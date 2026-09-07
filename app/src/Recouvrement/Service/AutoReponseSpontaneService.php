<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;

/**
 * Accuse automatique aux emails SPONTANES : un client (ou n'importe qui) ecrit a
 * la boite de recouvrement SANS repondre a une relance (aucun token, aucune
 * reference dans le sujet). On lui renvoie un accuse qui l'invite a repondre
 * directement a la relance, pour que ses prochains messages se rattachent seuls.
 *
 * Declenche par IngestionRetourService uniquement quand le mail entrant n'est
 * rattache a AUCUNE relance. Garde-fous stricts (anti-boucle, anti-spam) :
 *   - expediteur valide, externe, non automatique (pas de mailer-daemon/no-reply) ;
 *   - on ignore les mails en masse / auto (Auto-Submitted, Precedence: bulk, listes) ;
 *   - UNE seule reponse par expediteur et par fenetre (table auto_reponse_spontanee) ;
 *   - notre reponse est taguee Auto-Submitted: auto-replied (les serveurs d'en face
 *     ne re-repondent pas).
 *
 * Best-effort : un echec n'interrompt jamais l'ingestion. Le FORCE_TO (mailer.yaml)
 * s'applique comme pour les relances (tout part vers la boite interne en pre-prod).
 */
final class AutoReponseSpontaneService
{
    /** Une seule auto-reponse par expediteur sur cette fenetre (jours) : anti-spam et
     *  anti-boucle. Fenetre courte (1 jour) : un client peut donc etre re-accuse le
     *  lendemain, mais jamais plusieurs fois le meme jour. */
    private const FENETRE_JOURS = 1;

    /** Parties locales d'adresses non humaines : jamais d'auto-reponse. */
    private const LOCALES_AUTOMATIQUES = [
        'mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'no_reply',
        'donotreply', 'do-not-reply', 'ne-pas-repondre', 'nepasrepondre',
        'bounce', 'bounces', 'abuse',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly LogoRecouvrement $logo,
        private readonly string $fromEmail,
        private readonly string $replyTo,
    ) {
    }

    /**
     * Envoie l'accuse automatique si l'expediteur est eligible et n'a pas deja
     * ete servi recemment. Best-effort.
     *
     * @param array<string, string> $headers en-tetes du mail entrant (cles minuscules)
     */
    public function repondreSpontane(?string $expediteur, array $headers): void
    {
        try {
            $adresse = self::adresseValide($expediteur);
            if (null === $adresse) {
                return;
            }
            if (self::estAutomatique($adresse, $this->fromEmail, $this->replyTo)) {
                return;
            }
            if (self::estEnMasseOuAuto($headers)) {
                return;
            }
            if ($this->dejaRepondu($adresse)) {
                return;
            }

            $this->envoyer($adresse);
            $this->enregistrer($adresse);

            $this->logger->info('Auto-reponse spontanee envoyee', ['destinataire' => $adresse]);
        } catch (Throwable $e) {
            $this->logger->warning('Auto-reponse spontanee echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Normalise et valide l'adresse expediteur. null si absente ou invalide.
     */
    public static function adresseValide(?string $expediteur): ?string
    {
        $adresse = mb_strtolower(trim((string) $expediteur));

        return false !== filter_var($adresse, \FILTER_VALIDATE_EMAIL) ? $adresse : null;
    }

    /**
     * Adresse non humaine : partie locale automatique (mailer-daemon, no-reply...),
     * ou notre propre boite (from / reply-to), ou notre propre domaine (interne).
     */
    public static function estAutomatique(string $adresse, string $fromEmail, string $replyTo): bool
    {
        $adresse = mb_strtolower(trim($adresse));

        foreach ([$fromEmail, $replyTo] as $notre) {
            if ('' !== trim($notre) && $adresse === mb_strtolower(trim($notre))) {
                return true;
            }
        }

        $arobase = strrpos($adresse, '@');
        if (false === $arobase) {
            return true;
        }
        $locale = substr($adresse, 0, $arobase);
        $domaine = substr($adresse, $arobase + 1);

        foreach (self::LOCALES_AUTOMATIQUES as $motif) {
            if (str_contains($locale, $motif)) {
                return true;
            }
        }

        // Meme domaine que nous (mail interne) : pas d'auto-reponse.
        $notreDomaine = self::domaine($fromEmail);

        return '' !== $notreDomaine && $domaine === $notreDomaine;
    }

    /**
     * Mail en masse / automatique a NE PAS relancer : robots, listes de diffusion,
     * accuses, absences du bureau. Base sur les en-tetes standards.
     *
     * @param array<string, string> $headers cles en minuscules
     */
    public static function estEnMasseOuAuto(array $headers): bool
    {
        $lu = static fn (string $cle): string => mb_strtolower(trim($headers[$cle] ?? ''));

        // RFC 3834 : tout Auto-Submitted autre que "no" = message auto.
        $autoSubmitted = $lu('auto-submitted');
        if ('' !== $autoSubmitted && 'no' !== $autoSubmitted) {
            return true;
        }

        if (\in_array($lu('precedence'), ['bulk', 'list', 'junk', 'auto_reply'], true)) {
            return true;
        }

        foreach (['list-id', 'list-unsubscribe', 'x-auto-response-suppress', 'x-autoreply', 'x-autorespond'] as $cle) {
            if ('' !== $lu($cle)) {
                return true;
            }
        }

        return false;
    }

    private function dejaRepondu(string $adresse): bool
    {
        $seuil = (new DateTimeImmutable('-'.self::FENETRE_JOURS.' days'))->format('Y-m-d H:i:s');

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM recouvrement.auto_reponse_spontanee WHERE expediteur = :e AND envoye_le > :seuil',
            ['e' => $adresse, 'seuil' => $seuil],
        );
    }

    private function enregistrer(string $adresse): void
    {
        $this->connection->executeStatement(
            'INSERT INTO recouvrement.auto_reponse_spontanee (expediteur, envoye_le) VALUES (:e, :now) '
            .'ON CONFLICT (expediteur) DO UPDATE SET envoye_le = :now',
            ['e' => $adresse, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    private function envoyer(string $adresse): void
    {
        $corps = $this->twig->render('recouvrement/email/auto_reponse_spontanee.html.twig', [
            'logo_src' => $this->logo->srcCid(),
        ]);

        $email = (new Email())
            ->from(new Address($this->fromEmail, 'Service recouvrement'))
            ->to($adresse)
            ->replyTo(new Address($this->replyTo))
            ->subject('Votre message - Service recouvrement Groupe Synthauto')
            ->html($corps);

        // Logo SYNTHAUTO embarque en inline (CID) : Gmail affiche le CID, ignore les data-URI.
        if (is_file($this->logo->chemin())) {
            $email->embedFromPath($this->logo->chemin(), LogoRecouvrement::CID, 'image/png');
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-Transport', 'relances');
        // Anti-boucle : signale que c'est une reponse automatique (RFC 3834).
        $headers->addTextHeader('Auto-Submitted', 'auto-replied');
        $headers->addTextHeader('X-Auto-Response-Suppress', 'All');

        $this->mailer->send($email);
    }

    private static function domaine(string $email): string
    {
        $arobase = strrpos(mb_strtolower(trim($email)), '@');

        return false === $arobase ? '' : substr(mb_strtolower(trim($email)), $arobase + 1);
    }
}
