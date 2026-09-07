<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Repository\FacturePdfRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Twig\Environment;

/**
 * Prepare et envoie une relance email GROUPEE PAR COMPTE (un seul mail listant
 * toutes les factures echues impayees du client, facon releve), puis trace
 * l'envoi dans recouvrement.relance_envoi.
 *
 * Etapes pour un groupe "a relancer" (cf. SelectionRelanceService) :
 *   1. re-verifie l'idempotence (compte_code, niveau) juste avant l'envoi ;
 *   2. genere un token unique (lien retour <-> relance) ;
 *   3. rend le template Twig du niveau (relance_1 / relance_2 / mise_en_demeure)
 *      avec la variable `groupe` ;
 *   4. recupere et attache le PDF de CHAQUE facture du compte (PdfFactureProvider) ;
 *   5. construit l'Email (From, Reply-To en sous-adressage du token, header
 *      X-Recouvrement-Token, Message-Id custom) et l'envoie ;
 *   6. persiste un RelanceEnvoi ENVOYE (ou ECHEC + message si exception).
 *
 * En dev/test, le mailer force tout le courrier vers l'adresse interne (cf.
 * config/packages/mailer.yaml) : aucun vrai client n'est touche.
 *
 * @phpstan-import-type GroupeARelancer from SelectionRelanceService
 */
final class EnvoiRelanceService
{
    private const TEMPLATE_RELANCE_1 = 'recouvrement/email/relance_1.html.twig';
    private const TEMPLATE_RELANCE_2 = 'recouvrement/email/relance_2.html.twig';
    private const TEMPLATE_MISE_EN_DEMEURE = 'recouvrement/email/mise_en_demeure.html.twig';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly Environment $twig,
        private readonly PdfFactureProvider $pdfFactureProvider,
        private readonly FacturePdfRepository $facturePdfs,
        private readonly FusionPdfService $fusionPdfService,
        private readonly HtmlPdfConverter $htmlPdf,
        private readonly LogoRecouvrement $logo,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $replyTo,
        private readonly int $quotaPdfOctets,
    ) {
    }

    /**
     * Cree (sans envoyer) une relance en statut A_ENVOYER et la persiste.
     *
     * Etape "preparation" du flux asynchrone : la commande cree l'entite ici,
     * puis dispatch un message EnvoyerRelance portant son id. Le RelanceEnvoi
     * est ainsi trace en base AVANT toute tentative d'envoi. Un garde-fou
     * (dejaPrepareOuEnvoye) evite de re-preparer un palier deja pris en charge ;
     * la garantie stricte anti-double-envoi reste cote worker (envoyerPrepare).
     *
     * @param GroupeARelancer $groupe
     */
    public function preparer(array $groupe): RelanceEnvoi
    {
        $ecritureId = $groupe['ecriture_id'] ?? null;

        // Garde-fou anti-doublon a la preparation : ne pas recreer une relance deja
        // prise en charge (a_envoyer ou envoye). Cle = la FACTURE si relance ciblee
        // (ecriture_id renseigne), sinon le COMPTE (relance releve). Meme distinction
        // que l'index unique partiel en base. ECHEC/ANNULE restent re-preparables.
        if (null !== $ecritureId) {
            if ($this->dejaPrepareOuEnvoyeFacture($ecritureId, $groupe['niveau'])) {
                throw new RuntimeException(sprintf('Facture %s deja relancee au niveau %d.', $ecritureId, $groupe['niveau']));
            }
        } elseif ($this->dejaPrepareOuEnvoye($groupe['compte_code'], $groupe['niveau'])) {
            throw new RuntimeException(sprintf('Relance deja preparee ou envoyee pour le compte %s au niveau %d.', $groupe['compte_code'], $groupe['niveau']));
        }

        $token = bin2hex(random_bytes(16));

        $relance = new RelanceEnvoi(
            $groupe['compte_code'],
            $groupe['niveau'],
            $groupe['vecteur'],
            RelanceStatut::A_ENVOYER,
            $token,
        );
        $relance->setRegleId($groupe['regle_id']);
        $relance->setRegleNom($groupe['regle_nom']);
        $relance->setMiseEnDemeure($groupe['mise_en_demeure']);
        $relance->setSeuilMed($groupe['seuil_med']);
        $relance->setEcritureId($ecritureId);
        $relance->setReferenceFacture($groupe['reference_facture'] ?? null);
        $relance->setNbFactures($groupe['nb_factures']);
        $relance->setMontantSolde($groupe['total']);
        $relance->setDestinataire(self::nullableTrim($groupe['email'] ?? null));
        $relance->setSujet($this->construireSujet($groupe));
        $relance->setPrepareLe(new DateTimeImmutable());

        $this->entityManager->persist($relance);
        $this->entityManager->flush();

        return $relance;
    }

    /**
     * Envoie une relance deja preparee (statut A_ENVOYER) et met a jour son
     * statut (ENVOYE / ECHEC). Point d'entree du worker.
     *
     * Garde-fous :
     *   - une relance deja ENVOYE est ignoree (rejouabilite des messages) ;
     *   - un compte sans facture encore eligible est marque ANNULE ;
     *   - une relance sans destinataire exploitable est marquee ECHEC.
     *
     * @param GroupeARelancer $groupe donnees de rendu figees a la preparation
     */
    public function envoyerPrepare(RelanceEnvoi $relance, array $groupe): void
    {
        if (RelanceStatut::ENVOYE === $relance->getStatut()) {
            // Message rejoue apres un envoi reussi : ne pas renvoyer.
            return;
        }

        // Garde-fou anti-double-envoi : ce palier a-t-il deja ete envoye avec succes ?
        // Cle = la FACTURE si relance ciblee (ecriture_id), sinon le COMPTE (releve).
        // L'index unique partiel ne bloque qu'au flush (apres l'envoi reel du mail) :
        // on verifie donc ici, AVANT d'envoyer, pour ne jamais expedier deux fois.
        $ecritureId = $relance->getEcritureId();
        $dejaEnvoye = null !== $ecritureId
            ? $this->dejaEnvoyeFacture($ecritureId, $relance->getNiveau())
            : $this->dejaEnvoye($relance->getCompteCode(), $relance->getNiveau());
        if ($dejaEnvoye) {
            $relance->setStatut(RelanceStatut::ANNULE);
            $relance->setErreurMessage('Palier deja envoye par une autre relance : doublon annule.');
            $this->entityManager->flush();
            $this->logger->info('Relance annulee : palier deja envoye (doublon)', [
                'compte_code' => $relance->getCompteCode(),
                'ecriture_id' => $ecritureId,
                'niveau' => $relance->getNiveau(),
            ]);

            return;
        }

        // Re-verification critique au moment reel de l'envoi : la cible est-elle
        // TOUJOURS eligible (facture ou compte encore echu/impaye) ? Entre la
        // preparation et l'envoi (latence, backlog, retries), le client a pu regler.
        // On ne veut surtout pas relancer (voire mettre en demeure) sur du solde.
        $encoreEligible = null !== $ecritureId
            ? $this->encoreEligibleFacture($ecritureId)
            : $this->encoreEligibleCompte($relance->getCompteCode());
        if (!$encoreEligible) {
            $relance->setStatut(RelanceStatut::ANNULE);
            $relance->setErreurMessage('Compte solde ou non eligible au moment de l\'envoi : relance annulee.');
            $this->entityManager->flush();
            $this->logger->info('Relance annulee : compte plus eligible', [
                'compte_code' => $relance->getCompteCode(),
                'niveau' => $relance->getNiveau(),
            ]);

            return;
        }

        $email = self::nullableTrim($relance->getDestinataire() ?? ($groupe['email'] ?? null));
        if (null === $email) {
            $relance->setStatut(RelanceStatut::ECHEC);
            $relance->setErreurMessage('Aucune adresse email exploitable.');
            $this->entityManager->flush();

            return;
        }

        $token = $relance->getToken();

        try {
            // Pieces jointes + strategie de debordement, calculees AVANT le corps :
            // quand le document complet (releve + factures) ne tient pas dans l'e-mail,
            // on ne joint que le releve recap (leger) et on ajoute un lien de
            // telechargement vers le document complet (stocke en base, servi par le web).
            $piece = $this->preparerPieceJointe($groupe);
            $lienTelechargement = null;
            if ($piece->deborde && null !== $piece->documentComplet) {
                $relance->setCourrierPdf($piece->documentComplet);
                $lienTelechargement = $this->urlGenerator->generate(
                    'app_recouvrement_telecharger',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            }

            // Corps e-mail : logo en inline CID (embarque dans construireMessage) ; pas
            // de mentions legales (elles n'apparaissent que sur le PDF joint).
            $corpsHtml = $this->rendreCorps($groupe, $token, $this->logo->srcCid(), $lienTelechargement, [] !== $piece->pieces);
            $relance->setCorpsHtml($corpsHtml);

            $sujet = $relance->getSujet() ?? $this->construireSujet($groupe);
            $message = $this->construireMessage($groupe, $email, $sujet, $corpsHtml, $token, $piece);
            $this->mailer->send($message);

            $relance->setStatut(RelanceStatut::ENVOYE);
            $relance->setEnvoyeLe(new DateTimeImmutable());
        } catch (Throwable $e) {
            $relance->setStatut(RelanceStatut::ECHEC);
            $relance->setErreurMessage($e->getMessage());
            $this->logger->error('Echec envoi relance recouvrement', [
                'compte_code' => $relance->getCompteCode(),
                'niveau' => $relance->getNiveau(),
                'erreur' => $e->getMessage(),
            ]);
        }

        $this->entityManager->flush();
    }

    /**
     * Construit le message Mime complet (en-tetes de tracabilite + PDF de chaque
     * facture du compte).
     *
     * @param GroupeARelancer $groupe
     */
    private function construireMessage(array $groupe, string $email, string $sujet, string $corpsHtml, string $token, PieceJointeRelance $piece): Email
    {
        $nom = $groupe['destinataire_nom'];

        $message = (new Email())
            ->from(new Address($this->fromEmail, 'Service recouvrement'))
            ->to(new Address($email, '' !== $nom ? $nom : $email))
            ->replyTo(new Address($this->replyTo))
            ->subject($sujet)
            ->html($corpsHtml);

        // Transport dedie : les relances partent par Mailjet (transport "relances"),
        // les autres emails de l'app restent sur le transport par defaut (Gmail).
        $headers = $message->getHeaders();
        $headers->addTextHeader('X-Transport', 'relances');

        // En-tetes de tracabilite : relier les reponses entrantes a la relance.
        // Le Message-ID (sur le domaine d'envoi) permet le matching via In-Reply-To.
        $headers->addTextHeader('X-Recouvrement-Token', $token);
        $headers->addIdHeader('Message-ID', $token.'@relances.demonstration.invalid');

        // Logo SYNTHAUTO embarque en inline (CID) : Gmail affiche les images CID mais ignore
        // les data-URI. Le corps de l'e-mail reference src="cid:logosynth".
        if (is_file($this->logo->chemin())) {
            $message->embedFromPath($this->logo->chemin(), LogoRecouvrement::CID, 'image/png');
        }

        // Pieces jointes deja preparees (releve + factures fusionnes, ou releve recap
        // seul en cas de debordement, ou aucune si "releve seul"/pas de facture) :
        // cf. preparerPieceJointe().
        foreach ($piece->pieces as $pj) {
            $message->attach($pj['contenu'], $pj['nom'], 'application/pdf');
        }

        return $message;
    }

    /**
     * Rend le corps HTML de la relance. Le template suit le FLAG mise en demeure
     * (pas le numéro de niveau : la cadence est configurable, la MED peut tomber au
     * niveau 5). Sinon : 1re relance (niveau 1) ou relance ferme (niveaux suivants).
     *
     * @param GroupeARelancer $groupe
     */
    private function rendreCorps(array $groupe, string $token, ?string $logoSrc = null, ?string $lienTelechargement = null, bool $pieceJointe = false): string
    {
        $template = $groupe['mise_en_demeure']
            ? self::TEMPLATE_MISE_EN_DEMEURE
            : (1 === $groupe['niveau'] ? self::TEMPLATE_RELANCE_1 : self::TEMPLATE_RELANCE_2);

        return $this->twig->render($template, [
            'groupe' => $groupe,
            'token' => $token,
            'logo_src' => $logoSrc,
            'lien_telechargement' => $lienTelechargement,
            'piece_jointe' => $pieceJointe,
        ]);
    }

    /**
     * Sujet de l'email selon le niveau (mise en demeure explicite au niveau 3),
     * complete par le nom du destinataire (raison sociale) ou le code compte.
     *
     * @param GroupeARelancer $groupe
     */
    private function construireSujet(array $groupe): string
    {
        $nom = trim($groupe['destinataire_nom']);
        $reference = '' !== $nom ? $nom : $groupe['compte_code'];

        $base = $groupe['mise_en_demeure']
            ? sprintf('Mise en demeure de payer - %s', $reference)
            : sprintf('Vos factures impayees - %s', $reference);

        // Reference dossier (code compte) : conservee dans le sujet des reponses,
        // elle permet le rattachement meme quand Mailjet reecrit le Message-ID.
        return sprintf('%s [ref. %s]', $base, $groupe['compte_code']);
    }

    /**
     * Prepare la ou les pieces jointes PDF de la relance et decide de la strategie
     * de debordement (appelee AVANT le rendu du corps, qui affiche le lien de
     * telechargement le cas echeant).
     *
     * "releve seul" (ex. clients au prelevement) ou aucune facture recuperable :
     * aucune piece (le corps fait office de releve). Sinon on fusionne le releve
     * (page de garde) + les factures :
     *   1. fusion OK et sous le quota            -> on joint le PDF complet fusionne ;
     *   2. fusion indisponible (gs absent) mais volume brut sous le quota
     *                                            -> releve + factures attaches separement ;
     *   3. trop volumineux (DEBORDEMENT)         -> on ne joint que le releve recap
     *      (leger) et on expose le document complet fusionne via un lien de
     *      telechargement (stocke en base par l'appelant, servi par le web).
     *
     * @param GroupeARelancer $groupe
     */
    private function preparerPieceJointe(array $groupe): PieceJointeRelance
    {
        if ($groupe['releve_seul']) {
            return PieceJointeRelance::aucune();
        }

        $pdfsFactures = [];
        foreach ($groupe['factures'] as $facture) {
            $reference = (string) ($facture['reference'] ?? $facture['numpiece'] ?? '');
            $pdf = $this->pdfFactureProvider->recuperer($reference, $groupe['compte_code'], $facture['chemin_pdf'] ?? null);
            if (null === $pdf) {
                // Pas de PDF Progiciel : on tente le PDF televerse manuellement (page
                // "factures sans PDF"), rattache par ecriture_id.
                $ecritureId = self::nullableTrim((string) ($facture['ecriture_id'] ?? ''));
                if (null !== $ecritureId) {
                    $pdf = $this->facturePdfs->findParEcriture($ecritureId)?->getContenu();
                }
            }
            if (null !== $pdf) {
                $pdfsFactures[] = $pdf;
            }
        }

        // Aucun PDF de facture recuperable : le corps de l'e-mail liste deja tout.
        if ([] === $pdfsFactures) {
            return PieceJointeRelance::aucune();
        }

        // Page de garde = un vrai RELEVE (document formel : en-tete, destinataire,
        // objet, tableau, pied legal), distinct du corps. Logo en data-URI (dompdf ne
        // resout pas le CID). Place en tete des factures.
        $relevePdf = $this->htmlPdf->enPdf($this->twig->render('recouvrement/pdf/releve.html.twig', [
            'groupe' => $groupe,
            'logo_src' => $this->logo->dataUri(),
        ]));

        $nomReleve = $this->nomFichierReleve($groupe['compte_code']);
        $complet = $this->fusionPdfService->fusionner(array_merge([$relevePdf], $pdfsFactures));

        // 1. Fusion OK et sous le quota : on joint le document complet.
        if (null !== $complet && \strlen($complet) <= $this->quotaPdfOctets) {
            return PieceJointeRelance::attachee([['nom' => $nomReleve, 'contenu' => $complet]]);
        }

        // 2. Fusion indisponible (Ghostscript absent, ex. poste de dev) mais volume brut
        //    sous le quota : releve + factures attaches separement.
        if (null === $complet) {
            $tous = array_merge([$relevePdf], $pdfsFactures);
            $total = array_sum(array_map('strlen', $tous));
            if ($total <= $this->quotaPdfOctets) {
                $pieces = [];
                foreach ($tous as $index => $pdf) {
                    $nom = 0 === $index ? $nomReleve : sprintf('facture-%03d.pdf', $index);
                    $pieces[] = ['nom' => $nom, 'contenu' => $pdf];
                }

                return PieceJointeRelance::attachee($pieces);
            }
        }

        // 3. DEBORDEMENT : trop volumineux pour l'e-mail. On joint le releve recap seul
        //    (compresse s'il le faut) et on expose le document complet via lien de
        //    telechargement (si la fusion a abouti ; sinon rien a servir).
        $releveCompresse = $this->fusionPdfService->fusionner([$relevePdf]) ?? $relevePdf;

        $this->logger->warning('Recouvrement : document complet trop volumineux, releve recap joint + lien de telechargement', [
            'compte_code' => $groupe['compte_code'],
            'nb_factures' => \count($pdfsFactures),
            'taille_complet' => null !== $complet ? \strlen($complet) : null,
            'quota_octets' => $this->quotaPdfOctets,
            'lien_disponible' => null !== $complet,
        ]);

        return new PieceJointeRelance(
            [['nom' => $nomReleve, 'contenu' => $releveCompresse]],
            true,
            $complet,
        );
    }

    private function nomFichierReleve(string $compteCode): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($compteCode));
        $base = trim($base, '-');

        return 'Releve-'.('' !== $base ? $base : 'compte').'.pdf';
    }

    /**
     * Le compte a-t-il toujours au moins une facture dans le perimetre a relancer
     * (presente dans v_impayes, solde debiteur, echue) ? Si tout a ete regle /
     * lettre entre la preparation et l'envoi, le compte disparait de la vue.
     */
    private function encoreEligibleCompte(string $compteCode): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.v_impayes '
            .'WHERE compte = :compte AND montant_solde > 0 AND jours_retard > 0',
            ['compte' => $compteCode],
        );

        return $count > 0;
    }

    /**
     * Verifie en base si (compte_code, niveau) a deja ete envoye avec succes.
     */
    public function dejaEnvoye(string $compteCode, int $niveau): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.relance_envoi '
            // ecriture_id IS NULL : on ne compte QUE les relevés (comme l'index unique
            // partiel), pas les relances ciblées facture qui ont leur propre clé.
            ."WHERE compte_code = :compte AND niveau = :niveau AND ecriture_id IS NULL AND statut = 'envoye' AND cycle_clos = false",
            ['compte' => $compteCode, 'niveau' => $niveau],
        );

        return $count > 0;
    }

    /**
     * (compte_code, niveau) est-il deja pris en charge (prepare OU envoye) ? Sert
     * de garde-fou anti-doublon a la PREPARATION. Contrairement a dejaEnvoye(), on
     * inclut les 'a_envoyer' encore en file : l'index unique partiel ne les protege
     * pas. Un ECHEC/ANNULE n'est PAS compte (re-preparation legitime).
     */
    private function dejaPrepareOuEnvoye(string $compteCode, int $niveau): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.relance_envoi '
            .'WHERE compte_code = :compte AND niveau = :niveau AND ecriture_id IS NULL AND statut IN (:prepare, :envoye) AND cycle_clos = false',
            [
                'compte' => $compteCode,
                'niveau' => $niveau,
                'prepare' => RelanceStatut::A_ENVOYER->value,
                'envoye' => RelanceStatut::ENVOYE->value,
            ],
        );

        return $count > 0;
    }

    /**
     * (ecriture_id, niveau) a-t-il deja ete envoye ? Idempotence des relances
     * CIBLEES sur une facture (equivalent de dejaEnvoye() cote compte).
     */
    public function dejaEnvoyeFacture(string $ecritureId, int $niveau): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.relance_envoi '
            ."WHERE ecriture_id = :ecriture AND niveau = :niveau AND statut = 'envoye' AND cycle_clos = false",
            ['ecriture' => $ecritureId, 'niveau' => $niveau],
        );

        return $count > 0;
    }

    /**
     * (ecriture_id, niveau) est-il deja pris en charge (prepare OU envoye) ?
     * Garde-fou anti-doublon a la preparation des relances ciblees sur facture.
     */
    private function dejaPrepareOuEnvoyeFacture(string $ecritureId, int $niveau): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.relance_envoi '
            .'WHERE ecriture_id = :ecriture AND niveau = :niveau AND statut IN (:prepare, :envoye) AND cycle_clos = false',
            [
                'ecriture' => $ecritureId,
                'niveau' => $niveau,
                'prepare' => RelanceStatut::A_ENVOYER->value,
                'envoye' => RelanceStatut::ENVOYE->value,
            ],
        );

        return $count > 0;
    }

    /**
     * La facture est-elle toujours echue et impayee (dans v_impayes) au moment de
     * l'envoi ? Evite de relancer une facture reglee entre preparation et envoi.
     */
    private function encoreEligibleFacture(string $ecritureId): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.v_impayes '
            .'WHERE ecriture_id = :ecriture AND montant_solde > 0 AND jours_retard > 0',
            ['ecriture' => $ecritureId],
        );

        return $count > 0;
    }

    /**
     * Normalise une valeur en chaine non vide ou null (trim + chaine vide -> null).
     */
    private static function nullableTrim(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = trim($valeur);

        return '' === $texte ? null : $texte;
    }
}
