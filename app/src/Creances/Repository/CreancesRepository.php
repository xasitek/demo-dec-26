<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lecture du poste client a partir de la vue `creances.v_creances_ouvertes`.
 *
 * Audit 2026-06-02 : la vraie source des creances impayees est
 * `mirror.bal_eloficash` (44 941 factures clients impayees) et non
 * `mirror.balance_agee` (1 061 lignes, sous-ensemble seulement). Le module
 * lit la vue `creances.v_creances_ouvertes` qui filtre bal_eloficash sur
 * `Code type piece = 'FC'` avec solde > 0 et JOINT avec `tiers` pour
 * recuperer identite client (nom, prenom, civilite, email, telephone).
 *
 * La vue expose des **alias propres** (compte, nom, montant_solde, retard,
 * date_echeance, etc.) — plus besoin de `donnees->>'...'` dans le code.
 *
 * Tranches `retard` calculees cote vue depuis `Date d'echeance` :
 *   <30, >30, >60, >90, >120, >180, >240 (alignees sur Progiciel).
 *
 * Pertes connues vs ancienne balance_agee (informations qui n'existent
 * pas dans bal_eloficash, donc plus dans le module) :
 *   - nomvendeur / prenomvendeur (l'analyse par vendeur n'est plus possible)
 *   - nomsecretaire (l'analyse par secretaire n'est plus possible)
 *   - codesoc (le code societe est dans `Code entite` cote bal_eloficash)
 *
 * Voir docs/CREANCES_REFACTO_2026_06.md pour l'historique complet.
 *
 * @phpstan-type Filtres array{
 *     tranches?: ?list<string>,
 *     etablissement?: ?list<string>,
 *     marque?: ?list<string>,
 *     code?: ?string,
 *     texte?: ?string,
 *     montant_min?: ?float
 * }
 * @phpstan-type CreanceLigne array{
 *     ecriture_id: string,
 *     compte: string,
 *     nom: ?string,
 *     prenom: ?string,
 *     civilite: ?string,
 *     raison_sociale: ?string,
 *     email: ?string,
 *     telephone: ?string,
 *     montant_solde: ?float,
 *     retard: ?string,
 *     numpiece: ?string,
 *     reference_facture: ?string,
 *     numimmat: ?string,
 *     marque: ?string,
 *     codeetab: ?string,
 *     code_entite: ?string,
 *     date_echeance: ?string,
 *     date_piece: ?string,
 *     libelle: ?string
 * }
 */
final class CreancesRepository
{
    /**
     * Tranches d'anciennete Progiciel (telles que calculees par la vue depuis
     * `Date d'echeance`). Cle interne => valeur Progiciel stockee dans la colonne
     * `retard` de la vue.
     *
     * @var array<string, string>
     */
    public const TRANCHES = [
        'lt_30' => '<30',
        'gt_30' => '>30',
        'gt_60' => '>60',
        'gt_90' => '>90',
        'gt_120' => '>120',
        'gt_180' => '>180',
        'gt_240' => '>240',
    ];

    /**
     * Libelles d'affichage des tranches (ordre logique).
     *
     * @var array<string, string>
     */
    public const TRANCHES_LIBELLE = [
        'lt_30' => 'Moins de 30 jours',
        'gt_30' => 'Plus de 30 jours',
        'gt_60' => 'Plus de 60 jours',
        'gt_90' => 'Plus de 90 jours',
        'gt_120' => 'Plus de 120 jours',
        'gt_180' => 'Plus de 180 jours',
        'gt_240' => 'Plus de 240 jours',
    ];

    /**
     * Expression SQL d'ordre numerique des tranches (utilisee pour ORDER BY
     * retard et pour calculer le "pire" retard d'un compte).
     */
    private const ORDRE_TRANCHE_SQL = 'CASE retard '
        ."WHEN '>240' THEN 7 "
        ."WHEN '>180' THEN 6 "
        ."WHEN '>120' THEN 5 "
        ."WHEN '>90' THEN 4 "
        ."WHEN '>60' THEN 3 "
        ."WHEN '>30' THEN 2 "
        ."WHEN '<30' THEN 1 "
        .'ELSE 0 END';

    /**
     * Expression SQL pour estimer l'anciennete moyenne en jours par tranche.
     */
    private const MILIEU_TRANCHE_SQL = 'CASE retard '
        ."WHEN '>240' THEN 270 "
        ."WHEN '>180' THEN 210 "
        ."WHEN '>120' THEN 150 "
        ."WHEN '>90' THEN 105 "
        ."WHEN '>60' THEN 75 "
        ."WHEN '>30' THEN 45 "
        ."WHEN '<30' THEN 15 "
        .'ELSE 0 END';

    /**
     * Tris autorises sur la liste des creances. Cle publique => expression
     * SQL (anti-injection — uniquement des alias de colonnes de la vue).
     *
     * @var array<string, string>
     */
    private const TRIS = [
        'montant' => 'montant_solde',
        'retard' => 'ordre_tranche',
        'compte' => 'compte',
        'nom' => 'nom',
        'date' => 'date_piece',
        'marque' => 'marque',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    // ============================================================
    // Listing creances (creances.v_creances_ouvertes)
    // ============================================================

    /**
     * Page paginee de creances avec filtres et tri.
     *
     * @param Filtres $filtres
     *
     * @return list<array<string, mixed>>
     */
    public function pageCreances(int $page, int $parPage, array $filtres = [], string $tri = 'retard', string $sens = 'desc'): array
    {
        $offset = max(0, ($page - 1) * $parPage);
        $colonneTri = self::TRIS[$tri] ?? self::TRIS['retard'];
        $sensTri = 'asc' === strtolower($sens) ? 'ASC' : 'DESC';

        $sql = $this->sqlBase($filtres)
            .sprintf(' ORDER BY %s %s NULLS LAST, cle ASC LIMIT %d OFFSET %d', $colonneTri, $sensTri, $parPage, $offset);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $this->params($filtres), $this->types($filtres));

        return $rows;
    }

    /**
     * Compte total (pour calculer les pages).
     *
     * @param Filtres $filtres
     */
    public function compterCreances(array $filtres = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ('.$this->sqlBase($filtres).') sub';
        /** @var int|string|false $count */
        $count = $this->connection->fetchOne($sql, $this->params($filtres), $this->types($filtres));

        return (int) $count;
    }

    /**
     * Vue "Par client" : agrege les creances ouvertes par compte (Code
     * relance). 1 ligne = 1 client avec ses totaux. Memes filtres que
     * pageCreances.
     *
     * @param Filtres $filtres
     *
     * @return list<array{
     *     compte: string, nom: string, prenom: string,
     *     raison_sociale: ?string,
     *     encours: float, encours_echu: float, taux_echu: float,
     *     nb_creances: int, pire_tranche: ?string,
     *     anciennete_moyenne_jours: float
     * }>
     */
    public function pageComptes(int $page, int $parPage, array $filtres = [], string $tri = 'encours', string $sens = 'desc'): array
    {
        $offset = max(0, ($page - 1) * $parPage);
        $sensTri = 'asc' === strtolower($sens) ? 'ASC' : 'DESC';

        $colonneTri = match ($tri) {
            'compte' => 'compte',
            'nom' => 'nom',
            'retard' => 'pire_tranche_ordre',
            'montant' => 'encours',
            'date' => 'derniere_date',
            default => 'encours',
        };

        $base = $this->sqlBase($filtres);
        $sql = 'WITH src AS ('.$base.') '
            .'SELECT compte, '
            .'MAX(nom) AS nom, '
            .'MAX(prenom) AS prenom, '
            .'MAX(raison_sociale) AS raison_sociale, '
            .'COALESCE(SUM(montant_solde), 0) AS encours, '
            ."COALESCE(SUM(CASE WHEN retard <> '<30' THEN montant_solde ELSE 0 END), 0) AS encours_echu, "
            .'COUNT(*) AS nb_creances, '
            .'MAX('.self::ORDRE_TRANCHE_SQL.') AS pire_tranche_ordre, '
            .'AVG('.self::MILIEU_TRANCHE_SQL.') AS anciennete_moyenne_jours, '
            .'MAX(date_piece) AS derniere_date '
            .'FROM src '
            .'GROUP BY compte '
            .sprintf('ORDER BY %s %s NULLS LAST, compte ASC LIMIT %d OFFSET %d', $colonneTri, $sensTri, $parPage, $offset);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $this->params($filtres), $this->types($filtres));

        $libellesTranche = ['7' => '>240', '6' => '>180', '5' => '>120', '4' => '>90', '3' => '>60', '2' => '>30', '1' => '<30', '0' => null];

        return array_map(static function (array $r) use ($libellesTranche): array {
            $encours = (float) ($r['encours'] ?? 0);
            $encoursEchu = (float) ($r['encours_echu'] ?? 0);
            $ordrePire = (string) ($r['pire_tranche_ordre'] ?? '0');

            return [
                'compte' => (string) ($r['compte'] ?? ''),
                'nom' => (string) ($r['nom'] ?? ''),
                'prenom' => (string) ($r['prenom'] ?? ''),
                'raison_sociale' => null === ($r['raison_sociale'] ?? null) ? null : (string) $r['raison_sociale'],
                'encours' => $encours,
                'encours_echu' => $encoursEchu,
                'taux_echu' => $encours > 0 ? ($encoursEchu / $encours) * 100 : 0.0,
                'nb_creances' => (int) ($r['nb_creances'] ?? 0),
                'pire_tranche' => $libellesTranche[$ordrePire] ?? null,
                'anciennete_moyenne_jours' => (float) ($r['anciennete_moyenne_jours'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Compte le nombre de comptes distincts (pour la pagination de la vue
     * "par client").
     *
     * @param Filtres $filtres
     */
    public function compterComptes(array $filtres = []): int
    {
        $base = $this->sqlBase($filtres);
        $sql = 'SELECT COUNT(DISTINCT compte) FROM ('.$base.') sub';
        /** @var int|string|false $count */
        $count = $this->connection->fetchOne($sql, $this->params($filtres), $this->types($filtres));

        return (int) $count;
    }

    /**
     * Synthese cumulative sur les creances filtrees : nombre, encours total,
     * encours > 60j et > 180j (cibles recouvrement).
     *
     * @param Filtres $filtres
     *
     * @return array{nombre: int, encours_total: float, encours_grt_60j: float, encours_grt_180j: float, nb_comptes: int}
     */
    public function synthese(array $filtres = []): array
    {
        $sql = 'SELECT '
            .'COUNT(*) AS nombre, '
            .'COALESCE(SUM(montant_solde), 0) AS encours_total, '
            ."COALESCE(SUM(CASE WHEN retard IN ('>60','>90','>120','>180','>240') THEN montant_solde ELSE 0 END), 0) AS encours_grt_60j, "
            ."COALESCE(SUM(CASE WHEN retard IN ('>180','>240') THEN montant_solde ELSE 0 END), 0) AS encours_grt_180j, "
            .'COUNT(DISTINCT compte) AS nb_comptes '
            .'FROM ('.$this->sqlBase($filtres).') sub';

        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql, $this->params($filtres), $this->types($filtres)) ?: [];

        return [
            'nombre' => (int) ($row['nombre'] ?? 0),
            'encours_total' => (float) ($row['encours_total'] ?? 0),
            'encours_grt_60j' => (float) ($row['encours_grt_60j'] ?? 0),
            'encours_grt_180j' => (float) ($row['encours_grt_180j'] ?? 0),
            'nb_comptes' => (int) ($row['nb_comptes'] ?? 0),
        ];
    }

    /**
     * Iteration streamee (export). Memoire constante.
     *
     * @param Filtres $filtres
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterer(array $filtres = [], string $tri = 'retard', string $sens = 'desc'): iterable
    {
        $colonneTri = self::TRIS[$tri] ?? self::TRIS['retard'];
        $sensTri = 'asc' === strtolower($sens) ? 'ASC' : 'DESC';
        $sql = $this->sqlBase($filtres)
            .sprintf(' ORDER BY %s %s NULLS LAST, cle ASC', $colonneTri, $sensTri);

        return $this->connection->iterateAssociative($sql, $this->params($filtres), $this->types($filtres));
    }

    // ============================================================
    // Listes pour les filtres
    // ============================================================

    /**
     * Liste distincte des etablissements presents dans les creances ouvertes.
     *
     * @return list<string>
     */
    public function listerEtablissements(): array
    {
        /** @var list<array{etab: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT codeetab AS etab FROM creances.v_creances_ouvertes '
            .'WHERE codeetab IS NOT NULL AND codeetab <> \'\' '
            .'ORDER BY etab',
        );

        return array_map(static fn (array $r) => (string) $r['etab'], $rows);
    }

    /**
     * @return list<string>
     */
    public function listerMarques(): array
    {
        /** @var list<array{marque: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT marque FROM creances.v_creances_ouvertes '
            .'WHERE marque IS NOT NULL AND marque <> \'\' '
            .'ORDER BY marque',
        );

        return array_map(static fn (array $r) => (string) $r['marque'], $rows);
    }

    // ============================================================
    // Fiche tiers
    // ============================================================

    /**
     * Charge le tiers a partir de son code Progiciel. Renvoie null si introuvable.
     *
     * @return array<string, mixed>|null
     */
    public function tiers(string $compteCode): ?array
    {
        $sql = 'SELECT cle, donnees, present_dans_sage, vu_le, modifie_le '
            .'FROM creances.v_tiers '
            ."WHERE present_dans_sage AND donnees->>'code' = :code "
            .'LIMIT 1';

        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative($sql, ['code' => $compteCode]);

        return false === $row ? null : $row;
    }

    /**
     * Toutes les ecritures (bal_eloficash) d'un tiers (factures, paiements,
     * OD, lettrage). Tri par date pièce decroissante.
     *
     * @return list<array<string, mixed>>
     */
    public function ecrituresDuTiers(string $compteCode): array
    {
        // On cherche le tiers comme code_relance, code_facture OU code_payeur
        // pour couvrir les 3 roles possibles.
        $sql = 'SELECT cle, donnees '
            .'FROM creances.v_bal_eloficash '
            .'WHERE present_dans_sage AND ('
            ."donnees->>'Code relancé' = :code "
            ."OR donnees->>'Code facturé' = :code "
            ."OR donnees->>'Code payeur' = :code"
            .') '
            ."ORDER BY (donnees->>'Date de pièce')::DATE DESC NULLS LAST, cle ASC";

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['code' => $compteCode]);

        return $rows;
    }

    /**
     * Creances ouvertes d'un tiers.
     *
     * @return list<array<string, mixed>>
     */
    public function creancesOuvertesDuTiers(string $compteCode): array
    {
        $sql = 'SELECT * FROM creances.v_creances_ouvertes '
            .'WHERE compte = :code '
            .'ORDER BY date_piece DESC NULLS LAST, cle ASC';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['code' => $compteCode]);

        return $rows;
    }

    /**
     * Detail d'une ecriture par son identifiant Progiciel (`clé écriture`).
     * Recherche d'abord dans les creances ouvertes (FC impayee), sinon dans
     * l'historique complet bal_eloficash.
     *
     * @return array<string, mixed>|null
     */
    public function ecriture(string $numero): ?array
    {
        // Tentative 1 : la vue des creances ouvertes (donne directement les
        // alias propres + identite client jointe).
        $sql = 'SELECT * FROM creances.v_creances_ouvertes WHERE ecriture_id = :num LIMIT 1';
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative($sql, ['num' => $numero]);
        if (false !== $row) {
            return $row + ['source' => 'creances_ouvertes'];
        }

        // Tentative 2 : historique complet (factures soldees, paiements, OD).
        $sql = "SELECT cle, donnees, 'bal_eloficash' AS source "
            .'FROM creances.v_bal_eloficash '
            ."WHERE present_dans_sage AND donnees->>'clé écriture' = :num "
            .'LIMIT 1';
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative($sql, ['num' => $numero]);

        return false === $row ? null : $row;
    }

    // ============================================================
    // Top clients et analyses simples
    // ============================================================

    /**
     * Top N comptes par encours.
     *
     * @return list<array{compte: string, nom: string, prenom: ?string, codeetab: ?string, encours: float, nb_ecritures: int, pire_tranche: ?string}>
     */
    public function topComptesParEncours(int $limit = 10): array
    {
        $sql = 'SELECT compte, '
            .'MAX(nom) AS nom, '
            .'MAX(prenom) AS prenom, '
            .'MAX(codeetab) AS codeetab, '
            .'SUM(montant_solde) AS encours, '
            .'COUNT(*) AS nb_ecritures, '
            .'MAX('.self::ORDRE_TRANCHE_SQL.') AS pire_ordre '
            .'FROM creances.v_creances_ouvertes '
            .'GROUP BY compte '
            .'ORDER BY encours DESC '
            .'LIMIT :lim';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['lim' => $limit]);

        $libelles = ['7' => '>240', '6' => '>180', '5' => '>120', '4' => '>90', '3' => '>60', '2' => '>30', '1' => '<30', '0' => null];

        return array_map(static fn (array $r) => [
            'compte' => (string) ($r['compte'] ?? ''),
            'nom' => (string) ($r['nom'] ?? ''),
            'prenom' => null === ($r['prenom'] ?? null) ? null : (string) $r['prenom'],
            'codeetab' => null === ($r['codeetab'] ?? null) ? null : (string) $r['codeetab'],
            'encours' => (float) ($r['encours'] ?? 0),
            'nb_ecritures' => (int) ($r['nb_ecritures'] ?? 0),
            'pire_tranche' => $libelles[(string) ($r['pire_ordre'] ?? '0')] ?? null,
        ], $rows);
    }

    /**
     * Balance agee globale : montant par tranche d'anciennete.
     *
     * @return array{tranches: array<string, float>, total: float}
     */
    public function balanceAgeeGlobale(): array
    {
        $sql = 'SELECT retard, COALESCE(SUM(montant_solde), 0) AS total '
            .'FROM creances.v_creances_ouvertes '
            .'GROUP BY retard';

        /** @var list<array{retard: ?string, total: string|float}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql);

        $par_tranche = [];
        foreach (self::TRANCHES as $key => $sageValue) {
            $par_tranche[$key] = 0.0;
        }
        $total = 0.0;
        foreach ($rows as $r) {
            $cle = array_search($r['retard'], self::TRANCHES, true);
            if (false !== $cle) {
                $par_tranche[$cle] = (float) $r['total'];
            }
            $total += (float) $r['total'];
        }

        return [
            'tranches' => $par_tranche,
            'total' => $total,
        ];
    }

    // ============================================================
    // Construction du SQL filtree (interne)
    // ============================================================

    /**
     * @param Filtres $filtres
     */
    private function sqlBase(array $filtres): string
    {
        $sql = 'SELECT cle, donnees, ecriture_id, compte, code_facture, code_payeur, '
            .'nom, prenom, civilite, raison_sociale, email, telephone, portable, adresse, '
            .'numpiece, reference_facture, date_echeance, date_piece, retard, '
            .'montant_solde, montant_initial, montant_ht, '
            .'code_entite, codeetab, codejournal, marque, numimmat, numvin, '
            .'libelle, observation, collectif, type_compte, bloque, plafond_autorise, '
            .self::ORDRE_TRANCHE_SQL.' AS ordre_tranche '
            .'FROM creances.v_creances_ouvertes '
            .'WHERE 1=1';

        if (!empty($filtres['tranches'])) {
            $sql .= ' AND retard IN (:tranches)';
        }
        if (!empty($filtres['etablissement'])) {
            $sql .= ' AND codeetab IN (:etablissement)';
        }
        if (!empty($filtres['marque'])) {
            $sql .= ' AND marque IN (:marque)';
        }
        if (!empty($filtres['code'])) {
            $sql .= ' AND compte = :code';
        }
        if (!empty($filtres['texte'])) {
            $sql .= ' AND ('
                .'nom ILIKE :texte OR '
                .'prenom ILIKE :texte OR '
                .'raison_sociale ILIKE :texte OR '
                .'numpiece ILIKE :texte OR '
                .'reference_facture ILIKE :texte OR '
                .'numimmat ILIKE :texte'
                .')';
        }
        if (isset($filtres['montant_min']) && $filtres['montant_min'] > 0) {
            $sql .= ' AND montant_solde >= :montant_min';
        }

        return $sql;
    }

    /**
     * @param Filtres $filtres
     *
     * @return array<string, mixed>
     */
    private function params(array $filtres): array
    {
        $params = [];
        if (!empty($filtres['tranches'])) {
            $params['tranches'] = array_values(array_intersect_key(self::TRANCHES, array_flip($filtres['tranches'])));
        }
        if (!empty($filtres['etablissement'])) {
            $params['etablissement'] = $filtres['etablissement'];
        }
        if (!empty($filtres['marque'])) {
            $params['marque'] = $filtres['marque'];
        }
        if (!empty($filtres['code'])) {
            $params['code'] = $filtres['code'];
        }
        if (!empty($filtres['texte'])) {
            $params['texte'] = '%'.$filtres['texte'].'%';
        }
        if (isset($filtres['montant_min']) && $filtres['montant_min'] > 0) {
            $params['montant_min'] = $filtres['montant_min'];
        }

        return $params;
    }

    /**
     * @param Filtres $filtres
     *
     * @return array<string, ArrayParameterType>
     */
    private function types(array $filtres): array
    {
        $types = [];
        if (!empty($filtres['tranches'])) {
            $types['tranches'] = ArrayParameterType::STRING;
        }
        if (!empty($filtres['etablissement'])) {
            $types['etablissement'] = ArrayParameterType::STRING;
        }
        if (!empty($filtres['marque'])) {
            $types['marque'] = ArrayParameterType::STRING;
        }

        return $types;
    }

    // ============================================================
    // Agregats par etablissement (vendeur/secretaire perdus)
    // ============================================================

    /**
     * Repartition de l'encours par etablissement / site. Trie par encours
     * echu DECROISSANT pour faire remonter les sites les plus en difficulte.
     *
     * @return list<array{
     *     cle: string, libelle: string,
     *     encours: float, encours_echu: float, taux_echu: float,
     *     nb_creances: int, nb_comptes: int, anciennete_moyenne_jours: float,
     *     etablissements: list<string>
     * }>
     */
    public function repartitionParEtablissement(): array
    {
        $sql = 'SELECT codeetab AS cle, '
            .'COALESCE(SUM(montant_solde), 0) AS encours, '
            ."COALESCE(SUM(CASE WHEN retard <> '<30' THEN montant_solde ELSE 0 END), 0) AS encours_echu, "
            .'COUNT(*) AS nb_creances, '
            .'COUNT(DISTINCT compte) AS nb_comptes, '
            .'AVG('.self::MILIEU_TRANCHE_SQL.') AS anciennete_moyenne_jours '
            .'FROM creances.v_creances_ouvertes '
            .'WHERE codeetab IS NOT NULL '
            .'GROUP BY codeetab '
            .'ORDER BY encours_echu DESC';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql);

        return array_map(static function (array $r): array {
            $cle = (string) ($r['cle'] ?? '');
            $encours = (float) ($r['encours'] ?? 0);
            $encoursEchu = (float) ($r['encours_echu'] ?? 0);

            return [
                'cle' => $cle,
                'libelle' => $cle,
                'encours' => $encours,
                'encours_echu' => $encoursEchu,
                'taux_echu' => $encours > 0 ? ($encoursEchu / $encours) * 100 : 0.0,
                'nb_creances' => (int) ($r['nb_creances'] ?? 0),
                'nb_comptes' => (int) ($r['nb_comptes'] ?? 0),
                'anciennete_moyenne_jours' => (float) ($r['anciennete_moyenne_jours'] ?? 0),
                'etablissements' => [$cle],
            ];
        }, $rows);
    }

    /**
     * Detail d'un etablissement : synthese KPI + top creances + top comptes.
     * Utilise par le panneau slide-in de la page Analyses.
     *
     * @param 'etablissement' $axe
     *
     * @return array{
     *     axe: string,
     *     cle: string,
     *     synthese: array{encours: float, encours_echu: float, taux_echu: float, nb_creances: int, nb_comptes: int, anciennete_moyenne_jours: float},
     *     etablissements: list<string>,
     *     top_comptes: list<array{compte: string, nom: string, encours: float, encours_echu: float, nb_creances: int, pire_tranche: ?string}>,
     *     top_creances: list<array{compte: string, nom: string, numpiece: string, date_piece: ?string, marque: string, numimmat: string, montant: float, tranche: string, codeetab: string}>
     * }
     */
    public function detailResponsable(string $axe, string $cle): array
    {
        // Seul axe encore supporte : etablissement (vendeur/secretaire ont
        // disparu avec le passage de balance_agee a bal_eloficash).
        if ('etablissement' !== $axe) {
            return $this->detailVide($axe, $cle);
        }
        $params = ['cle' => $cle];

        // Synthese
        $sqlSynth = 'SELECT '
            .'COALESCE(SUM(montant_solde), 0) AS encours, '
            ."COALESCE(SUM(CASE WHEN retard <> '<30' THEN montant_solde ELSE 0 END), 0) AS encours_echu, "
            .'COUNT(*) AS nb_creances, '
            .'COUNT(DISTINCT compte) AS nb_comptes, '
            .'AVG('.self::MILIEU_TRANCHE_SQL.') AS anciennete_moyenne_jours '
            .'FROM creances.v_creances_ouvertes WHERE codeetab = :cle';
        /** @var array<string, mixed> $rowSynth */
        $rowSynth = $this->connection->fetchAssociative($sqlSynth, $params) ?: [];
        $encours = (float) ($rowSynth['encours'] ?? 0);
        $encoursEchu = (float) ($rowSynth['encours_echu'] ?? 0);

        // Top 10 comptes
        $sqlComptes = 'SELECT compte, '
            .'MAX(nom) AS nom, MAX(prenom) AS prenom, '
            .'COALESCE(SUM(montant_solde), 0) AS encours, '
            ."COALESCE(SUM(CASE WHEN retard <> '<30' THEN montant_solde ELSE 0 END), 0) AS encours_echu, "
            .'COUNT(*) AS nb_creances, '
            .'MAX('.self::ORDRE_TRANCHE_SQL.') AS pire_ordre '
            .'FROM creances.v_creances_ouvertes WHERE codeetab = :cle '
            .'GROUP BY compte ORDER BY encours_echu DESC, encours DESC LIMIT 10';
        /** @var list<array<string, mixed>> $comptesRows */
        $comptesRows = $this->connection->fetchAllAssociative($sqlComptes, $params);
        $libelleTranche = ['7' => '>240', '6' => '>180', '5' => '>120', '4' => '>90', '3' => '>60', '2' => '>30', '1' => '<30', '0' => null];
        $topComptes = array_map(static function (array $r) use ($libelleTranche): array {
            $ordre = (string) ($r['pire_ordre'] ?? '0');
            $nomComplet = trim(((string) ($r['prenom'] ?? '')).' '.((string) ($r['nom'] ?? '')));

            return [
                'compte' => (string) ($r['compte'] ?? ''),
                'nom' => $nomComplet ?: (string) ($r['nom'] ?? ''),
                'encours' => (float) ($r['encours'] ?? 0),
                'encours_echu' => (float) ($r['encours_echu'] ?? 0),
                'nb_creances' => (int) ($r['nb_creances'] ?? 0),
                'pire_tranche' => $libelleTranche[$ordre] ?? null,
            ];
        }, $comptesRows);

        // Top 20 creances
        $sqlCreances = 'SELECT compte, nom, prenom, numpiece, date_piece, '
            .'marque, numimmat, montant_solde AS montant, retard AS tranche, codeetab, '
            .self::ORDRE_TRANCHE_SQL.' AS ordre_tranche '
            .'FROM creances.v_creances_ouvertes WHERE codeetab = :cle '
            .'ORDER BY ordre_tranche DESC, montant_solde DESC LIMIT 20';
        /** @var list<array<string, mixed>> $creancesRows */
        $creancesRows = $this->connection->fetchAllAssociative($sqlCreances, $params);
        $topCreances = array_map(static function (array $r): array {
            $nomComplet = trim(((string) ($r['prenom'] ?? '')).' '.((string) ($r['nom'] ?? '')));

            return [
                'compte' => (string) ($r['compte'] ?? ''),
                'nom' => $nomComplet ?: (string) ($r['nom'] ?? ''),
                'numpiece' => (string) ($r['numpiece'] ?? ''),
                'date_piece' => isset($r['date_piece']) ? (string) $r['date_piece'] : null,
                'marque' => (string) ($r['marque'] ?? ''),
                'numimmat' => (string) ($r['numimmat'] ?? ''),
                'montant' => (float) ($r['montant'] ?? 0),
                'tranche' => (string) ($r['tranche'] ?? ''),
                'codeetab' => (string) ($r['codeetab'] ?? ''),
            ];
        }, $creancesRows);

        return [
            'axe' => $axe,
            'cle' => $cle,
            'synthese' => [
                'encours' => $encours,
                'encours_echu' => $encoursEchu,
                'taux_echu' => $encours > 0 ? ($encoursEchu / $encours) * 100 : 0.0,
                'nb_creances' => (int) ($rowSynth['nb_creances'] ?? 0),
                'nb_comptes' => (int) ($rowSynth['nb_comptes'] ?? 0),
                'anciennete_moyenne_jours' => (float) ($rowSynth['anciennete_moyenne_jours'] ?? 0),
            ],
            'etablissements' => [$cle],
            'top_comptes' => $topComptes,
            'top_creances' => $topCreances,
        ];
    }

    /**
     * @return array{
     *     axe: string,
     *     cle: string,
     *     synthese: array{encours: float, encours_echu: float, taux_echu: float, nb_creances: int, nb_comptes: int, anciennete_moyenne_jours: float},
     *     etablissements: list<string>,
     *     top_comptes: list<array{compte: string, nom: string, encours: float, encours_echu: float, nb_creances: int, pire_tranche: ?string}>,
     *     top_creances: list<array{compte: string, nom: string, numpiece: string, date_piece: ?string, marque: string, numimmat: string, montant: float, tranche: string, codeetab: string}>
     * }
     */
    private function detailVide(string $axe, string $cle): array
    {
        return [
            'axe' => $axe,
            'cle' => $cle,
            'synthese' => [
                'encours' => 0.0, 'encours_echu' => 0.0, 'taux_echu' => 0.0,
                'nb_creances' => 0, 'nb_comptes' => 0, 'anciennete_moyenne_jours' => 0.0,
            ],
            'etablissements' => [],
            'top_comptes' => [],
            'top_creances' => [],
        ];
    }
}
