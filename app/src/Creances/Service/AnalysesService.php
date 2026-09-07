<?php

declare(strict_types=1);

namespace App\Creances\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Calculs analytiques : previsions d'encaissement a 3 mois et radar de
 * risque (5 axes alignes Gestion commerciale).
 *
 * Les previsions reposent sur :
 *  - la date de promesse si elle existe (priorite forte)
 *  - sinon une date d'echeance + retard comportemental moyen (sur la
 *    base des comptes deja regles dans bal_eloficash)
 *
 * Le radar produit 5 axes sur une echelle de 0 a 10 (0 = sain, 10 =
 * critique) : risque (10 - score), echu, promesses (echues / total),
 * litiges (montant en litige / encours), DSO (10 - ratio BPDSO/DSO).
 */
final class AnalysesService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IndicateursService $indicateurs,
    ) {
    }

    // ============================================================
    // Previsions d'encaissement a 3 mois
    // ============================================================

    /**
     * @return array{
     *     periodes: array<string, array{libelle: string, montant: float, nombre: int}>,
     *     total_pondere: float,
     *     ecritures_incluses: int
     * }
     */
    public function previsionsEncaissement(): array
    {
        $now = new DateTimeImmutable('today');
        $finSemaine = $now->modify('next sunday')->format('Y-m-d');
        $finMois = $now->modify('last day of this month')->format('Y-m-d');
        $finMoisProchain = $now->modify('last day of next month')->format('Y-m-d');
        $fin3Mois = $now->modify('+3 months')->format('Y-m-d');

        // Retard moyen pour estimer la date previsionnelle quand pas de promesse.
        $retardMoyen = $this->retardMoyenJours();

        $sql = "SELECT b.donnees->>'numero' AS numero, "
            ."(b.donnees->>'Montant (valeur absolue)')::NUMERIC AS montant, "
            ."b.donnees->>'compte' AS compte, "
            ."b.donnees->>'retard' AS retard, "
            ."b.donnees->>'dateecriture' AS dateecriture, "
            .'(SELECT MIN(p.date_promesse) FROM creances.promesse p '
            ."  WHERE p.ecriture_numero = b.donnees->>'numero' AND p.statut = 'en_cours') AS date_promesse, "
            .'(SELECT 1 FROM creances.dossier_ecriture de '
            .' JOIN creances.dossier d ON d.id = de.dossier_id '
            ." WHERE de.ecriture_numero = b.donnees->>'numero' AND d.statut = 'ouvert' "
            ." AND d.type IN ('contentieux','litige') LIMIT 1) AS bloque "
            .'FROM creances.v_balance_agee b '
            .'WHERE b.present_dans_sage';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql);

        $periodes = [
            'cette_semaine' => ['libelle' => 'Cette semaine', 'montant' => 0.0, 'nombre' => 0],
            'ce_mois' => ['libelle' => 'Ce mois', 'montant' => 0.0, 'nombre' => 0],
            'mois_prochain' => ['libelle' => 'Mois prochain', 'montant' => 0.0, 'nombre' => 0],
            'mois_suivant' => ['libelle' => 'Mois suivant', 'montant' => 0.0, 'nombre' => 0],
            'au_dela' => ['libelle' => 'Au-dela de 3 mois', 'montant' => 0.0, 'nombre' => 0],
        ];

        $totalPondere = 0.0;
        $incluses = 0;
        foreach ($rows as $row) {
            if (null !== $row['bloque']) {
                continue; // litige ou contentieux : exclu
            }
            $montant = (float) $row['montant'];
            if ($montant <= 0) {
                continue;
            }
            ++$incluses;

            // Date de prevision : promesse si presente, sinon echeance + retard moyen.
            if (null !== $row['date_promesse']) {
                $datePrev = new DateTimeImmutable((string) $row['date_promesse']);
            } else {
                $dateEcriture = isset($row['dateecriture']) && is_string($row['dateecriture'])
                    ? new DateTimeImmutable($row['dateecriture'])
                    : $now;
                $datePrev = $dateEcriture->modify('+'.(int) $retardMoyen.' days');
                if ($datePrev < $now) {
                    $datePrev = $now;
                }
            }

            // Ponderation legere : 1.0 pour les promesses, 0.95 pour le comportemental.
            $taux = null !== $row['date_promesse'] ? 1.0 : 0.95;
            $contribution = $montant * $taux;
            $totalPondere += $contribution;

            $dateStr = $datePrev->format('Y-m-d');
            if ($dateStr <= $finSemaine) {
                $periodes['cette_semaine']['montant'] += $contribution;
                ++$periodes['cette_semaine']['nombre'];
            } elseif ($dateStr <= $finMois) {
                $periodes['ce_mois']['montant'] += $contribution;
                ++$periodes['ce_mois']['nombre'];
            } elseif ($dateStr <= $finMoisProchain) {
                $periodes['mois_prochain']['montant'] += $contribution;
                ++$periodes['mois_prochain']['nombre'];
            } elseif ($dateStr <= $fin3Mois) {
                $periodes['mois_suivant']['montant'] += $contribution;
                ++$periodes['mois_suivant']['nombre'];
            } else {
                $periodes['au_dela']['montant'] += $contribution;
                ++$periodes['au_dela']['nombre'];
            }
        }

        return [
            'periodes' => $periodes,
            'total_pondere' => $totalPondere,
            'ecritures_incluses' => $incluses,
        ];
    }

    // ============================================================
    // Radar de risque
    // ============================================================

    /**
     * Renvoie 5 axes de risque sur l'echelle 0 (sain) → 10 (critique).
     *
     * @return array{risque: float, echu: float, promesses: float, litiges: float, dso: float}
     */
    public function radarRisque(): array
    {
        $i = $this->indicateurs->syntheseGlobale();

        // Axe 1 : risque base sur le score (10 - score).
        $risque = max(0.0, min(10.0, 10.0 - $i['score']));

        // Axe 2 : echu (part du montant echu dans l'encours total).
        $echu = $i['encours_total'] > 0
            ? min(10.0, ($i['encours_echu'] / $i['encours_total']) * 10.0)
            : 0.0;

        // Axe 3 : promesses echues / total promesses (cle nombre).
        $promesses = $this->ratioPromessesEchues();

        // Axe 4 : litiges (montant en litige / encours).
        $litiges = $this->ratioLitiges($i['encours_total']);

        // Axe 5 : DSO (ratio BPDSO/DSO).
        $dsoAxe = ($i['dso'] > 0)
            ? max(0.0, min(10.0, 10.0 - ($i['bpdso'] / $i['dso']) * 10.0))
            : 0.0;

        return [
            'risque' => round($risque, 1),
            'echu' => round($echu, 1),
            'promesses' => round($promesses, 1),
            'litiges' => round($litiges, 1),
            'dso' => round($dsoAxe, 1),
        ];
    }

    private function retardMoyenJours(): float
    {
        // Sur les ecritures reglees, delta entre dateecriture du debit et
        // dateecriture du credit. Approximation simple : on prend le retard
        // moyen pondere de la balance agee (deja calcule par
        // IndicateursService).
        $i = $this->indicateurs->syntheseGlobale();

        return $i['retard_moyen_jours'];
    }

    private function ratioPromessesEchues(): float
    {
        $sql = 'SELECT '
            ."COALESCE(SUM(CASE WHEN p.date_promesse < CURRENT_DATE AND p.statut = 'en_cours' THEN 1 ELSE 0 END), 0) AS echues, "
            .'COUNT(*) AS total '
            .'FROM creances.promesse p '
            ."WHERE p.statut IN ('en_cours','tenue','non_tenue')";
        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql) ?: [];
        $total = (int) ($row['total'] ?? 0);
        if (0 === $total) {
            return 0.0;
        }

        return min(10.0, ((int) ($row['echues'] ?? 0)) / $total * 10.0);
    }

    private function ratioLitiges(float $encoursTotal): float
    {
        if ($encoursTotal <= 0) {
            return 0.0;
        }
        $sql = 'SELECT COALESCE(SUM('
            .'CASE WHEN de.montant_partiel IS NOT NULL THEN de.montant_partiel '
            .'ELSE COALESCE(('
            ."  SELECT (b.donnees->>'Montant (valeur absolue)')::NUMERIC "
            .'  FROM creances.v_balance_agee b '
            ."  WHERE b.donnees->>'numero' = de.ecriture_numero LIMIT 1"
            .'), 0) END'
            .')::NUMERIC, 0) AS montant '
            .'FROM creances.dossier_ecriture de '
            .'JOIN creances.dossier d ON d.id = de.dossier_id '
            ."WHERE d.statut = 'ouvert' AND d.type = 'litige'";
        /** @var float|string|false $val */
        $val = $this->connection->fetchOne($sql);
        $montantLitige = (float) ($val ?: 0);

        return min(10.0, ($montantLitige / $encoursTotal) * 10.0);
    }
}
