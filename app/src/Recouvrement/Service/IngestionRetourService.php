<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Entity\RetourClient;
use App\Recouvrement\Entity\RetourPieceJointe;
use App\Recouvrement\Enum\RetourSource;
use App\Recouvrement\Repository\RetourClientRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ingere un email entrant (reponse d'un client a une relance) en RetourClient.
 *
 * Deux missions :
 *   1. RATTACHEMENT a la facture / au compte, par ordre de fiabilite :
 *        (a) token connu (header X-Recouvrement-Token ou Reply-To sous-adresse
 *            "local+token@domaine") -> RelanceEnvoi -> compte_code + ecriture_id ;
 *        (b) sinon, match de l'adresse expediteur sur recouvrement.v_impayes
 *            (un client n'a en general qu'un seul compte) -> compte_code.
 *   2. CATEGORISATION simple par mots-cles FR du corps (promesse de paiement /
 *      contestation / changement de coordonnees), sinon NON_CATEGORISE.
 *
 * Dedoublonnage strict par message_id (existsByMessageId) : un meme email relu
 * par IMAP n'est jamais ingere deux fois.
 */
final class IngestionRetourService
{
    public function __construct(
        private readonly RetourClientRepository $retourClientRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly RecouvrementRealtime $realtime,
        private readonly AutoReponseSpontaneService $autoReponse,
        private readonly string $adresseService = '',
    ) {
    }

    /**
     * Ingere un email parse. Retourne le RetourClient cree, ou null si le message
     * a deja ete ingere (meme message_id).
     *
     * @param array{
     *     messageId?: ?string,
     *     inReplyTo?: ?string,
     *     from?: ?string,
     *     to?: ?string,
     *     cc?: ?string,
     *     sujet?: ?string,
     *     corpsTexte?: ?string,
     *     corpsHtml?: ?string,
     *     headers?: array<string, string>,
     *     piecesJointes?: list<array{nom: string, typeMime: ?string, contenu: string}>,
     *     recuLe?: ?DateTimeImmutable
     * } $email
     */
    public function ingerer(array $email, RetourSource $source = RetourSource::IMAP): ?RetourClient
    {
        $recuLe = $email['recuLe'] ?? new DateTimeImmutable();
        $expediteur = self::extraireAdresse($email['from'] ?? null);
        $headers = $email['headers'] ?? [];

        // Cle de dedoublonnage : Message-ID normalise (chevrons retires) s'il existe,
        // sinon empreinte de repli. Certains webmails/repondeurs n'emettent pas de
        // Message-ID : sans repli, ils seraient reinseres a chaque passage (mode recent).
        $messageId = self::cleDedup($email, $recuLe, $expediteur);

        if ($this->retourClientRepository->existsByMessageId($messageId)) {
            $this->logger->info('Retour ignore : message_id deja ingere', ['message_id' => $messageId]);

            return null;
        }

        $retour = new RetourClient($source, $recuLe);
        $retour->setMessageId($messageId);
        $retour->setInReplyTo(self::nettoyer($email['inReplyTo'] ?? null));
        $retour->setExpediteur($expediteur);
        $retour->setSujet(self::nettoyer($email['sujet'] ?? null));
        $retour->setCorpsTexte(self::nettoyer($email['corpsTexte'] ?? null));
        $retour->setCorpsHtml(self::nettoyer($email['corpsHtml'] ?? null));
        // "Aussi adresse a" : les autres destinataires (To + Cc), hors notre propre
        // boite et hors l'expediteur lui-meme. Couvre le cas ou le client ajoute
        // quelqu'un en destinataire direct (To) et pas seulement en Cc.
        $retour->setCc($this->autresDestinataires(
            $email['to'] ?? null,
            $email['cc'] ?? null,
            [$this->adresseService, (string) $expediteur],
        ));

        // Rattachement, par ordre de fiabilite : (1) token, (2) reference dossier
        // (code compte place dans le sujet de la relance -> derniere relance du
        // compte ; survit a la reecriture du Message-ID par Mailjet), (3) adresse
        // expediteur rapprochee sur les impayes.
        $relance = $this->retrouverRelanceParToken($headers, $email)
            ?? $this->retrouverRelanceParRef($email);
        if (null !== $relance) {
            $retour->setRelanceEnvoi($relance);
            $retour->setCompteCode($relance->getCompteCode());
            $retour->setEcritureId($relance->getEcritureId());
        } elseif (null !== $expediteur) {
            $compteCode = $this->retrouverCompteParEmail($expediteur);
            if (null !== $compteCode) {
                $retour->setCompteCode($compteCode);
            }
        }

        // Pas de categorisation automatique : le classement par mots-cles etait
        // trop peu fiable (bancal). La categorie reste nulle (un classement manuel
        // par la comptable pourra etre ajoute plus tard).

        try {
            $this->retourClientRepository->save($retour);
        } catch (UniqueConstraintViolationException) {
            // Course entre deux runs IMAP concurrents : un autre process a inséré
            // ce message_id entre notre test existsByMessageId() et le save.
            // L'index unique partiel protège la base ; on traite ça comme un
            // simple doublon (idempotence garantie quel que soit l'appelant).
            $this->logger->info('Retour ignore : message_id insere en concurrence', ['message_id' => $messageId]);

            return null;
        }

        // Pieces jointes du mail (justificatifs...) : stockees en base, liees au retour.
        $this->enregistrerPiecesJointes($retour, $email['piecesJointes'] ?? []);

        $this->logger->info('Retour client ingere', [
            'message_id' => $messageId,
            'compte' => $retour->getCompteCode(),
            'relance' => null !== $relance ? $relance->getId() : null,
            'categorie' => $retour->getCategorie()?->value,
        ]);

        // Temps reel : nouveau retour -> badge nav + cloche (best-effort).
        $this->realtime->signalerNouveauRetour($retour);

        // Email SPONTANE (aucune relance rattachee : ni token ni reference) :
        // accuse automatique invitant a repondre directement a la relance. Une
        // reponse a une relance ($relance non nul) n'en declenche jamais. On n'auto-
        // repond pas non plus a une soumission du FORMULAIRE web (deja une reponse
        // volontaire du client, pas un email a accuser). Best-effort.
        if (null === $relance && RetourSource::FORMULAIRE !== $source) {
            $this->autoReponse->repondreSpontane($expediteur, $headers);
        }

        return $retour;
    }

    /**
     * Persiste les pieces jointes d'un retour (contenu en base). Un flush unique.
     *
     * @param list<array{nom: string, typeMime: ?string, contenu: string}> $pieces
     */
    private function enregistrerPiecesJointes(RetourClient $retour, array $pieces): void
    {
        if ([] === $pieces) {
            return;
        }

        $ajout = false;
        foreach ($pieces as $piece) {
            $contenu = $piece['contenu'];
            $nom = trim($piece['nom']);
            if ('' === $contenu || '' === $nom) {
                continue;
            }
            $this->entityManager->persist(new RetourPieceJointe(
                $retour,
                $nom,
                self::nettoyer($piece['typeMime'] ?? null),
                $contenu,
            ));
            $ajout = true;
        }

        if ($ajout) {
            $this->entityManager->flush();
        }
    }

    /**
     * "Autres destinataires" d'une reponse : adresses des champs To + Cc, hors
     * celles a exclure (notre boite de service, l'expediteur). Dedoublonne,
     * separe par des virgules. null si aucune. Couvre le CC ET le To (le client
     * peut ajouter quelqu'un en destinataire direct plutot qu'en copie).
     *
     * @param list<string> $exclure adresses a retirer (insensible a la casse)
     */
    private function autresDestinataires(?string $to, ?string $cc, array $exclure): ?string
    {
        $texte = trim(($to ?? '').' '.($cc ?? ''));
        if ('' === $texte) {
            return null;
        }

        preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $texte, $m);

        $exclureBas = [];
        foreach ($exclure as $e) {
            $e = strtolower(trim($e));
            if ('' !== $e) {
                $exclureBas[$e] = true;
            }
        }

        $adresses = [];
        foreach ($m[0] as $email) {
            $bas = strtolower($email);
            if (isset($exclureBas[$bas]) || isset($adresses[$bas])) {
                continue;
            }
            $adresses[$bas] = $email;
        }

        return [] !== $adresses ? implode(', ', array_values($adresses)) : null;
    }

    /**
     * Retrouve la relance par token, depuis le header X-Recouvrement-Token ou un
     * Reply-To sous-adresse ("local+token@domaine") present dans les en-tetes.
     *
     * @param array<string, string>      $headers
     * @param array{inReplyTo?: ?string} $email
     */
    private function retrouverRelanceParToken(array $headers, array $email): ?RelanceEnvoi
    {
        $token = $this->extraireToken($headers, $email);
        if (null === $token) {
            return null;
        }

        $repo = $this->entityManager->getRepository(RelanceEnvoi::class);

        return $repo->findOneBy(['token' => $token]);
    }

    /**
     * Rattachement par la reference dossier (code compte) que la relance place dans
     * son sujet, ex. "[ref. ACTUA67]". Robuste aux reponses (le sujet est conserve)
     * et independant du Message-ID (que Mailjet reecrit). Cherche dans le sujet puis
     * le corps, et renvoie la derniere relance de ce compte (null si rien d'exploitable).
     *
     * @param array{sujet?: ?string, corpsTexte?: ?string} $email
     */
    private function retrouverRelanceParRef(array $email): ?RelanceEnvoi
    {
        $compteCode = self::extraireRefCompte($email['sujet'] ?? null)
            ?? self::extraireRefCompte($email['corpsTexte'] ?? null);
        if (null === $compteCode) {
            return null;
        }

        // Le code est revalide ici : findOneBy ne renvoie une relance que si le code
        // correspond reellement a un compte relance (pas de faux positif).
        return $this->entityManager->getRepository(RelanceEnvoi::class)
            ->findOneBy(['compteCode' => $compteCode], ['id' => 'DESC']);
    }

    /**
     * Extrait le code compte d'une reference dossier "[ref. CODE]" (accent et casse
     * indifferents). Le code est de toute facon revalide par l'appelant.
     */
    private static function extraireRefCompte(?string $texte): ?string
    {
        if (null === $texte) {
            return null;
        }
        if (1 === preg_match('/\[r[ée]f\.?\s*([A-Za-z0-9._-]{2,64})\]/iu', $texte, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extrait un token de relance des en-tetes (X-Recouvrement-Token prioritaire,
     * puis sous-adressage du To/Delivered-To/Reply-To, puis In-Reply-To).
     *
     * @param array<string, string>      $headers
     * @param array{inReplyTo?: ?string} $email
     */
    private function extraireToken(array $headers, array $email): ?string
    {
        // En-tete dedie pose a l'envoi.
        $direct = $this->headerInsensible($headers, 'x-recouvrement-token');
        if (null !== $direct && '' !== trim($direct)) {
            return trim($direct);
        }

        // Sous-adressage "local+TOKEN@domaine" sur les destinataires probables du retour.
        foreach (['to', 'delivered-to', 'x-original-to', 'reply-to', 'cc'] as $champ) {
            $valeur = $this->headerInsensible($headers, $champ);
            if (null === $valeur) {
                continue;
            }
            $token = self::tokenDansSousAdressage($valeur);
            if (null !== $token) {
                return $token;
            }
        }

        // In-Reply-To : Message-ID custom "TOKEN@recouvrement.demonstration.invalid".
        $inReplyTo = self::nettoyer($email['inReplyTo'] ?? null) ?? $this->headerInsensible($headers, 'in-reply-to');
        if (null !== $inReplyTo) {
            $token = self::tokenDansMessageId($inReplyTo);
            if (null !== $token) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Retrouve le compte client par adresse email exacte dans recouvrement.v_impayes.
     * Renvoie null si aucun compte ou plusieurs comptes distincts (ambigu).
     */
    private function retrouverCompteParEmail(string $email): ?string
    {
        $emailNorm = mb_strtolower(trim($email));
        if ('' === $emailNorm) {
            return null;
        }

        /** @var list<array{compte: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT compte FROM recouvrement.v_impayes '
            .'WHERE compte IS NOT NULL AND lower(trim(email)) = :email LIMIT 2',
            ['email' => $emailNorm],
        );

        if (1 !== \count($rows)) {
            // Aucun match, ou ambigu (plusieurs comptes) : on ne devine pas.
            return null;
        }

        $compte = $rows[0]['compte'] ?? null;

        return null !== $compte && '' !== $compte ? $compte : null;
    }

    /**
     * Cherche "local+TOKEN@domaine" dans une liste d'adresses ; renvoie TOKEN.
     */
    private static function tokenDansSousAdressage(string $valeur): ?string
    {
        // Token = bin2hex(random_bytes(16)) = exactement 32 hex. On exige cette
        // longueur exacte (et non {16,}) pour ne pas capter une sous-adresse
        // legitime non liee ; le token est de toute facon revalide en base.
        if (preg_match('/\+([0-9a-f]{32})@/i', $valeur, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Extrait le token d'un Message-ID custom "TOKEN@relances.demonstration.invalid"
     * (ancien domaine "recouvrement." accepte aussi, pour les relances envoyees
     * avant l'alignement du Message-ID sur le domaine d'envoi). Token revalide en base.
     */
    private static function tokenDansMessageId(string $valeur): ?string
    {
        if (preg_match('/<?([0-9a-f]{32})@(?:relances|recouvrement)\./i', $valeur, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Lecture insensible a la casse d'un en-tete (les cles IMAP varient).
     *
     * @param array<string, string> $headers
     */
    private function headerInsensible(array $headers, string $nom): ?string
    {
        $cible = strtolower($nom);
        foreach ($headers as $cle => $valeur) {
            if (strtolower($cle) === $cible) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * Extrait l'adresse email d'un champ "From" ("Nom <a@b.fr>" ou "a@b.fr").
     */
    private static function extraireAdresse(?string $from): ?string
    {
        if (null === $from) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        $from = trim($from);

        return '' !== $from ? mb_strtolower($from) : null;
    }

    /**
     * Cle de dedoublonnage : Message-ID normalise (chevrons retires) s'il existe,
     * sinon empreinte deterministe (expediteur + date + sujet + debut du corps),
     * pour dedupliquer aussi les emails depourvus de Message-ID.
     *
     * @param array{messageId?: ?string, sujet?: ?string, corpsTexte?: ?string} $email
     */
    private static function cleDedup(array $email, DateTimeImmutable $recuLe, ?string $expediteur): string
    {
        $messageId = self::normaliserMessageId($email['messageId'] ?? null);
        if (null !== $messageId) {
            return $messageId;
        }

        $base = ($expediteur ?? '')
            .'|'.$recuLe->format('c')
            .'|'.trim((string) ($email['sujet'] ?? ''))
            .'|'.mb_substr(trim((string) ($email['corpsTexte'] ?? '')), 0, 2000);

        return 'sha256:'.hash('sha256', $base);
    }

    /**
     * Normalise un Message-ID : trim + retrait d'un unique couple de chevrons
     * englobants ("<id@x>" -> "id@x"), pour que POP3 (sans chevrons) et IMAP (avec
     * chevrons) convergent. Renvoie null si vide.
     */
    private static function normaliserMessageId(?string $valeur): ?string
    {
        $valeur = self::nettoyer($valeur);
        if (null === $valeur) {
            return null;
        }
        if (str_starts_with($valeur, '<') && str_ends_with($valeur, '>')) {
            $valeur = trim(substr($valeur, 1, -1));
        }

        return '' === $valeur ? null : $valeur;
    }

    private static function nettoyer(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = trim($valeur);

        return '' === $texte ? null : $texte;
    }
}
