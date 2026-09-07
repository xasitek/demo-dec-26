<?php

declare(strict_types=1);

namespace App\Garanties\Command;

use App\Garanties\Service\GarantiesScrapImporter;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande d'amorcage : ingere un CSV exporte depuis la feuille TOYOTA du
 * Google Sheet partage (identifiant retire de la copie de demonstration).
 *
 * Le RPA Toyota ecrit dans ce sheet tant que Finance Créances n'est pas en prod.
 * Une fois prod, le RPA pushera directement vers /api/garanties/scrap/upsert
 * et cette commande deviendra obsolete.
 *
 * Usage : php bin/console app:garanties:ingest-toyota-csv chemin/vers/toyota.csv
 *
 * Mapping Toyota -> format API Fiat (genere par le RPA) :
 *   num_dg     <- num_dg_nmsc
 *   mvs        <- vin                (discriminant secondaire ; cle unique
 *                                     cote Toyota = num_dg_nmsc + vin)
 *   chassis    <- right(vin, 8)      (les 8 derniers caracteres, pour matcher Progiciel)
 *   numero_or  <- num_dg_ra
 *   emetteur   <- "TOYOTA"
 *   statut_code<- code_fiat          (21/23/22/29 deja mappes cote RPA)
 *   marque     <- "Toyota"
 *   concession <- colonne `concession` du CSV (1 compte CWS = 1 concession ;
 *                 import multi-concessions : Besancon, Metz, etc.)
 */
#[AsCommand(
    name: 'app:garanties:ingest-toyota-csv',
    description: 'Ingere un CSV Toyota exporte depuis Google Sheets (feuille TOYOTA) vers garanties.dossier.',
)]
final class IngestToyotaCsvCommand extends Command
{
    public function __construct(
        private readonly GarantiesScrapImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'fichier',
            InputArgument::REQUIRED,
            'Chemin absolu vers le CSV exporte depuis Google Sheets (feuille TOYOTA).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fichier = (string) $input->getArgument('fichier');

        if (!is_file($fichier)) {
            $io->error(sprintf('Fichier introuvable : %s', $fichier));

            return Command::FAILURE;
        }

        $handle = fopen($fichier, 'r');
        if (false === $handle) {
            $io->error(sprintf('Impossible d\'ouvrir : %s', $fichier));

            return Command::FAILURE;
        }

        try {
            $entetes = $this->lireEntetes($handle);
        } catch (RuntimeException $e) {
            fclose($handle);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        /** @var array<string, list<array<string, string>>> $parConcession */
        $parConcession = [];
        $ligne = 1;
        while (false !== ($cells = fgetcsv($handle, 0, ',', '"', '\\'))) {
            ++$ligne;
            if ([] === array_filter($cells, static fn ($v) => null !== $v && '' !== trim((string) $v))) {
                continue;
            }
            $raw = $this->associerLigne($entetes, $cells);
            $row = $this->mapperToyotaVersApi($raw, $ligne, $io);
            if (null === $row) {
                continue;
            }
            $concession = trim($raw['concession'] ?? '');
            if ('' === $concession) {
                $concession = 'Toyota inconnue';
            }
            $parConcession[$concession][] = $row;
        }
        fclose($handle);

        if ([] === $parConcession) {
            $io->warning('Aucune ligne exploitable dans le CSV.');

            return Command::SUCCESS;
        }

        $cumul = ['lignes' => 0, 'nouveaux' => 0, 'transitions' => 0, 'introuvables' => 0];
        foreach ($parConcession as $concession => $rowsApi) {
            $io->section(sprintf('Import de %d dossier(s) — %s...', \count($rowsApi), $concession));
            $stats = $this->importer->importerBucket($concession, 'Toyota', 'TOYOTA', $rowsApi);
            $io->writeln(sprintf(
                '  → %d lignes vues, %d nouveaux, %d transitions, %d introuvables',
                $stats['lignes'],
                $stats['nouveaux'],
                $stats['transitions'],
                $stats['introuvables'],
            ));
            $cumul['lignes'] += $stats['lignes'];
            $cumul['nouveaux'] += $stats['nouveaux'];
            $cumul['transitions'] += $stats['transitions'];
            $cumul['introuvables'] += $stats['introuvables'];
        }

        $io->success(sprintf(
            'Total %d concession(s) : %d lignes, %d nouveaux, %d transitions, %d introuvables.',
            \count($parConcession),
            $cumul['lignes'],
            $cumul['nouveaux'],
            $cumul['transitions'],
            $cumul['introuvables'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @param resource $handle
     *
     * @return list<string>
     */
    private function lireEntetes($handle): array
    {
        $entetes = fgetcsv($handle, 0, ',', '"', '\\');
        if (false === $entetes) {
            throw new RuntimeException('Le fichier est vide ou la 1re ligne (entetes) est invalide.');
        }
        /** @var list<string> $entetesNormalises */
        $entetesNormalises = array_map(static fn ($s) => strtolower(trim((string) $s)), $entetes);
        $attendues = [
            'code_fiat', 'marque', 'concession', 'num_dg_nmsc', 'num_dg_ra',
            'vin', 'opp', 'date_diagnostic', 'date_statut',
            'montant_demande', 'date_scrap',
        ];
        $manquantes = array_diff($attendues, $entetesNormalises);
        if ([] !== $manquantes) {
            throw new RuntimeException('Colonnes manquantes dans le CSV : '.implode(', ', $manquantes).'. Verifie que tu as bien exporte la feuille TOYOTA (Fichier > Telecharger > CSV).');
        }

        return $entetesNormalises;
    }

    /**
     * @param list<string>      $entetes
     * @param list<string|null> $cells
     *
     * @return array<string, string>
     */
    private function associerLigne(array $entetes, array $cells): array
    {
        $row = [];
        foreach ($entetes as $i => $nom) {
            $row[$nom] = trim((string) ($cells[$i] ?? ''));
        }

        return $row;
    }

    /**
     * @param array<string, string> $raw
     *
     * @return array<string, string>|null
     */
    private function mapperToyotaVersApi(array $raw, int $ligne, SymfonyStyle $io): ?array
    {
        $vin = $raw['vin'] ?? '';
        $numDg = $raw['num_dg_nmsc'] ?? '';
        if ('' === $vin || '' === $numDg) {
            $io->warning(sprintf('Ligne %d ignoree : VIN ou num_dg_nmsc vide.', $ligne));

            return null;
        }
        $chassis8 = \strlen($vin) >= 8 ? substr($vin, -8) : $vin;
        $dateScrap = $raw['date_scrap'] ?? '';

        return [
            'Marque' => 'Toyota',
            'Chassis' => $chassis8,
            'Num DG' => $numDg,
            'MVS' => $vin,
            "Numéro d'OR" => $raw['num_dg_ra'] ?? '',
            'Emetteur' => 'TOYOTA',
            'Statut DG' => $raw['code_fiat'] ?? '',
            'Montant DG' => $raw['montant_demande'] ?? '',
            'Date émiss.' => $raw['date_introduite'] ?? '',
            'Date Int.' => $raw['date_diagnostic'] ?? '',
            'Date Comptab.' => $raw['date_statut'] ?? '',
            'Code frais' => $raw['opp'] ?? '',
            'Première vue' => $this->normaliserHorodatage($dateScrap),
            'Dernière MAJ' => $this->normaliserHorodatage($dateScrap),
        ];
    }

    /**
     * Le RPA ecrit "YYYY-MM-DD HH:MM:SS" ; le service Importer accepte ce format
     * tel quel. Si vide, on renvoie l'instant present.
     */
    private function normaliserHorodatage(string $valeur): string
    {
        $valeur = trim($valeur);
        if ('' === $valeur) {
            return (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        return $valeur;
    }
}
