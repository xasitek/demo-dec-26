<?php

declare(strict_types=1);

namespace App\Garanties\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Import du scrap des dossiers de garantie constructeur (RPA Fiat/Opel) vers
 * garanties.dossier. Meme philosophie que l'ETL mirror : upsert idempotent sur
 * une cle stable, jamais de suppression, detection de changement par content_hash.
 *
 * Cle naturelle : (emetteur, mvs, numero_or) — alignee sur celle utilisee par le
 * RPA cote Google Sheet (a remplacer). Le RPA appelle l'API par bucket
 * (concession, marque, emetteur) ; les lignes du bucket non revues dans le
 * run sont marquees present_dans_scrap = false.
 *
 * Toute transition de statut (ex. en cours -> 21 payee) est tracee dans
 * garanties.dossier_changement pour reconstituer l'historique.
 */
final class GarantiesScrapImporter
{
    private const LOT = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Import API : appel par bucket (concession, marque, emetteur). Apres
     * upsert, les lignes du meme bucket non revues sont marquees
     * present_dans_scrap = false. Detection des transitions de statut tracee
     * dans garanties.dossier_changement.
     *
     * @param list<array<string, string>> $rows
     *
     * @return array{lignes: int, nouveaux: int, transitions: int, introuvables: int}
     */
    public function importerBucket(string $concession, string $marque, string $emetteur, array $rows): array
    {
        $debut = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // 1. Mapper et dedoublonner par cle naturelle.
        /** @var array<string, array<string, string|null>> $parCle */
        $parCle = [];
        foreach ($rows as $brut) {
            $champs = $this->mapperLigne($brut, $concession, $debut);
            $cleNat = self::cleNaturelle($champs);
            if (null === $cleNat) {
                continue;
            }
            $parCle[$cleNat] = $champs;
        }

        // 2. Charger les statuts existants pour les cles vues (pour transitions).
        $statutsExistants = $this->statutsExistantsParCles(array_keys($parCle));

        // 3. Identifier les transitions de statut.
        /** @var list<array{cle_nat: string, ancien: ?string, nouveau: ?string}> $transitions */
        $transitions = [];
        $nouveaux = 0;
        foreach ($parCle as $cleNat => $champs) {
            if (\array_key_exists($cleNat, $statutsExistants)) {
                $ancien = $statutsExistants[$cleNat];
                if ($ancien !== $champs['statut_code']) {
                    $transitions[] = ['cle_nat' => $cleNat, 'ancien' => $ancien, 'nouveau' => $champs['statut_code']];
                }
            } else {
                ++$nouveaux;
            }
        }

        // 4. Upsert par lots.
        foreach (array_chunk($parCle, self::LOT, true) as $lot) {
            $this->upsertLot(array_values($lot));
        }

        // 5. Marquage des introuvables (lignes du meme bucket non revues).
        $introuvables = $this->marquerIntrouvables($concession, $marque, $emetteur, $debut);

        // 6. Enregistrement des transitions.
        $this->enregistrerTransitions($transitions, $debut);

        $this->logger->info('Import scrap garanties {concession}/{marque}/{emetteur} : {lignes} lignes, {nouveaux} nouveaux, {transitions} transitions, {introuvables} introuvables', [
            'concession' => $concession,
            'marque' => $marque,
            'emetteur' => $emetteur,
            'lignes' => \count($parCle),
            'nouveaux' => $nouveaux,
            'transitions' => \count($transitions),
            'introuvables' => $introuvables,
        ]);

        return [
            'lignes' => \count($parCle),
            'nouveaux' => $nouveaux,
            'transitions' => \count($transitions),
            'introuvables' => $introuvables,
        ];
    }

    /**
     * @param list<string> $clesNat
     *
     * @return array<string, string|null> cle naturelle => statut_code
     */
    private function statutsExistantsParCles(array $clesNat): array
    {
        $map = [];
        if ([] === $clesNat) {
            return $map;
        }
        // Filtre cote DB : on construit la liste de triplets et on filtre dessus.
        // Plus simple : on filtre sur les num_dg en jeu (groupe etroit) puis on
        // affine en PHP. Volume typique : ~5k cles → ok.
        $numDgs = [];
        foreach ($clesNat as $cle) {
            $parts = explode('|', $cle, 3);
            if (3 === \count($parts)) {
                $numDgs[$parts[0]] = true;
            }
        }
        if ([] === $numDgs) {
            return $map;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT num_dg, mvs, emetteur, statut_code FROM garanties.dossier WHERE num_dg IN (:nums)',
            ['nums' => array_keys($numDgs)],
            ['nums' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $existants = array_flip($clesNat);
        foreach ($rows as $row) {
            $cle = $this->concatCleNat($row['num_dg'] ?? null, $row['mvs'] ?? null, $row['emetteur'] ?? null);
            if (null !== $cle && isset($existants[$cle])) {
                /** @var string|null $statut */
                $statut = $row['statut_code'] ?? null;
                $map[$cle] = $statut;
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $brut
     *
     * @return array{concession: string, marque: ?string, chassis: ?string, num_dg: ?string, mvs: ?string, numero_or: ?string, emetteur: ?string, statut_code: ?string, montant_dg: ?string, date_emission: ?string, date_intervention: ?string, date_comptable: ?string, code_frais: ?string, donnees: string, content_hash: string, premiere_vue: ?string, derniere_maj: ?string, importe_le: string}
     */
    private function mapperLigne(array $brut, string $concession, string $now): array
    {
        $json = json_encode($brut, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return [
            'concession' => $concession,
            'marque' => $this->normaliserMarque($brut['Marque'] ?? null),
            'chassis' => $this->nullSiVide($brut['Chassis'] ?? null),
            'num_dg' => $this->nullSiVide($brut['Num DG'] ?? null),
            'mvs' => $this->nullSiVide($brut['MVS'] ?? null),
            'numero_or' => $this->nullSiVide($brut["Numéro d'OR"] ?? null),
            'emetteur' => $this->nullSiVide($brut['Emetteur'] ?? null),
            'statut_code' => $this->nullSiVide($brut['Statut DG'] ?? null),
            'montant_dg' => $this->parserMontant($brut['Montant DG'] ?? null),
            'date_emission' => $this->nullSiVide($brut['Date émiss.'] ?? null),
            'date_intervention' => $this->parserDate($brut['Date Int.'] ?? null),
            'date_comptable' => $this->parserDate($brut['Date Comptab.'] ?? null),
            'code_frais' => $this->nullSiVide($brut['Code frais'] ?? null),
            'donnees' => $json,
            'content_hash' => md5($json),
            'premiere_vue' => $this->parserHorodatage($brut['Première vue'] ?? null),
            'derniere_maj' => $this->parserHorodatage($brut['Dernière MAJ'] ?? null),
            'importe_le' => $now,
        ];
    }

    /**
     * @param array<string, string|null> $champs
     */
    private static function cleNaturelle(array $champs): ?string
    {
        return self::concatCleNat($champs['num_dg'] ?? null, $champs['mvs'] ?? null, $champs['emetteur'] ?? null);
    }

    private static function concatCleNat(?string $numDg, ?string $mvs, ?string $emetteur): ?string
    {
        $numDg = trim((string) $numDg);
        $mvs = trim((string) $mvs);
        $emetteur = trim((string) $emetteur);
        if ('' === $numDg || '' === $emetteur) {
            return null;
        }

        return $numDg.'|'.$mvs.'|'.$emetteur;
    }

    /**
     * @param list<array<string, string|null>> $lignes
     */
    private function upsertLot(array $lignes): void
    {
        if ([] === $lignes) {
            return;
        }

        $colonnes = [
            'concession', 'marque', 'chassis', 'num_dg', 'mvs', 'numero_or', 'emetteur',
            'statut_code', 'montant_dg', 'date_emission', 'date_intervention',
            'date_comptable', 'code_frais', 'donnees', 'content_hash',
            'premiere_vue', 'derniere_maj', 'importe_le',
        ];

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $placeholders = [];
        $params = [];
        foreach ($lignes as $ligne) {
            // colonnes... + cree_le + modifie_le (tout positionnel).
            $placeholders[] = '('.implode(', ', array_fill(0, \count($colonnes) + 2, '?')).')';
            foreach ($colonnes as $colonne) {
                $params[] = $ligne[$colonne];
            }
            $params[] = $now;
            $params[] = $now;
        }

        $sql = sprintf(
            'INSERT INTO garanties.dossier (%s, cree_le, modifie_le) VALUES %s '
            .'ON CONFLICT (emetteur, mvs, numero_or) DO UPDATE SET '
            .'num_dg = EXCLUDED.num_dg, concession = EXCLUDED.concession, marque = EXCLUDED.marque, chassis = EXCLUDED.chassis, '
            .'statut_code = EXCLUDED.statut_code, '
            .'montant_dg = EXCLUDED.montant_dg, date_emission = EXCLUDED.date_emission, '
            .'date_intervention = EXCLUDED.date_intervention, date_comptable = EXCLUDED.date_comptable, '
            .'code_frais = EXCLUDED.code_frais, donnees = EXCLUDED.donnees, '
            .'derniere_maj = EXCLUDED.derniere_maj, importe_le = EXCLUDED.importe_le, '
            .'present_dans_scrap = true, '
            // premiere_vue : on conserve la plus ancienne vue connue.
            .'premiere_vue = LEAST(garanties.dossier.premiere_vue, EXCLUDED.premiere_vue), '
            .'modifie_le = CASE WHEN garanties.dossier.content_hash <> EXCLUDED.content_hash THEN EXCLUDED.cree_le ELSE garanties.dossier.modifie_le END, '
            .'content_hash = EXCLUDED.content_hash',
            implode(', ', $colonnes),
            implode(', ', $placeholders),
        );

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement($sql, $params);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Marque introuvables les lignes du bucket non revues dans ce run.
     */
    private function marquerIntrouvables(string $concession, string $marque, string $emetteur, string $debut): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE garanties.dossier SET present_dans_scrap = false '
            .'WHERE concession = :concession AND marque = :marque AND emetteur = :emetteur '
            .'  AND importe_le < :debut AND present_dans_scrap = true',
            ['concession' => $concession, 'marque' => $marque, 'emetteur' => $emetteur, 'debut' => $debut],
        );
    }

    /**
     * @param list<array{cle_nat: string, ancien: ?string, nouveau: ?string}> $transitions
     */
    private function enregistrerTransitions(array $transitions, string $now): void
    {
        if ([] === $transitions) {
            return;
        }

        $sql = 'INSERT INTO garanties.dossier_changement (dossier_id, constate_le, ancien_statut, nouveau_statut) '
            .'SELECT id, ?, ?, ? FROM garanties.dossier '
            .'WHERE num_dg = ? AND mvs = ? AND emetteur = ?';

        $this->connection->beginTransaction();
        try {
            foreach ($transitions as $t) {
                [$numDg, $mvs, $emetteur] = explode('|', $t['cle_nat'], 3);
                $this->connection->executeStatement(
                    $sql,
                    [$now, $t['ancien'], $t['nouveau'], $numDg, $mvs, $emetteur],
                );
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function nullSiVide(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return '' !== $valeur ? $valeur : null;
    }

    private function normaliserMarque(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if ('' === $valeur) {
            return null;
        }
        // "00 - FIAT" -> "Fiat" (aligne sur la casse de Progiciel "Marque (BU)").
        if (str_contains($valeur, '-')) {
            $parts = explode('-', $valeur);
            $valeur = trim((string) end($parts));
        }

        return ucfirst(strtolower($valeur));
    }

    private function parserMontant(?string $valeur): ?string
    {
        $valeur = (string) $valeur;
        $valeur = (string) preg_replace('/[^0-9,.\-]/u', '', $valeur);
        $valeur = str_replace(',', '.', $valeur);

        return '' !== $valeur && is_numeric($valeur) ? $valeur : null;
    }

    private function parserDate(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if ('' === $valeur) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('d/m/Y', $valeur);

        return false !== $date ? $date->format('Y-m-d') : null;
    }

    private function parserHorodatage(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if ('' === $valeur) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valeur);

        return false !== $date ? $date->format('Y-m-d H:i:s') : null;
    }
}
