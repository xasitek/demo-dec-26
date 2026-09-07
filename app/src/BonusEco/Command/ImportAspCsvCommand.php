<?php

declare(strict_types=1);

namespace App\BonusEco\Command;

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
 * Import / seed initial du CSV ASP dans bonus_eco.dossier_asp.
 *
 * Pattern upsert (jamais de delete) sur cle naturelle (num_chassis,
 * num_dossier_mensuel). Sera remplace par la sync hebdo Google Sheet
 * une fois le service account configure.
 */
#[AsCommand(
    name: 'app:bonus-eco:import-csv',
    description: 'Importe le CSV ASP dans bonus_eco.dossier_asp (upsert).',
)]
final class ImportAspCsvCommand extends Command
{
    public function __construct(
        private readonly Connection $defaultConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('chemin', InputArgument::REQUIRED, 'Chemin du fichier CSV ASP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chemin = (string) $input->getArgument('chemin');

        if (!is_file($chemin)) {
            $io->error("Fichier introuvable : $chemin");

            return Command::FAILURE;
        }

        $f = fopen($chemin, 'r');
        if (false === $f) {
            $io->error('Impossible d\'ouvrir le fichier.');

            return Command::FAILURE;
        }

        $header = fgetcsv($f, escape: '\\');
        if (false === $header) {
            $io->error('CSV vide.');
            fclose($f);

            return Command::FAILURE;
        }

        // Index des colonnes attendues (les colonnes repetees a la fin sont ignorees).
        $idx = $this->indexColonnes($header);

        $nbVues = 0;
        $nbInserts = 0;
        $nbUpdates = 0;
        $erreurs = 0;

        $this->defaultConnection->beginTransaction();
        try {
            while (false !== ($row = fgetcsv($f, escape: '\\'))) {
                ++$nbVues;
                try {
                    $data = $this->normaliser($row, $idx);
                    if ('' === ($data['num_chassis'] ?? '') || '' === ($data['num_dossier_mensuel'] ?? '')) {
                        continue;
                    }
                    $type = $this->upsert($data);
                    'insert' === $type ? ++$nbInserts : ++$nbUpdates;
                } catch (Throwable $e) {
                    ++$erreurs;
                    if ($erreurs <= 5) {
                        $io->warning(sprintf('Ligne %d : %s', $nbVues + 1, $e->getMessage()));
                    }
                }
            }
            $this->defaultConnection->commit();
        } catch (Throwable $e) {
            $this->defaultConnection->rollBack();
            fclose($f);
            $io->error('Echec global : '.$e->getMessage());

            return Command::FAILURE;
        }
        fclose($f);

        $io->success(sprintf(
            '%d lignes vues, %d nouveaux, %d mises à jour, %d erreurs.',
            $nbVues,
            $nbInserts,
            $nbUpdates,
            $erreurs,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string|null> $header
     *
     * @return array<string, int>
     */
    private function indexColonnes(array $header): array
    {
        // Mapping CSV header -> colonne BDD. Les noms repetes sont ignores
        // (on prend la premiere occurrence de chaque cle attendue).
        $map = [
            'DenomSoc' => 'denom_soc',
            'NumSIRET' => 'num_siret',
            'NumDossMens' => 'num_dossier_mensuel',
            'NumVente' => 'num_vente',
            'Immatriculation' => 'immatriculation',
            'NumChassis' => 'num_chassis',
            'NomAcqu' => 'nom_acquereur',
            'CodePostal' => 'code_postal',
            'Ville' => 'ville',
            'ModVehic' => 'modele_vehicule',
            'CNIT' => 'cnit',
            'DateAcqui' => 'date_acquisition',
            'TauxCO2' => 'taux_co2',
            'MtBonus' => 'mt_bonus',
            'MtORBonus' => 'mt_or_bonus',
            'MtSupBonus' => 'mt_sup_bonus',
            'MtORSupBonus' => 'mt_or_sup_bonus',
            'MtPrimeCasse' => 'mt_prime_casse',
            'MtORPrimeCasse' => 'mt_or_prime_casse',
            'MtPrimeConversion' => 'mt_prime_conversion',
            'MtORPrimeConversion' => 'mt_or_prime_conversion',
            'MtLeasing' => 'mt_leasing',
            'MtORLeasing' => 'mt_or_leasing',
            'MtPaye' => 'mt_paye',
            'DatePaieBonus' => 'date_paie_bonus',
            'DatePaieSuperBonus' => 'date_paie_super_bonus',
            'DatePaiePrimeCasse' => 'date_paie_prime_casse',
            'DatePaiePrimeConversion' => 'date_paie_prime_conversion',
            'DatePaieLeasing' => 'date_paie_leasing',
            'NumeroOR' => 'numero_or',
            'Login' => 'login',
            'DateCre' => 'date_creation',
            'LibEtat' => 'lib_etat',
        ];

        $idx = [];
        foreach ($header as $i => $col) {
            $col = trim((string) $col);
            if (isset($map[$col]) && !isset($idx[$map[$col]])) {
                $idx[$map[$col]] = $i;
            }
        }

        return $idx;
    }

    /**
     * @param list<string|null>  $row
     * @param array<string, int> $idx
     *
     * @return array<string, mixed>
     */
    private function normaliser(array $row, array $idx): array
    {
        $get = static function (string $col) use ($row, $idx): ?string {
            $i = $idx[$col] ?? null;
            if (null === $i || !isset($row[$i])) {
                return null;
            }
            $v = trim((string) $row[$i]);

            return '' === $v ? null : $v;
        };

        $champsTexte = ['num_chassis', 'num_dossier_mensuel', 'denom_soc', 'num_siret', 'num_vente',
            'immatriculation', 'nom_acquereur', 'code_postal', 'ville', 'modele_vehicule',
            'cnit', 'mt_or_bonus', 'mt_or_sup_bonus', 'mt_or_prime_casse',
            'mt_or_prime_conversion', 'mt_or_leasing', 'numero_or', 'login', 'lib_etat'];
        $champsNumeriques = ['taux_co2', 'mt_bonus', 'mt_sup_bonus', 'mt_prime_casse',
            'mt_prime_conversion', 'mt_leasing', 'mt_paye'];
        $champsDates = ['date_acquisition', 'date_paie_bonus', 'date_paie_super_bonus',
            'date_paie_prime_casse', 'date_paie_prime_conversion', 'date_paie_leasing',
            'date_creation'];

        $data = [];
        foreach ($champsTexte as $c) {
            $data[$c] = $get($c);
        }
        foreach ($champsNumeriques as $c) {
            $v = $get($c);
            $data[$c] = null === $v ? null : (float) str_replace(',', '.', $v);
        }
        foreach ($champsDates as $c) {
            $data[$c] = self::parseDate($get($c));
        }

        return $data;
    }

    private static function parseDate(?string $v): ?string
    {
        if (null === $v || '' === $v) {
            return null;
        }
        // Format CSV : DD/MM/YYYY
        $d = DateTimeImmutable::createFromFormat('d/m/Y', $v);
        if (false === $d) {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $v);
        }

        return false === $d ? null : $d->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return 'insert'|'update'
     */
    private function upsert(array $data): string
    {
        // Construit le INSERT ... ON CONFLICT pour upsert atomique.
        $cols = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':'.$c, $cols);
        $updates = array_map(static fn (string $c): string => sprintf('%s = EXCLUDED.%s', $c, $c), $cols);

        $sql = sprintf(
            'INSERT INTO bonus_eco.dossier_asp (%s, present_dans_sheet, updated_at) VALUES (%s, TRUE, CURRENT_TIMESTAMP) '
            .'ON CONFLICT (num_chassis, num_dossier_mensuel) DO UPDATE SET %s, present_dans_sheet = TRUE, updated_at = CURRENT_TIMESTAMP '
            .'RETURNING (xmax = 0) AS inserted',
            implode(', ', $cols),
            implode(', ', $placeholders),
            implode(', ', $updates),
        );

        /** @var array<string, mixed>|false $r */
        $r = $this->defaultConnection->fetchAssociative($sql, $data);

        return false !== $r && true === ($r['inserted'] ?? false) ? 'insert' : 'update';
    }
}
