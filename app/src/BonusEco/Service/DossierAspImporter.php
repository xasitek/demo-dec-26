<?php

declare(strict_types=1);

namespace App\BonusEco\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Normalise une ligne ASP (depuis CSV ou Google Sheet) et fait l'upsert
 * dans bonus_eco.dossier_asp.
 *
 * Cle naturelle : (num_chassis, num_dossier_mensuel). Pattern jamais de delete :
 * une ligne disparue du sheet reste en base, mais on pourrait la flagger
 * present_dans_sheet=FALSE par un sweep ulterieur si besoin.
 */
final class DossierAspImporter
{
    /**
     * Mapping noms colonnes source -> colonnes BDD.
     * Les colonnes source non listees sont ignorees (ex. 'excel', 'IdIndInterv',
     * et la colonne repetee 'numchassis|nomacqu|ville|modvehic').
     *
     * @var array<string, string>
     */
    private const MAPPING = [
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

    /** @var list<string> */
    private const CHAMPS_TEXTE = [
        'num_chassis', 'num_dossier_mensuel', 'denom_soc', 'num_siret', 'num_vente',
        'immatriculation', 'nom_acquereur', 'code_postal', 'ville', 'modele_vehicule',
        'cnit', 'mt_or_bonus', 'mt_or_sup_bonus', 'mt_or_prime_casse',
        'mt_or_prime_conversion', 'mt_or_leasing', 'numero_or', 'login', 'lib_etat',
    ];

    /** @var list<string> */
    private const CHAMPS_NUMERIQUES = [
        'taux_co2', 'mt_bonus', 'mt_sup_bonus', 'mt_prime_casse',
        'mt_prime_conversion', 'mt_leasing', 'mt_paye',
    ];

    /** @var list<string> */
    private const CHAMPS_DATES = [
        'date_acquisition', 'date_paie_bonus', 'date_paie_super_bonus',
        'date_paie_prime_casse', 'date_paie_prime_conversion', 'date_paie_leasing',
        'date_creation',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Importe un lot de lignes (header + data) issues d'un CSV ou Sheet.
     * Retourne (nbVues, nbInserts, nbUpdates, nbIgnorees).
     *
     * @param list<string>           $header Noms des colonnes
     * @param iterable<list<string>> $rows   Lignes data
     *
     * @return array{vues: int, inserts: int, updates: int, ignorees: int}
     */
    public function importBatch(array $header, iterable $rows): array
    {
        $idx = $this->indexColonnes($header);
        $stats = ['vues' => 0, 'inserts' => 0, 'updates' => 0, 'ignorees' => 0];

        $this->connection->beginTransaction();
        try {
            foreach ($rows as $row) {
                ++$stats['vues'];
                $data = $this->normaliser($row, $idx);
                if ('' === ((string) ($data['num_chassis'] ?? '')) || '' === ((string) ($data['num_dossier_mensuel'] ?? ''))) {
                    ++$stats['ignorees'];
                    continue;
                }
                $type = $this->upsert($data);
                'insert' === $type ? ++$stats['inserts'] : ++$stats['updates'];
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int>
     */
    private function indexColonnes(array $header): array
    {
        $idx = [];
        foreach ($header as $i => $col) {
            $col = trim($col);
            if (isset(self::MAPPING[$col]) && !isset($idx[self::MAPPING[$col]])) {
                $idx[self::MAPPING[$col]] = $i;
            }
        }

        return $idx;
    }

    /**
     * @param list<string>       $row
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
            $v = trim($row[$i]);

            return '' === $v ? null : $v;
        };

        $data = [];
        foreach (self::CHAMPS_TEXTE as $c) {
            $data[$c] = $get($c);
        }
        foreach (self::CHAMPS_NUMERIQUES as $c) {
            $v = $get($c);
            $data[$c] = null === $v ? null : (float) str_replace(',', '.', $v);
        }
        foreach (self::CHAMPS_DATES as $c) {
            $data[$c] = self::parseDate($get($c));
        }

        return $data;
    }

    private static function parseDate(?string $v): ?string
    {
        if (null === $v || '' === $v) {
            return null;
        }
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
        $r = $this->connection->fetchAssociative($sql, $data);

        return false !== $r && true === ($r['inserted'] ?? false) ? 'insert' : 'update';
    }
}
