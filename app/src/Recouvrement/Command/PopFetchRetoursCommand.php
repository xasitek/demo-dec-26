<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Enum\RetourSource;
use App\Recouvrement\Service\IngestionRetourService;
use App\Recouvrement\Service\Pop3Client;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use ZBateson\MailMimeParser\Message;

/**
 * Collecte les reponses clients (retours) depuis la boite POP3S de recouvrement.
 *
 * Alternative a app:recouvrement:imap-fetch pour les serveurs ou le port IMAP
 * (993) est bloque mais le POP3S (995) ouvert, et ou ext-imap n'est pas installe.
 * Ne depend que d'un socket TLS et du parseur MIME zbateson/mail-mime-parser.
 *
 * Configuration (env, vides par defaut -> commande no-op) :
 *   RECOUVREMENT_POP3_HOST / _PORT / _USER / _PASS.
 * Pour Gmail : USER = adresse complete, PASS = mot de passe d'application.
 *
 * Mode "recent" Gmail : l'utilisateur est prefixe par "recent:" -> Gmail sert les
 * messages des 30 derniers jours a chaque passage. Combine au dedoublonnage par
 * message_id (IngestionRetourService), aucun retour n'est perdu ni traite deux fois.
 *
 * Chaque message est traite dans son propre try/catch : un message corrompu
 * n'interrompt pas la collecte.
 */
#[AsCommand(
    name: 'app:recouvrement:pop-fetch',
    description: 'Collecte les retours clients (reponses aux relances) depuis la boite POP3S.',
)]
final class PopFetchRetoursCommand extends Command
{
    /** Plafond de taille par message (anti-OOM) : au-dela, le message est ignore. */
    private const TAILLE_MAX_OCTETS = 30_000_000;

    /** Plafond par piece jointe stockee en base (au-dela : ignoree). */
    private const TAILLE_MAX_PIECE = 15_000_000;

    public function __construct(
        private readonly IngestionRetourService $ingestionRetourService,
        private readonly Pop3Client $pop3Client,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly string $port,
        private readonly string $user,
        private readonly string $pass,
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
                'Lit et parse les messages sans enregistrer les retours en base.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recouvrement : collecte POP3 des retours clients');

        $host = trim($this->host);
        $user = trim($this->user);
        $pass = trim($this->pass);

        if ('' === $host || '' === $user || '' === $pass) {
            $io->note('Configuration POP3 absente (RECOUVREMENT_POP3_HOST/_USER/_PASS). '
                .'Aucune collecte. Renseignez ces variables dans .env.local pour activer.');

            return Command::SUCCESS;
        }

        $port = (int) trim($this->port);
        if ($port < 1) {
            $port = 995;
        }

        $limit = $this->lireLimit($input, $io);
        if (false === $limit) {
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        return $this->collecter($io, $host, $port, $user, $pass, $limit, $dryRun);
    }

    /**
     * Se connecte, parcourt les messages, ingere chacun (sauf --dry-run).
     */
    private function collecter(
        SymfonyStyle $io,
        string $host,
        int $port,
        string $user,
        string $pass,
        int $limit,
        bool $dryRun,
    ): int {
        // Mode "recent" Gmail : recevoir les messages des 30 derniers jours
        // independamment de l'etat "deja telecharge" (le dedoublonnage message_id
        // gere les doublons). Actif pour les hotes Gmail (gmail.com / googlemail.com).
        $recent = str_contains($host, 'gmail') || str_contains($host, 'googlemail');
        $utilisateur = $recent ? 'recent:'.$user : $user;
        $this->logger->info('Recouvrement POP3 : demarrage collecte', ['hote' => $host, 'mode_recent' => $recent]);

        try {
            $this->pop3Client->connecter($host, $port);
            $this->pop3Client->sIdentifier($utilisateur, $pass);
            $total = $this->pop3Client->nombreMessages();
        } catch (Throwable $e) {
            $io->error('Connexion / authentification POP3 impossible : '.$e->getMessage());
            $this->logger->error('Recouvrement POP3 : connexion impossible', ['erreur' => $e->getMessage()]);
            $this->pop3Client->fermer();

            return Command::FAILURE;
        }

        if (0 === $total) {
            $io->success('Aucun message dans la boite.');
            $this->pop3Client->fermer();

            return Command::SUCCESS;
        }

        $aTraiter = min($total, $limit);
        $io->writeln(sprintf('<info>%d</info> message(s) disponible(s), traitement de %d.', $total, $aTraiter));

        $ingeres = 0;
        $ignores = 0;
        $erreurs = 0;

        try {
            // Du plus recent ($total) au plus ancien : si le backlog depasse la
            // limite, on privilegie les nouvelles reponses (les plus utiles).
            for ($numero = $total; $numero > $total - $aTraiter; --$numero) {
                try {
                    $taille = $this->pop3Client->tailleMessage($numero);
                    if ($taille > self::TAILLE_MAX_OCTETS) {
                        ++$ignores;
                        $this->logger->warning('Recouvrement POP3 : message ignore (trop volumineux)', [
                            'numero' => $numero,
                            'octets' => $taille,
                        ]);

                        continue;
                    }

                    $brut = $this->pop3Client->recuperer($numero);
                    $email = $this->parser($brut);

                    if ($dryRun) {
                        ++$ingeres;

                        continue;
                    }

                    $retour = $this->ingestionRetourService->ingerer($email, RetourSource::POP3);
                    if (null === $retour) {
                        ++$ignores;
                    } else {
                        ++$ingeres;
                    }
                } catch (Throwable $e) {
                    ++$erreurs;
                    $this->logger->error('Recouvrement POP3 : echec traitement message', [
                        'numero' => $numero,
                        'erreur' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->pop3Client->fermer();
        }

        $io->success(sprintf(
            '%d %s, %d ignore(s) (doublon), %d en erreur.',
            $ingeres,
            $dryRun ? 'lu(s) [simulation]' : 'ingere(s)',
            $ignores,
            $erreurs,
        ));

        return $erreurs > 0 && 0 === $ingeres ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Parse un message brut (RFC 822) dans le format attendu par l'ingestion.
     *
     * @return array{
     *     messageId: ?string,
     *     inReplyTo: ?string,
     *     from: ?string,
     *     to: ?string,
     *     cc: ?string,
     *     sujet: ?string,
     *     corpsTexte: ?string,
     *     corpsHtml: ?string,
     *     headers: array<string, string>,
     *     piecesJointes: list<array{nom: string, typeMime: ?string, contenu: string}>,
     *     recuLe: DateTimeImmutable
     * }
     */
    private function parser(string $brut): array
    {
        $message = Message::from($brut, false);

        $recuLe = new DateTimeImmutable();
        $dateBrute = $message->getHeaderValue('Date');
        if (null !== $dateBrute && '' !== trim($dateBrute)) {
            try {
                $recuLe = new DateTimeImmutable($dateBrute);
            } catch (Throwable) {
                $recuLe = new DateTimeImmutable();
            }
        }

        $headers = [];
        foreach ($message->getAllHeaders() as $header) {
            $headers[strtolower($header->getName())] = $header->getValue() ?? '';
        }

        // Pieces jointes : nom + type + contenu binaire (cap par piece anti-OOM).
        $piecesJointes = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $contenu = $part->getContent();
            if (!\is_string($contenu) || '' === $contenu) {
                continue;
            }
            if (\strlen($contenu) > self::TAILLE_MAX_PIECE) {
                $this->logger->warning('Recouvrement POP3 : piece jointe ignoree (trop volumineuse)', [
                    'nom' => $part->getFilename(),
                    'octets' => \strlen($contenu),
                ]);

                continue;
            }
            $piecesJointes[] = [
                'nom' => self::nomPiece($part->getFilename()),
                'typeMime' => $part->getContentType(),
                'contenu' => $contenu,
            ];
        }

        return [
            'messageId' => self::nullable($message->getHeaderValue('Message-ID')),
            'inReplyTo' => self::nullable($message->getHeaderValue('In-Reply-To')),
            'from' => self::nullable($message->getHeaderValue('From')),
            'to' => self::nullable($message->getHeaderValue('To')),
            'cc' => self::nullable($message->getHeaderValue('Cc')),
            'sujet' => self::nullable($message->getHeaderValue('Subject')),
            'corpsTexte' => self::nullable($message->getTextContent()),
            'corpsHtml' => self::nullable($message->getHtmlContent()),
            'headers' => $headers,
            'piecesJointes' => $piecesJointes,
            'recuLe' => $recuLe,
        ];
    }

    /**
     * Nom de fichier sur pour une piece jointe (repli si absent).
     */
    private static function nomPiece(?string $nom): string
    {
        $nom = trim((string) $nom);

        return '' !== $nom ? $nom : 'piece-jointe';
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

    private static function nullable(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = trim($valeur);

        return '' === $texte ? null : $texte;
    }
}
