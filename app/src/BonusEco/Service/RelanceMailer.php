<?php

declare(strict_types=1);

namespace App\BonusEco\Service;

use App\Shared\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Envoie une relance email (vendeur OU secretaire) et trace l'envoi dans
 * bonus_eco.relance pour la traçabilite RGPD + audit.
 *
 * Logique destinataire :
 *  - si email Progiciel renseigne -> on l'utilise (1 destinataire)
 *  - sinon, on construit deux variantes de fallback a partir du nom/prenom :
 *    `prenomnom@demonstration.invalid` ET `nomprenom@demonstration.invalid`
 *    (on ne sait pas laquelle des 2 conventions est utilisee ici)
 *  - chaque destinataire = 1 ligne dans le log
 *
 * Echec d'envoi : on log quand meme la tentative avec le message d'erreur,
 * pour que l'historique reflete les essais (utile pour debug SMTP).
 */
final class RelanceMailer
{
    private const DOMAINE_INTERNE = 'demonstration.invalid';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    /**
     * Envoie une relance ciblee (vendeur ou secretaire). Le destinataire est
     * soit l'email Progiciel renseigne, soit les 2 variantes construites depuis
     * le nom/prenom (envoi multi-destinataire).
     *
     * @param array<string, mixed>      $ecriture ligne Progiciel (bal_agee_bonuseco)
     * @param array<string, mixed>|null $asp      dossier ASP matche s'il existe
     * @param 'vendeur'|'secretaire'    $type
     *
     * @return list<string> liste des emails utilises (pour feedback UI)
     */
    public function envoyer(array $ecriture, ?array $asp, string $type, User $auteur): array
    {
        [$nomComplet, $emails] = $this->resoudreDestinataires($ecriture, $type);

        if ([] === $emails) {
            throw new RuntimeException(sprintf('Impossible de relancer le/la %s : ni email ni nom dans Progiciel.', $type));
        }

        $sujet = $this->construireSujet($ecriture);

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->subject($sujet)
            ->htmlTemplate('bonus_eco/emails/relance.html.twig')
            ->context([
                'ecriture' => $ecriture,
                'asp' => $asp,
                'auteur' => $auteur,
                'type' => $type,
                'destinataire_nom' => $nomComplet,
            ]);

        foreach ($emails as $addr) {
            $email->addTo(new Address($addr, '' !== $nomComplet ? $nomComplet : $addr));
        }

        $erreur = null;
        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $erreur = $e->getMessage();
            $this->logger->error('Echec envoi relance bonus eco', [
                'ecriture' => $ecriture['numero'] ?? null,
                'type' => $type,
                'emails' => $emails,
                'erreur' => $erreur,
            ]);
        }

        $html = $email->getHtmlBody();
        $corps = is_string($html) ? $html : '';

        // 1 ligne de log par destinataire (traçabilite granulaire RGPD).
        foreach ($emails as $addr) {
            $this->logRelance($ecriture, $type, $addr, $nomComplet, $sujet, $corps, $auteur, $erreur);
        }

        if (null !== $erreur) {
            throw new RuntimeException('Envoi échoué : '.$erreur);
        }

        return $emails;
    }

    /**
     * Resout les destinataires pour un type donne.
     *
     * @param array<string, mixed>   $ecriture
     * @param 'vendeur'|'secretaire' $type
     *
     * @return array{0: string, 1: list<string>} [nomComplet, listeEmails]
     */
    private function resoudreDestinataires(array $ecriture, string $type): array
    {
        if ('vendeur' === $type) {
            $prenom = trim((string) ($ecriture['prenomvendeur'] ?? ''));
            $nom = trim((string) ($ecriture['nomvendeur'] ?? ''));
            $emailSage = trim((string) ($ecriture['emailvendeur'] ?? ''));
        } else {
            // La table Progiciel n'a pas de prenom secretaire separe : on tente d'extraire
            // depuis nomsecretaire qui peut etre "PRENOM NOM" ou "NOM Prenom".
            [$prenom, $nom] = self::splitNomComplet(trim((string) ($ecriture['nomsecretaire'] ?? '')));
            $emailSage = trim((string) ($ecriture['emailsecr'] ?? ''));
        }

        $nomComplet = trim($prenom.' '.$nom);

        if ('' !== $emailSage) {
            return [$nomComplet, [$emailSage]];
        }

        // Pas d'email Progiciel -> on construit les 2 variantes de fallback.
        $variantes = [];
        $v1 = self::construireEmailInterne($prenom.$nom);
        $v2 = self::construireEmailInterne($nom.$prenom);
        if (null !== $v1) {
            $variantes[$v1] = true;
        }
        if (null !== $v2) {
            $variantes[$v2] = true;
        }

        return [$nomComplet, array_keys($variantes)];
    }

    /**
     * Heuristique de split d'un nom complet "PRENOM NOM" ou "NOM Prenom".
     * Convention Synthauto inconnue : on prend simple = premier mot = prenom.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitNomComplet(string $nomComplet): array
    {
        $parts = preg_split('/\s+/', $nomComplet, 2);
        if (false === $parts || \count($parts) < 2) {
            return ['', $nomComplet];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Normalise une chaine "Prénom Nom" en partie locale d'email :
     * minuscules, sans accent, sans espace ni caractere special.
     */
    private static function construireEmailInterne(string $brut): ?string
    {
        $local = strtolower(self::sansAccent($brut));
        $local = (string) preg_replace('/[^a-z0-9]/', '', $local);

        if ('' === $local) {
            return null;
        }

        return $local.'@'.self::DOMAINE_INTERNE;
    }

    private static function sansAccent(string $v): string
    {
        $r = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);

        return false === $r ? $v : $r;
    }

    /**
     * @param array<string, mixed> $ecriture
     */
    private function construireSujet(array $ecriture): string
    {
        $vin = trim((string) ($ecriture['numvin'] ?? ''));
        $immat = trim((string) ($ecriture['numimmat'] ?? ''));
        $piece = trim((string) ($ecriture['numpiece'] ?? ''));

        $ident = '' !== $immat ? $immat : ($vin ?: $piece);

        return sprintf('Relance dossier bonus écologique - %s', $ident ?: 'véhicule');
    }

    /**
     * @param array<string, mixed> $ecriture
     */
    private function logRelance(
        array $ecriture,
        string $type,
        string $email,
        string $nom,
        string $sujet,
        string $corps,
        User $auteur,
        ?string $erreur,
    ): void {
        $this->connection->insert('bonus_eco.relance', [
            'numero_ecriture_sage' => (int) ($ecriture['numero'] ?? 0),
            'num_chassis' => trim((string) ($ecriture['numvin'] ?? '')) ?: null,
            'destinataire_type' => $type,
            'destinataire_email' => $email,
            'destinataire_nom' => '' === $nom ? null : $nom,
            'auteur_id' => $auteur->getId(),
            'sujet' => $sujet,
            'corps' => $corps,
            'erreur' => $erreur,
        ]);
    }

    /**
     * Envoi en lot : 1 email par destinataire avec la liste de SES dossiers.
     * Utilise le meme service que la relance unitaire (fallback emails inclus).
     *
     * @param list<array{prenom: string, nom: string, email_sage: string, ecritures: list<array<string, mixed>>}> $groupes
     * @param 'vendeur'|'secretaire'                                                                              $type
     *
     * @return array{envoyes: int, echecs: int, ignores: int}
     */
    public function envoyerLot(array $groupes, string $type, User $auteur): array
    {
        $stats = ['envoyes' => 0, 'echecs' => 0, 'ignores' => 0];

        foreach ($groupes as $grp) {
            $emails = $this->resoudreEmailsLot($grp);
            if ([] === $emails) {
                ++$stats['ignores'];
                continue;
            }

            $nomComplet = trim($grp['prenom'].' '.$grp['nom']);
            $sujet = sprintf(
                'Relance dossiers bonus écologique en correction (%s)',
                self::pluriel(\count($grp['ecritures']), 'dossier', 'dossiers'),
            );

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->subject($sujet)
                ->htmlTemplate('bonus_eco/emails/relance_lot.html.twig')
                ->context([
                    'ecritures' => $grp['ecritures'],
                    'auteur' => $auteur,
                    'type' => $type,
                    'destinataire_nom' => $nomComplet,
                ]);
            foreach ($emails as $addr) {
                $email->addTo(new Address($addr, '' !== $nomComplet ? $nomComplet : $addr));
            }

            $erreur = null;
            try {
                $this->mailer->send($email);
                ++$stats['envoyes'];
            } catch (Throwable $e) {
                $erreur = $e->getMessage();
                ++$stats['echecs'];
                $this->logger->error('Echec envoi relance lot bonus eco', [
                    'type' => $type,
                    'destinataire' => $nomComplet,
                    'erreur' => $erreur,
                ]);
            }

            $html = $email->getHtmlBody();
            $corps = is_string($html) ? $html : '';

            // 1 ligne de log par couple (ecriture, destinataire) pour retrouver
            // toute l'historique d'une ecriture donnee dans le detail.
            foreach ($grp['ecritures'] as $ec) {
                foreach ($emails as $addr) {
                    $this->logRelance($ec, $type, $addr, $nomComplet, $sujet, $corps, $auteur, $erreur);
                }
            }
        }

        return $stats;
    }

    /**
     * Resolution des emails pour un groupe : email Progiciel si dispo, sinon 2
     * variantes construites (prenomnom + nomprenom @demonstration.invalid).
     *
     * @param array{prenom: string, nom: string, email_sage: string, ecritures: list<array<string, mixed>>} $grp
     *
     * @return list<string>
     */
    private function resoudreEmailsLot(array $grp): array
    {
        $emailSage = trim($grp['email_sage']);
        if ('' !== $emailSage) {
            return [$emailSage];
        }

        $prenom = trim($grp['prenom']);
        $nom = trim($grp['nom']);

        // Pour la secretaire on n'a souvent que nomsecretaire = "PRENOM NOM"
        // ou "NOM Prenom" : on tente un split, et si echec on construit juste
        // sur le champ brut.
        if ('' === $prenom && '' !== $nom) {
            [$prenom, $nom] = self::splitNomComplet($nom);
        }

        $variantes = [];
        $v1 = self::construireEmailInterne($prenom.$nom);
        $v2 = self::construireEmailInterne($nom.$prenom);
        if (null !== $v1) {
            $variantes[$v1] = true;
        }
        if (null !== $v2) {
            $variantes[$v2] = true;
        }

        return array_keys($variantes);
    }

    private static function pluriel(int $n, string $sing, string $pl): string
    {
        return $n.' '.($n > 1 ? $pl : $sing);
    }

    /**
     * Historique des relances pour une ecriture donnee.
     *
     * @return list<array<string, mixed>>
     */
    public function historique(int $numeroEcriture): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.destinataire_type, r.destinataire_email, r.destinataire_nom, '
            .'r.envoye_le, r.erreur, u.last_name AS auteur_nom, u.first_name AS auteur_prenom '
            .'FROM bonus_eco.relance r '
            .'JOIN shared.users u ON u.id = r.auteur_id '
            .'WHERE r.numero_ecriture_sage = ? '
            .'ORDER BY r.envoye_le DESC',
            [$numeroEcriture],
        );

        return $rows;
    }
}
