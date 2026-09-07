<?php

declare(strict_types=1);

namespace App\Creances\Service;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Calcul des indicateurs de pilotage du poste client : DSO, BPDSO, retard
 * moyen, balance agee comparative, score compte / ecriture, encaissements
 * du mois, DMR / RMR.
 *
 * Tous les calculs sont en lecture seule sur `mirror.*` et nos tables
 * `creances.*` (litige, promesse). Aucune ecriture cote service.
 *
 * Definitions (alignees sur Gestion commerciale) :
 *  - Retard moyen sur debit : somme(jours_retard) / nb_ecritures, sur les
 *    ecritures echues a fin de periode et debitrices.
 *  - DSO (rollback) : on epuise le CA mensuel le plus recent jusqu'a couvrir
 *    l'encours total ; on additionne les jours correspondants.
 *  - BPDSO : meme calcul mais sur l'encours non echu uniquement.
 *  - DMR (delai moyen de reglement) : (date_reglement - date_facture).
 *  - RMR (retard moyen de reglement) : (date_reglement - date_echeance).
 *  - Score 0-10 : moyenne (ponderee par montant) des notes ecritures suivant
 *    le bareme Gestion commerciale (regle/non echu/echu/promesse/litige/contentieux).
 */
final class IndicateursService
{
    /**
     * Bareme score par statut metier d'ecriture (0-10). Calque Gestion commerciale.
     * NOTE_REGLE n'est pas utilisee directement dans la balance_agee (qui ne
     * contient que des ecritures non reglees) mais sera necessaire des qu'on
     * exposera l'historique complet via bal_eloficash.
     */
    private const NOTE_NON_ECHUE = 8;
    private const NOTE_PROMESSE_NON_ECHUE = 6;
    private const NOTE_ECHUE = 4;
    private const NOTE_LITIGE = 2;
    private const NOTE_PROMESSE_ECHUE = 2;
    private const NOTE_CONTENTIEUX = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    // ============================================================
    // Niveau global / portefeuille
    // ============================================================

    /**
     * Synthese du tableau de bord recouvrement : encaissement du mois,
     * encours, retard moyen, DSO, BPDSO. Filtres optionnels sur etablissement
     * et societe.
     *
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     *
     * @return array{
     *     encours_total: float,
     *     encours_non_echu: float,
     *     encours_echu: float,
     *     encaissement_mois: float,
     *     retard_moyen_jours: float,
     *     dso: float,
     *     bpdso: float,
     *     score: float,
     *     nb_comptes: int
     * }
     */
    public function syntheseGlobale(array $filtres = []): array
    {
        // Refacto 2026-06-02 : bascule sur creances.v_creances_ouvertes (vraie
        // source 44 941 lignes) au lieu de creances.v_balance_agee (sous-
        // ensemble 1 061 lignes). Les alias propres remplacent les acces JSONB.
        $where = '';
        $params = [];
        $types = [];
        if (!empty($filtres['etablissement'])) {
            $where .= ' AND codeetab IN (:etablissement)';
            $params['etablissement'] = $filtres['etablissement'];
            $types['etablissement'] = ArrayParameterType::STRING;
        }

        $sqlEncours = 'SELECT '
            .'COALESCE(SUM(montant_solde), 0) AS encours_total, '
            ."COALESCE(SUM(CASE WHEN retard = '<30' THEN montant_solde ELSE 0 END), 0) AS encours_non_echu, "
            .'COUNT(DISTINCT compte) AS nb_comptes '
            .'FROM creances.v_creances_ouvertes '
            ."WHERE 1=1 $where";

        /** @var array<string, mixed> $rowEncours */
        $rowEncours = $this->connection->fetchAssociative($sqlEncours, $params, $types) ?: [];
        $encoursTotal = (float) ($rowEncours['encours_total'] ?? 0);
        $encoursNonEchu = (float) ($rowEncours['encours_non_echu'] ?? 0);
        $encoursEchu = max(0.0, $encoursTotal - $encoursNonEchu);
        $nbComptes = (int) ($rowEncours['nb_comptes'] ?? 0);

        // Encaissement du mois (somme des credits dans bal_eloficash pour le mois courant).
        $encaissementMois = $this->encaissementDuMois($filtres);

        // Retard moyen jours (calcule a partir des dates d'echeance des
        // ecritures echues). On approxime sur la balance_agee : pour chaque
        // tranche, on prend le milieu de tranche pondere par le montant.
        $retardMoyen = $this->retardMoyenSurDebit($filtres);

        // DSO et BPDSO (rollback).
        [$dso, $bpdso] = $this->dsoEtBpdso($encoursTotal, $encoursNonEchu, $filtres);

        // Score global = moyenne des scores ecritures de la balance_agee
        // (ponderee par montant).
        $score = $this->scoreGlobal($filtres);

        return [
            'encours_total' => $encoursTotal,
            'encours_non_echu' => $encoursNonEchu,
            'encours_echu' => $encoursEchu,
            'encaissement_mois' => $encaissementMois,
            'retard_moyen_jours' => $retardMoyen,
            'dso' => $dso,
            'bpdso' => $bpdso,
            'score' => $score,
            'nb_comptes' => $nbComptes,
        ];
    }

    /**
     * Balance agee comparative actuelle vs M-3 / M-6 / M-12 / M-24. En V1, on
     * ne dispose que de l'instantane courant : les autres periodes seront
     * remplies des qu'on aura un job de snapshot quotidien.
     *
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     *
     * @return array{
     *     actuel: array<int|string, float>,
     *     m_3: array<int|string, float>,
     *     m_6: array<int|string, float>,
     *     m_12: array<int|string, float>,
     *     m_24: array<int|string, float>
     * }
     */
    public function balanceAgeeComparative(array $filtres = []): array
    {
        $actuel = $this->balanceAgee($filtres);

        // Placeholders pour V1 : structure pretes a recevoir les snapshots.
        $zeros = array_fill_keys(array_keys($actuel), 0.0);

        return [
            'actuel' => $actuel,
            'm_3' => $zeros,
            'm_6' => $zeros,
            'm_12' => $zeros,
            'm_24' => $zeros,
        ];
    }

    // ============================================================
    // Niveau compte
    // ============================================================

    /**
     * Indicateurs d'une fiche compte : encours, retard moyen, score, taux
     * d'echu.
     *
     * @return array{
     *     encours_total: float,
     *     encours_non_echu: float,
     *     encours_echu: float,
     *     taux_echu: float,
     *     retard_moyen_jours: float,
     *     score: float,
     *     nb_ecritures: int,
     *     pire_tranche: ?string
     * }
     */
    public function syntheseCompte(string $compteCode): array
    {
        $sql = 'SELECT '
            ."COALESCE(SUM((donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS encours_total, "
            ."COALESCE(SUM(CASE WHEN donnees->>'retard' = '<30' THEN (donnees->>'Montant (valeur absolue)')::NUMERIC ELSE 0 END), 0) AS encours_non_echu, "
            .'COUNT(*) AS nb_ecritures '
            .'FROM creances.v_balance_agee '
            ."WHERE present_dans_sage AND donnees->>'compte' = :code";

        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql, ['code' => $compteCode]) ?: [];
        $encoursTotal = (float) ($row['encours_total'] ?? 0);
        $encoursNonEchu = (float) ($row['encours_non_echu'] ?? 0);
        $encoursEchu = max(0.0, $encoursTotal - $encoursNonEchu);
        $nbEcritures = (int) ($row['nb_ecritures'] ?? 0);
        $tauxEchu = $encoursTotal > 0 ? ($encoursEchu / $encoursTotal) * 100 : 0.0;

        // Retard moyen et pire tranche : on parcourt les tranches du compte.
        $sqlTranches = "SELECT donnees->>'retard' AS tranche, "
            ."COALESCE(SUM((donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS montant "
            .'FROM creances.v_balance_agee '
            ."WHERE present_dans_sage AND donnees->>'compte' = :code "
            ."GROUP BY donnees->>'retard'";

        /** @var list<array{tranche: ?string, montant: string|float}> $tranchesRows */
        $tranchesRows = $this->connection->fetchAllAssociative($sqlTranches, ['code' => $compteCode]);

        $piresOrdre = ['>240' => 7, '>180' => 6, '>120' => 5, '>90' => 4, '>60' => 3, '>30' => 2, '<30' => 1];
        $milieux = ['>240' => 270.0, '>180' => 210.0, '>120' => 150.0, '>90' => 105.0, '>60' => 75.0, '>30' => 45.0, '<30' => 15.0];
        $pireTranche = null;
        $rangPire = -1;
        $sommeJours = 0.0;
        $sommeMontants = 0.0;
        foreach ($tranchesRows as $tr) {
            $tranche = (string) ($tr['tranche'] ?? '');
            $montant = (float) $tr['montant'];
            $rang = $piresOrdre[$tranche] ?? -1;
            if ($rang > $rangPire) {
                $rangPire = $rang;
                $pireTranche = $tranche;
            }
            $sommeJours += ($milieux[$tranche] ?? 0) * $montant;
            $sommeMontants += $montant;
        }
        $retardMoyen = $sommeMontants > 0 ? $sommeJours / $sommeMontants : 0.0;

        $score = $this->scoreCompte($compteCode);

        return [
            'encours_total' => $encoursTotal,
            'encours_non_echu' => $encoursNonEchu,
            'encours_echu' => $encoursEchu,
            'taux_echu' => $tauxEchu,
            'retard_moyen_jours' => $retardMoyen,
            'score' => $score,
            'nb_ecritures' => $nbEcritures,
            'pire_tranche' => $pireTranche,
        ];
    }

    /**
     * Score d'un compte (0-10) : moyenne ponderee par montant des notes
     * ecritures. Les ecritures dans un dossier litige ou contentieux ouvert
     * sont penalisees ; les ecritures avec promesse en_cours non echue sont
     * boostees.
     */
    public function scoreCompte(string $compteCode): float
    {
        return $this->scoreCompteOuGlobal(['code' => $compteCode]);
    }

    /**
     * Historique 12 mois d'un compte client : CA realise, encaissements, nb
     * factures, nb paiements + recurrence, plus gros et plus vieil impaye,
     * delai moyen de paiement estime (DSO du compte = encours / (CA quotidien
     * moyen 12M)).
     *
     * Sans lettrage Progiciel cote mirror, le delai n'est pas calculable par
     * appariement facture/paiement direct ; le DSO compte est l'approche
     * standard en recouvrement et reflete combien de jours de CA le client
     * doit en moyenne.
     *
     * @return array{
     *     ca_12m: float,
     *     encaissements_12m: float,
     *     nb_factures_12m: int,
     *     nb_paiements_12m: int,
     *     recurrence_paiement_jours: float,
     *     ratio_encaissement: float,
     *     delai_moyen_paiement_jours: float,
     *     plus_gros_impaye: float,
     *     plus_vieil_impaye_libelle: ?string,
     *     plus_vieil_impaye_jours: int
     * }
     */
    public function historiqueCompte(string $compteCode): array
    {
        $debut12m = (new DateTimeImmutable())->modify('-12 months')->format('Y-m-d');

        // CA realise (factures emises = debits) + encaissements (paiements =
        // credits) + nb factures + nb paiements + bornes dates des paiements
        // (pour calculer la recurrence), sur 12 mois glissants.
        $sql = 'SELECT '
            ."COALESCE(SUM(CASE WHEN (donnees->>'debit')::NUMERIC > 0 THEN (donnees->>'debit')::NUMERIC ELSE 0 END), 0) AS ca, "
            ."COALESCE(SUM(CASE WHEN (donnees->>'credit')::NUMERIC > 0 THEN (donnees->>'credit')::NUMERIC ELSE 0 END), 0) AS encaisse, "
            ."COUNT(CASE WHEN (donnees->>'debit')::NUMERIC > 0 THEN 1 END) AS nb_factures, "
            ."COUNT(CASE WHEN (donnees->>'credit')::NUMERIC > 0 THEN 1 END) AS nb_paiements, "
            ."MIN(CASE WHEN (donnees->>'credit')::NUMERIC > 0 THEN (donnees->>'dateecriture')::DATE END) AS premier_paiement, "
            ."MAX(CASE WHEN (donnees->>'credit')::NUMERIC > 0 THEN (donnees->>'dateecriture')::DATE END) AS dernier_paiement "
            .'FROM creances.v_bal_eloficash '
            ."WHERE present_dans_sage AND donnees->>'compte' = :code "
            ."AND (donnees->>'dateecriture')::DATE >= :debut";

        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql, [
            'code' => $compteCode,
            'debut' => $debut12m,
        ]) ?: [];

        $ca12m = (float) ($row['ca'] ?? 0);
        $encaisse12m = (float) ($row['encaisse'] ?? 0);
        $nbFactures = (int) ($row['nb_factures'] ?? 0);
        $nbPaiements = (int) ($row['nb_paiements'] ?? 0);
        $ratio = $ca12m > 0 ? min(100.0, ($encaisse12m / $ca12m) * 100) : 0.0;

        // Recurrence = nb jours moyens entre 2 paiements consecutifs (sur
        // l'interval observe). Necessite au moins 2 paiements pour avoir un
        // sens. Fallback 365/nb_paiements si une seule annee de donnees.
        $recurrence = 0.0;
        if ($nbPaiements >= 2 && null !== ($row['premier_paiement'] ?? null) && null !== ($row['dernier_paiement'] ?? null)) {
            $premier = new DateTimeImmutable((string) $row['premier_paiement']);
            $dernier = new DateTimeImmutable((string) $row['dernier_paiement']);
            $joursInterval = (float) $premier->diff($dernier)->days;
            $recurrence = $joursInterval > 0 ? $joursInterval / ($nbPaiements - 1) : 0.0;
        }

        // Encours total actuel (pour le DSO).
        $sqlEnc = "SELECT COALESCE(SUM((donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS enc, "
            ."COALESCE(MAX((donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS plus_gros "
            .'FROM creances.v_balance_agee '
            ."WHERE present_dans_sage AND donnees->>'compte' = :code";

        /** @var array<string, mixed> $rowEnc */
        $rowEnc = $this->connection->fetchAssociative($sqlEnc, ['code' => $compteCode]) ?: [];
        $encours = (float) ($rowEnc['enc'] ?? 0);
        $plusGrosImpaye = (float) ($rowEnc['plus_gros'] ?? 0);

        // DSO compte = encours / (CA quotidien moyen sur 12M). Plus le DSO
        // est haut, plus le client paie en retard.
        $caQuotidien = $ca12m / 365.0;
        $delaiMoyen = $caQuotidien > 0 ? $encours / $caQuotidien : 0.0;

        // Plus vieil impaye : on prend la pire tranche presente avec un
        // montant > 0.
        $sqlTranche = "SELECT donnees->>'retard' AS tranche "
            .'FROM creances.v_balance_agee '
            ."WHERE present_dans_sage AND donnees->>'compte' = :code "
            ."AND (donnees->>'Montant (valeur absolue)')::NUMERIC > 0 "
            ."AND donnees->>'retard' <> '<30'"
            ."ORDER BY CASE donnees->>'retard' "
            ."  WHEN '>240' THEN 7 WHEN '>180' THEN 6 WHEN '>120' THEN 5 WHEN '>90' THEN 4 "
            ."  WHEN '>60' THEN 3 WHEN '>30' THEN 2 WHEN '<30' THEN 1 ELSE 0 END DESC "
            .'LIMIT 1';
        /** @var array<string, mixed>|false $rowT */
        $rowT = $this->connection->fetchAssociative($sqlTranche, ['code' => $compteCode]);
        $pireTranche = $rowT ? (string) ($rowT['tranche'] ?? '') : null;
        $milieux = ['>240' => 270, '>180' => 210, '>120' => 150, '>90' => 105, '>60' => 75, '>30' => 45, '<30' => 15];
        $jours = null !== $pireTranche ? ($milieux[$pireTranche] ?? 0) : 0;

        return [
            'ca_12m' => $ca12m,
            'encaissements_12m' => $encaisse12m,
            'nb_factures_12m' => $nbFactures,
            'nb_paiements_12m' => $nbPaiements,
            'recurrence_paiement_jours' => $recurrence,
            'ratio_encaissement' => $ratio,
            'delai_moyen_paiement_jours' => $delaiMoyen,
            'plus_gros_impaye' => $plusGrosImpaye,
            'plus_vieil_impaye_libelle' => $pireTranche,
            'plus_vieil_impaye_jours' => $jours,
        ];
    }

    // ============================================================
    // Internes
    // ============================================================

    /**
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     *
     * @return array<int|string, float>
     */
    private function balanceAgee(array $filtres): array
    {
        $where = $this->whereMirror($filtres, 'b');
        $sql = "SELECT b.donnees->>'retard' AS tranche, "
            ."COALESCE(SUM((b.donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS montant "
            .'FROM creances.v_balance_agee b '
            ."WHERE b.present_dans_sage $where "
            ."GROUP BY b.donnees->>'retard'";

        /** @var list<array{tranche: ?string, montant: string|float}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $this->paramsMirror($filtres), $this->typesMirror($filtres));

        // Note : la cle '0' est convertie en int(0) par PHP, d'ou la
        // signature array<int|string, float>. C'est intentionnel pour rester
        // aligne sur les valeurs Progiciel exposees par creances.v_balance_agee.
        $bal = ['<30' => 0.0, '>30' => 0.0, '>60' => 0.0, '>90' => 0.0, '>120' => 0.0, '>180' => 0.0, '>240' => 0.0];
        foreach ($rows as $r) {
            $tranche = (string) ($r['tranche'] ?? '');
            if (array_key_exists($tranche, $bal)) {
                $bal[$tranche] = (float) $r['montant'];
            }
        }

        return $bal;
    }

    /**
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     */
    private function encaissementDuMois(array $filtres): float
    {
        // Premier jour du mois courant.
        $debutMois = new DateTimeImmutable('first day of this month');
        $where = $this->whereMirror($filtres, 'b');

        $sql = "SELECT COALESCE(SUM((b.donnees->>'credit')::NUMERIC), 0) AS total "
            .'FROM creances.v_bal_eloficash b '
            .'WHERE b.present_dans_sage '
            ."AND (b.donnees->>'credit')::NUMERIC > 0 "
            ."AND (b.donnees->>'dateecriture')::DATE >= :debut "
            .$where;

        $params = $this->paramsMirror($filtres);
        $params['debut'] = $debutMois->format('Y-m-d');

        /** @var float|string|false $val */
        $val = $this->connection->fetchOne($sql, $params, $this->typesMirror($filtres));

        return (float) ($val ?: 0);
    }

    /**
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     */
    private function retardMoyenSurDebit(array $filtres): float
    {
        $where = $this->whereMirror($filtres, 'b');
        // Approche : milieu de tranche * montant / somme(montants).
        $sql = 'SELECT '
            ."COALESCE(SUM(CASE b.donnees->>'retard' "
            ."  WHEN '>240' THEN 270 "
            ."  WHEN '>180' THEN 210 "
            ."  WHEN '>120' THEN 150 "
            ."  WHEN '>90' THEN 105 "
            ."  WHEN '>60' THEN 75 "
            ."  WHEN '>30' THEN 45 "
            ."  WHEN '<30' THEN 15 "
            .'  ELSE 0 '
            ."END * (b.donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS num, "
            ."COALESCE(SUM((b.donnees->>'Montant (valeur absolue)')::NUMERIC), 0) AS den "
            .'FROM creances.v_balance_agee b '
            ."WHERE b.present_dans_sage AND b.donnees->>'retard' <> '<30' $where";

        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql, $this->paramsMirror($filtres), $this->typesMirror($filtres)) ?: [];
        $num = (float) ($row['num'] ?? 0);
        $den = (float) ($row['den'] ?? 0);

        return $den > 0 ? $num / $den : 0.0;
    }

    /**
     * DSO (rollback) et BPDSO. Methode count-back : on epuise le CA mensuel
     * en remontant le temps jusqu'a couvrir l'encours.
     *
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     *
     * @return array{0: float, 1: float}
     */
    private function dsoEtBpdso(float $encoursTotal, float $encoursNonEchu, array $filtres): array
    {
        if ($encoursTotal <= 0) {
            return [0.0, 0.0];
        }

        // CA mensuel des 18 derniers mois (debits dans bal_eloficash).
        $sql = "SELECT TO_CHAR((donnees->>'dateecriture')::DATE, 'YYYY-MM') AS mois, "
            ."COALESCE(SUM((donnees->>'debit')::NUMERIC), 0) AS ca "
            .'FROM creances.v_bal_eloficash '
            .'WHERE present_dans_sage '
            ."AND (donnees->>'debit')::NUMERIC > 0 "
            ."AND (donnees->>'dateecriture')::DATE >= :debut "
            .$this->whereMirror($filtres, '') // sans alias
            .' GROUP BY mois '
            .'ORDER BY mois DESC';

        $debut = (new DateTimeImmutable())->modify('-18 months')->format('Y-m-01');
        $params = $this->paramsMirror($filtres);
        $params['debut'] = $debut;

        /** @var list<array{mois: string, ca: string|float}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $this->typesMirror($filtres));

        $dso = $this->rollbackDso($encoursTotal, $rows);
        $bpdso = $this->rollbackDso($encoursNonEchu, $rows);

        return [$dso, $bpdso];
    }

    /**
     * @param list<array{mois: string, ca: string|float}> $caParMois
     */
    private function rollbackDso(float $encours, array $caParMois): float
    {
        if ($encours <= 0) {
            return 0.0;
        }

        $reste = $encours;
        $jours = 0.0;
        $now = new DateTimeImmutable('today');

        foreach ($caParMois as $row) {
            $ca = (float) $row['ca'];
            if ($ca <= 0) {
                continue;
            }
            $premierJourMois = new DateTimeImmutable($row['mois'].'-01');
            $dernierJour = $premierJourMois->modify('last day of this month');
            $joursDuMois = (int) $dernierJour->format('d');
            $finPeriode = $dernierJour < $now ? $dernierJour : $now;
            $debutPeriode = $premierJourMois;
            $joursPeriode = max(1, (int) $debutPeriode->diff($finPeriode)->days + 1);

            if ($reste <= $ca) {
                $ratio = $reste / $ca;
                $jours += $ratio * $joursPeriode;
                $reste = 0;
                break;
            }
            $jours += $joursPeriode;
            $reste -= $ca;
        }

        // S'il reste de l'encours non couvert : majoration prudente (on
        // considere qu'il faudrait encore X mois au CA moyen).
        if ($reste > 0 && !empty($caParMois)) {
            $totalCa = array_sum(array_map(static fn ($r) => (float) $r['ca'], $caParMois));
            $caMoyen = $totalCa / count($caParMois);
            if ($caMoyen > 0) {
                $jours += ($reste / $caMoyen) * 30;
            }
        }

        return round($jours, 1);
    }

    /**
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     */
    private function scoreGlobal(array $filtres): float
    {
        return $this->scoreCompteOuGlobal($filtres);
    }

    /**
     * Calcul score (0-10) generique : moyenne ponderee par montant des notes
     * ecritures de la balance_agee, en tenant compte des dossiers et
     * promesses.
     *
     * @param array{code?: ?string, etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     */
    private function scoreCompteOuGlobal(array $filtres): float
    {
        $where = '';
        $params = [];
        if (!empty($filtres['code'])) {
            $where .= " AND b.donnees->>'compte' = :code";
            $params['code'] = $filtres['code'];
        }
        if (!empty($filtres['etablissement'])) {
            $where .= " AND b.donnees->>'codeetab' IN (:etab)";
            $params['etab'] = $filtres['etablissement'];
        }
        if (!empty($filtres['societe'])) {
            $where .= " AND b.donnees->>'codesoc' IN (:soc)";
            $params['soc'] = $filtres['societe'];
        }

        // Note initiale par tranche (non echu / echu) + montant.
        $sql = 'WITH e AS ('
            ."  SELECT b.donnees->>'numero' AS numero, "
            ."         b.donnees->>'compte' AS compte, "
            ."         (b.donnees->>'Montant (valeur absolue)')::NUMERIC AS montant, "
            ."         (CASE WHEN b.donnees->>'retard' = '<30' THEN (:n_non_echue)::NUMERIC ELSE (:n_echue)::NUMERIC END) AS note_base "
            .'  FROM creances.v_balance_agee b '
            ."  WHERE b.present_dans_sage $where"
            .'), '
            .'litige AS ('
            .'  SELECT DISTINCT de.ecriture_numero FROM creances.dossier_ecriture de '
            .'  JOIN creances.dossier d ON d.id = de.dossier_id '
            ."  WHERE d.statut = 'ouvert' AND d.type = 'litige'"
            .'), '
            .'contentieux AS ('
            .'  SELECT DISTINCT de.ecriture_numero FROM creances.dossier_ecriture de '
            .'  JOIN creances.dossier d ON d.id = de.dossier_id '
            ."  WHERE d.statut = 'ouvert' AND d.type = 'contentieux'"
            .'), '
            .'promesse_active AS ('
            .'  SELECT DISTINCT p.ecriture_numero, '
            .'         (CASE WHEN p.date_promesse >= CURRENT_DATE THEN (:n_promesse_non_echue)::NUMERIC ELSE (:n_promesse_echue)::NUMERIC END) AS note_promesse '
            .'  FROM creances.promesse p '
            ."  WHERE p.statut = 'en_cours'"
            .') '
            .'SELECT '
            .'COALESCE(SUM((CASE '
            .'  WHEN c.ecriture_numero IS NOT NULL THEN (:n_contentieux)::NUMERIC '
            .'  WHEN l.ecriture_numero IS NOT NULL THEN (:n_litige)::NUMERIC '
            .'  WHEN pa.ecriture_numero IS NOT NULL THEN pa.note_promesse::NUMERIC '
            .'  ELSE e.note_base::NUMERIC '
            .'END) * e.montant), 0) AS num, '
            .'COALESCE(SUM(e.montant), 0) AS den '
            .'FROM e '
            .'LEFT JOIN litige l ON l.ecriture_numero = e.numero '
            .'LEFT JOIN contentieux c ON c.ecriture_numero = e.numero '
            .'LEFT JOIN promesse_active pa ON pa.ecriture_numero = e.numero';

        $params['n_non_echue'] = self::NOTE_NON_ECHUE;
        $params['n_echue'] = self::NOTE_ECHUE;
        $params['n_litige'] = self::NOTE_LITIGE;
        $params['n_contentieux'] = self::NOTE_CONTENTIEUX;
        $params['n_promesse_non_echue'] = self::NOTE_PROMESSE_NON_ECHUE;
        $params['n_promesse_echue'] = self::NOTE_PROMESSE_ECHUE;

        $types = [];
        if (!empty($filtres['etablissement'])) {
            $types['etab'] = ArrayParameterType::STRING;
        }
        if (!empty($filtres['societe'])) {
            $types['soc'] = ArrayParameterType::STRING;
        }

        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql, $params, $types) ?: [];
        $num = (float) ($row['num'] ?? 0);
        $den = (float) ($row['den'] ?? 0);

        if ($den <= 0) {
            // Pas de creance ouverte = compte sain (10).
            return 10.0;
        }

        return round($num / $den, 1);
    }

    /**
     * Clause WHERE additionnelle (avec AND prefixe) pour filtrer la balance.
     * Renvoie chaine vide si pas de filtres.
     *
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     */
    private function whereMirror(array $filtres, string $alias): string
    {
        $where = '';
        $prefix = '' === $alias ? '' : $alias.'.';
        if (!empty($filtres['etablissement'])) {
            $where .= ' AND '.$prefix."donnees->>'codeetab' IN (:etab)";
        }
        if (!empty($filtres['societe'])) {
            $where .= ' AND '.$prefix."donnees->>'codesoc' IN (:soc)";
        }

        return $where;
    }

    /**
     * Types Doctrine pour les parametres array de paramsMirror (a passer en
     * 3e argument des fetchXXX, sinon PG recoit la valeur scalaire au lieu
     * d'un array expanse → erreur 22P02 "malformed array literal").
     *
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     *
     * @return array<string, ArrayParameterType::STRING>
     */
    private function typesMirror(array $filtres): array
    {
        $types = [];
        if (!empty($filtres['etablissement'])) {
            $types['etab'] = ArrayParameterType::STRING;
        }
        if (!empty($filtres['societe'])) {
            $types['soc'] = ArrayParameterType::STRING;
        }

        return $types;
    }

    /**
     * @param array{etablissement?: ?list<string>, societe?: ?list<string>} $filtres
     *
     * @return array<string, mixed>
     */
    private function paramsMirror(array $filtres): array
    {
        $params = [];
        if (!empty($filtres['etablissement'])) {
            $params['etab'] = $filtres['etablissement'];
        }
        if (!empty($filtres['societe'])) {
            $params['soc'] = $filtres['societe'];
        }

        return $params;
    }
}
