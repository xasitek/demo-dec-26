<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Enum\RetourSource;
use App\Recouvrement\Service\IngestionRetourService;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Collecte les reponses clients (retours) depuis la boite IMAP de recouvrement.
 *
 * Configuration par variables d'environnement (cf. .env, vides par defaut) :
 *   - IMAP_DSN  : mailbox complete, ex. "{imap.exemple.fr:993/imap/ssl}INBOX" ;
 *   - sinon IMAP_HOST (+ IMAP_PORT, IMAP_FLAGS, IMAP_FOLDER) avec IMAP_USER /
 *     IMAP_PASS.
 *
 * Robustesse :
 *   - si la configuration est absente : message clair, sortie 0 (rien a faire,
 *     ce n'est pas une erreur — cas du dev local sans boite branchee) ;
 *   - si l'extension PHP imap est absente : message clair, sortie 0 ;
 *   - chaque message est traite dans son propre try/catch : un message corrompu
 *     n'interrompt pas la collecte. Le dedoublonnage (message_id) est porte par
 *     le service d'ingestion.
 *
 * Les messages traites avec succes sont marques "lus" (sauf en --dry-run).
 */
#[AsCommand(
    name: 'app:recouvrement:imap-fetch',
    description: 'Collecte les retours clients (reponses aux relances) depuis la boite IMAP.',
)]
final class ImapFetchRetoursCommand extends Command
{
    public function __construct(
        private readonly IngestionRetourService $ingestionRetourService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre maximal de messages traites sur ce run.',
                '100',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Lit et ingere sans marquer les messages comme lus.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recouvrement : collecte IMAP des retours clients');

        // Extension requise : sortie propre si absente (dev local Windows, etc.).
        if (!\function_exists('imap_open')) {
            $io->warning('Extension PHP "imap" absente : collecte ignoree. '
                .'Installez/activez ext-imap pour brancher la boite de reception.');

            return Command::SUCCESS;
        }

        $mailbox = $this->resoudreMailbox();
        $user = self::env('IMAP_USER');
        $pass = self::env('IMAP_PASS');

        if (null === $mailbox || null === $user || null === $pass) {
            $io->note('Configuration IMAP absente (IMAP_DSN ou IMAP_HOST + IMAP_USER + IMAP_PASS). '
                .'Aucune collecte. Renseignez les variables IMAP_* dans .env.local pour activer.');

            return Command::SUCCESS;
        }

        $limit = $this->lireLimit($input, $io);
        if (false === $limit) {
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        return $this->collecter($io, $mailbox, $user, $pass, $limit, $dryRun);
    }

    /**
     * Ouvre la boite, parcourt les messages non lus, ingere chacun, marque lus.
     */
    private function collecter(
        SymfonyStyle $io,
        string $mailbox,
        string $user,
        string $pass,
        int $limit,
        bool $dryRun,
    ): int {
        $connexion = @imap_open($mailbox, $user, $pass, 0, 1);
        if (false === $connexion) {
            $erreur = imap_last_error();
            $io->error('Connexion IMAP impossible : '.(\is_string($erreur) ? $erreur : 'erreur inconnue'));
            $this->logger->error('Recouvrement IMAP : connexion impossible', [
                'mailbox' => $mailbox,
                'erreur' => \is_string($erreur) ? $erreur : null,
            ]);

            return Command::FAILURE;
        }

        $ingeres = 0;
        $ignores = 0;
        $erreurs = 0;

        try {
            $numeros = imap_search($connexion, 'UNSEEN');
            if (false === $numeros || [] === $numeros) {
                $io->success('Aucun nouveau message a traiter.');

                return Command::SUCCESS;
            }

            $numeros = \array_slice($numeros, 0, $limit);
            $io->writeln(sprintf('<info>%d</info> message(s) non lu(s) a traiter.', \count($numeros)));

            foreach ($numeros as $numero) {
                try {
                    $email = $this->lireMessage($connexion, (int) $numero);
                    $retour = $this->ingestionRetourService->ingerer($email, RetourSource::IMAP);

                    if (null === $retour) {
                        ++$ignores;
                    } else {
                        ++$ingeres;
                        if (!$dryRun) {
                            imap_setflag_full($connexion, (string) $numero, '\\Seen');
                        }
                    }
                } catch (Throwable $e) {
                    ++$erreurs;
                    $this->logger->error('Recouvrement IMAP : echec traitement message', [
                        'numero' => (int) $numero,
                        'erreur' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            imap_close($connexion);
        }

        $io->success(sprintf(
            '%d ingere(s), %d ignore(s) (doublon), %d en erreur.%s',
            $ingeres,
            $ignores,
            $erreurs,
            $dryRun ? ' [simulation : messages non marques lus]' : '',
        ));

        return $erreurs > 0 && 0 === $ingeres ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Lit un message IMAP et le projette dans le format attendu par l'ingestion.
     *
     * @param \IMAP\Connection $connexion
     *
     * @return array{
     *     messageId: ?string,
     *     inReplyTo: ?string,
     *     from: ?string,
     *     sujet: ?string,
     *     corpsTexte: ?string,
     *     corpsHtml: ?string,
     *     headers: array<string, string>,
     *     recuLe: DateTimeImmutable
     * }
     */
    private function lireMessage(mixed $connexion, int $numero): array
    {
        $brutHeaders = imap_fetchheader($connexion, $numero);
        $headers = $this->parserHeaders(\is_string($brutHeaders) ? $brutHeaders : '');

        $entete = imap_headerinfo($connexion, $numero);

        $messageId = $headers['message-id'] ?? null;
        $inReplyTo = $headers['in-reply-to'] ?? null;
        $from = $headers['from'] ?? null;
        $sujet = null;
        $recuLe = new DateTimeImmutable();

        if (\is_object($entete)) {
            if (isset($entete->subject) && \is_string($entete->subject)) {
                $sujet = self::decoderMime($entete->subject);
            }
            if (isset($entete->fromaddress) && \is_string($entete->fromaddress)) {
                $from = self::decoderMime($entete->fromaddress);
            }
            if (isset($entete->date) && \is_string($entete->date)) {
                try {
                    $recuLe = new DateTimeImmutable($entete->date);
                } catch (Throwable) {
                    $recuLe = new DateTimeImmutable();
                }
            }
        }

        $corpsTexte = $this->extraireCorps($connexion, $numero, false);
        $corpsHtml = $this->extraireCorps($connexion, $numero, true);

        return [
            'messageId' => self::nullable($messageId),
            'inReplyTo' => self::nullable($inReplyTo),
            'from' => self::nullable($from),
            'sujet' => self::nullable($sujet),
            'corpsTexte' => self::nullable($corpsTexte),
            'corpsHtml' => self::nullable($corpsHtml),
            'headers' => $headers,
            'recuLe' => $recuLe,
        ];
    }

    /**
     * Extrait le corps texte (subtype TEXT) ou HTML (subtype HTML) du message.
     *
     * @param \IMAP\Connection $connexion
     */
    private function extraireCorps(mixed $connexion, int $numero, bool $html): ?string
    {
        $structure = imap_fetchstructure($connexion, $numero);
        if (!\is_object($structure)) {
            return null;
        }

        $sousType = $html ? 'HTML' : 'PLAIN';

        // Message simple (pas de parties).
        if (!isset($structure->parts) || !\is_array($structure->parts)) {
            $sousTypeMsg = isset($structure->subtype) && \is_string($structure->subtype)
                ? strtoupper($structure->subtype) : '';
            if ($sousTypeMsg === $sousType || (!$html && '' === $sousTypeMsg)) {
                $corps = imap_body($connexion, $numero);

                return \is_string($corps)
                    ? $this->decoderPartie($corps, isset($structure->encoding) ? (int) $structure->encoding : 0)
                    : null;
            }

            return null;
        }

        // Message multipart : on cherche la premiere partie du sous-type voulu.
        foreach ($structure->parts as $index => $partie) {
            if (!\is_object($partie)) {
                continue;
            }
            $sousTypePartie = isset($partie->subtype) && \is_string($partie->subtype)
                ? strtoupper($partie->subtype) : '';
            if ($sousTypePartie === $sousType) {
                $corps = imap_fetchbody($connexion, $numero, (string) ($index + 1));

                return \is_string($corps)
                    ? $this->decoderPartie($corps, isset($partie->encoding) ? (int) $partie->encoding : 0)
                    : null;
            }
        }

        return null;
    }

    /**
     * Decode une partie selon son encodage IMAP (3 = base64, 4 = quoted-printable).
     */
    private function decoderPartie(string $corps, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($corps, true),
            4 => quoted_printable_decode($corps),
            default => $corps,
        };
    }

    /**
     * Parse les en-tetes bruts en tableau cle (minuscule) => valeur. Gere le
     * repliement des lignes (continuation indentee).
     *
     * @return array<string, string>
     */
    private function parserHeaders(string $brut): array
    {
        $headers = [];
        $cleCourante = null;

        foreach (preg_split('/\r\n|\r|\n/', $brut) ?: [] as $ligne) {
            if ('' === $ligne) {
                continue;
            }

            // Ligne de continuation (commence par espace/tabulation).
            if (null !== $cleCourante && (str_starts_with($ligne, ' ') || str_starts_with($ligne, "\t"))) {
                $headers[$cleCourante] .= ' '.trim($ligne);

                continue;
            }

            $pos = strpos($ligne, ':');
            if (false === $pos) {
                continue;
            }

            $cle = strtolower(trim(substr($ligne, 0, $pos)));
            $valeur = trim(substr($ligne, $pos + 1));
            $headers[$cle] = $valeur;
            $cleCourante = $cle;
        }

        return $headers;
    }

    /**
     * Construit la mailbox IMAP depuis IMAP_DSN, ou IMAP_HOST + flags + dossier.
     */
    private function resoudreMailbox(): ?string
    {
        $dsn = self::env('IMAP_DSN');
        if (null !== $dsn) {
            return $dsn;
        }

        $host = self::env('IMAP_HOST');
        if (null === $host) {
            return null;
        }

        $port = self::env('IMAP_PORT') ?? '993';
        $flags = self::env('IMAP_FLAGS') ?? '/imap/ssl';
        $dossier = self::env('IMAP_FOLDER') ?? 'INBOX';

        return sprintf('{%s:%s%s}%s', $host, $port, $flags, $dossier);
    }

    private static function decoderMime(string $valeur): string
    {
        $decode = imap_mime_header_decode($valeur);
        if (!\is_array($decode)) {
            return $valeur;
        }

        $texte = '';
        foreach ($decode as $partie) {
            if (\is_object($partie) && isset($partie->text) && \is_string($partie->text)) {
                $texte .= $partie->text;
            }
        }

        return '' !== $texte ? $texte : $valeur;
    }

    /**
     * @return int|false int = limite valide, false = invalide
     */
    private function lireLimit(InputInterface $input, SymfonyStyle $io): int|false
    {
        $brut = $input->getOption('limit');
        if (!is_numeric($brut) || (int) $brut < 1) {
            $io->error('L\'option --limit doit etre un entier strictement positif.');

            return false;
        }

        return (int) $brut;
    }

    private static function env(string $nom): ?string
    {
        $valeur = $_ENV[$nom] ?? $_SERVER[$nom] ?? getenv($nom);
        if (!\is_string($valeur)) {
            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    private static function nullable(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = trim($valeur);

        return '' === $texte ? null : $texte;
    }
}
