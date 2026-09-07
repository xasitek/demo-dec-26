<?php

declare(strict_types=1);

namespace App\Garanties\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Import des CSV "historique CWS Toyota" (format reportXXXXX_AAAAMMJJ.csv).
 *
 * Encoding : UTF-16, separateur TAB, 35 colonnes. Statuts CWS (AP/AD/DE/DR/PD)
 * mappes vers nos codes (21/20/23/29/23). Upsert sur cle naturelle
 * (num_dg, mvs, emetteur=TOYOTA) — meme pattern que l'import CSV initial.
 *
 * Usage : app:garanties:ingest-toyota-report report70173_20260608.csv [report70175_...]
 */
#[AsCommand(
    name: 'app:garanties:ingest-toyota-report',
    description: 'Importe l\'historique CWS Toyota (format reportXXXXX_*.csv UTF-16).',
)]
final class IngestToyotaReportCommand extends Command
{
    /**
     * Mapping statut CWS Toyota -> code interne (cf. garanties.dossier.statut_code).
     */
    private const STATUTS = [
        'AP' => '21', // Approved      -> Payé
        'DR' => '29', // Declined      -> Refusé
        'AD' => '20', // Annulé        -> Annulé
        'DE' => '23', // Decided       -> En traitement
        'PD' => '23', // Pending Dec.  -> En traitement
    ];

    private const EMETTEUR = 'TOYOTA';
    private const MARQUE = 'Toyota';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('concession', InputArgument::REQUIRED, 'Nom de la concession (ex. "Toyota Besancon", "Toyota Metz")')
            ->addArgument('fichiers', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Un ou plusieurs CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $concession = (string) $input->getArgument('concession');
        /** @var list<string> $fichiers */
        $fichiers = $input->getArgument('fichiers');
        $io->note("Concession cible : $concession");

        $totalVues = 0;
        $totalInserts = 0;
        $totalUpdates = 0;
        $totalErreurs = 0;

        foreach ($fichiers as $fichier) {
            if (!is_file($fichier)) {
                $io->warning("Introuvable : $fichier");
                continue;
            }
            $io->section("Import $fichier");
            $stats = $this->importerFichier($fichier, $concession, $io);
            $io->writeln(sprintf(
                '  %d lignes vues, %d nouveaux, %d MAJ, %d erreurs',
                $stats['vues'],
                $stats['inserts'],
                $stats['updates'],
                $stats['erreurs'],
            ));
            $totalVues += $stats['vues'];
            $totalInserts += $stats['inserts'];
            $totalUpdates += $stats['updates'];
            $totalErreurs += $stats['erreurs'];
        }

        $io->success(sprintf(
            'Total : %d lignes, %d nouveaux, %d MAJ, %d erreurs',
            $totalVues,
            $totalInserts,
            $totalUpdates,
            $totalErreurs,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{vues: int, inserts: int, updates: int, erreurs: int}
     */
    private function importerFichier(string $chemin, string $concession, SymfonyStyle $io): array
    {
        $stats = ['vues' => 0, 'inserts' => 0, 'updates' => 0, 'erreurs' => 0];

        // Conversion UTF-16 -> UTF-8 en memoire (les fichiers font max ~600 lignes).
        $contenu = file_get_contents($chemin);
        if (false === $contenu) {
            $io->error("Lecture impossible : $chemin");
            ++$stats['erreurs'];

            return $stats;
        }
        $utf8 = iconv('UTF-16', 'UTF-8//IGNORE', $contenu);
        if (false === $utf8) {
            $io->error("Conversion UTF-16 echouee : $chemin");
            ++$stats['erreurs'];

            return $stats;
        }

        $lignes = preg_split('/\r\n|\n|\r/', $utf8);
        if (false === $lignes || \count($lignes) < 2) {
            $io->warning("Fichier vide : $chemin");

            return $stats;
        }

        $header = str_getcsv($lignes[0], "\t", '"', '\\');
        $idx = $this->indexColonnes($header);

        // Transaction par ligne via savepoint : si une ligne foire, on isole
        // l'echec sans casser tout le batch (sinon "transaction aborted").
        for ($i = 1; $i < \count($lignes); ++$i) {
            $ligne = trim($lignes[$i]);
            if ('' === $ligne) {
                continue;
            }
            ++$stats['vues'];

            $row = str_getcsv($ligne, "\t", '"', '\\');
            try {
                $data = $this->normaliser($row, $idx, $concession);
                if (null === $data) {
                    ++$stats['erreurs'];
                    continue;
                }
                $this->connection->beginTransaction();
                try {
                    $type = $this->upsert($data);
                    $this->connection->commit();
                    'insert' === $type ? ++$stats['inserts'] : ++$stats['updates'];
                } catch (Throwable $e) {
                    $this->connection->rollBack();
                    throw $e;
                }
            } catch (Throwable $e) {
                ++$stats['erreurs'];
                if ($stats['erreurs'] <= 3) {
                    $io->warning("Ligne $i : ".$e->getMessage());
                }
            }
        }

        return $stats;
    }

    /**
     * @param list<string|null> $header
     *
     * @return array<string, int>
     */
    private function indexColonnes(array $header): array
    {
        $map = [];
        foreach ($header as $i => $col) {
            if (null === $col) {
                continue;
            }
            $map[trim($col)] = $i;
        }

        return $map;
    }

    /**
     * @param list<string|null>  $row
     * @param array<string, int> $idx
     *
     * @return array<string, mixed>|null null si ligne invalide (sans num_dg ou VIN)
     */
    private function normaliser(array $row, array $idx, string $concession): ?array
    {
        $get = static function (string $col) use ($row, $idx): string {
            $i = $idx[$col] ?? null;
            if (null === $i) {
                return '';
            }
            $v = $row[$i] ?? null;

            return null === $v ? '' : trim($v);
        };

        $numDg = $get('N° DG NMSC');
        $vin = $get('VIN');
        if ('' === $numDg || '' === $vin) {
            return null;
        }

        $statutCsv = strtoupper($get('Statut'));
        $statutCode = self::STATUTS[$statutCsv] ?? '23';

        $montant = self::parseMontant($get('Total remb. au RA'));
        if (null === $montant) {
            // Fallback : montant demandé si pas de remboursement
            $montant = self::parseMontant($get('Total dem. par RA'));
        }

        return [
            'num_dg' => $numDg,
            'mvs' => $vin,
            'emetteur' => self::EMETTEUR,
            'chassis' => substr($vin, -8),
            'marque' => self::MARQUE,
            'concession' => $concession,
            'statut_code' => $statutCode,
            'numero_or' => trim($get('Repair Order Number')),
            'montant_dg' => $montant,
            'date_intervention' => self::parseDate($get('Date de réparation')),
        ];
    }

    private static function parseDate(string $v): ?string
    {
        if ('' === $v) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('d/m/Y', $v)
            ?: DateTimeImmutable::createFromFormat('Y-m-d', $v);

        return false === $d ? null : $d->format('Y-m-d');
    }

    private static function parseMontant(string $v): ?float
    {
        if ('' === $v) {
            return null;
        }
        $clean = str_replace([' ', ','], ['', '.'], $v);

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return 'insert'|'update'
     */
    private function upsert(array $data): string
    {
        // garanties.dossier a une cle unique (emetteur, mvs, numero_or) ;
        // ON CONFLICT met a jour les autres colonnes (le num_dg le plus recent gagne).
        // content_hash : detecte les changements (NOT NULL en BDD).
        $data['content_hash'] = md5(json_encode($data, JSON_THROW_ON_ERROR));

        $sql = 'INSERT INTO garanties.dossier '
            .'(num_dg, mvs, emetteur, chassis, marque, concession, statut_code, numero_or, montant_dg, date_intervention, present_dans_scrap, donnees, content_hash, importe_le, cree_le, modifie_le) '
            .'VALUES (:num_dg, :mvs, :emetteur, :chassis, :marque, :concession, :statut_code, :numero_or, :montant_dg, :date_intervention, TRUE, \'{}\'::jsonb, :content_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            .'ON CONFLICT (emetteur, mvs, numero_or) DO UPDATE SET '
            .'num_dg = EXCLUDED.num_dg, chassis = EXCLUDED.chassis, marque = EXCLUDED.marque, concession = EXCLUDED.concession, '
            .'statut_code = EXCLUDED.statut_code, '
            .'montant_dg = EXCLUDED.montant_dg, date_intervention = EXCLUDED.date_intervention, '
            .'present_dans_scrap = TRUE, content_hash = EXCLUDED.content_hash, modifie_le = CURRENT_TIMESTAMP '
            .'RETURNING (xmax = 0) AS inserted';

        /** @var array<string, mixed>|false $r */
        $r = $this->connection->fetchAssociative($sql, $data);

        return false !== $r && true === ($r['inserted'] ?? false) ? 'insert' : 'update';
    }
}
