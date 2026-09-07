<?php

declare(strict_types=1);

namespace App\Creances\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Exception;

/**
 * Score de criticite algorithmique + detection des comptes inactifs.
 *
 * Le score criticite (0-100) combine trois facteurs ponderes :
 *
 *   criticite = 0.45 × poids_montant + 0.35 × poids_anciennete + 0.20 × poids_incidents
 *
 * Chaque facteur est normalise sur 0-100 a partir des quantiles du
 * portefeuille (un encours dans le top 5% des comptes vaut 100, la
 * mediane vaut 50, etc.) ce qui rend le score auto-adapte au portefeuille
 * sans seuils arbitraires.
 *
 * "Inactif" = aucune trace dans creances.note, creances.action,
 * creances.promesse, creances.relance_envoi ou creances.dossier depuis
 * N jours (15 par defaut), pour un compte qui a encore au moins une
 * creance ouverte.
 *
 * Toutes les requetes sont en DBAL pour rester performant a gros volume.
 */
final class PriorisationService
{
    public const SEUIL_INACTIVITE_JOURS = 15;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Top N comptes par score de criticite. Renvoie le code tiers, le
     * score, les composantes et les infos client pour affichage direct.
     *
     * @return list<array{
     *     compte: string,
     *     nom: string,
     *     prenom: ?string,
     *     codeetab: ?string,
     *     encours: float,
     *     anciennete_max_jours: int,
     *     nb_incidents: int,
     *     score_criticite: float,
     *     poids_montant: float,
     *     poids_anciennete: float,
     *     poids_incidents: float
     * }>
     */
    public function topCriticite(int $limit = 20): array
    {
        $sql = $this->sqlScoreCriticite()
            .' ORDER BY score_criticite DESC, encours DESC LIMIT :lim';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['lim' => $limit]);

        return $this->mapperLignes($rows);
    }

    /**
     * Score criticite d'un compte specifique (renvoie les 3 composantes).
     *
     * @return array{
     *     score_criticite: float,
     *     poids_montant: float,
     *     poids_anciennete: float,
     *     poids_incidents: float,
     *     encours: float,
     *     anciennete_max_jours: int,
     *     nb_incidents: int,
     *     rang: int,
     *     total_comptes: int
     * }|null
     */
    public function scoreCompte(string $compteCode): ?array
    {
        $sql = 'WITH base AS ('.$this->sqlScoreBase().') '
            .'SELECT *, RANK() OVER (ORDER BY score_criticite DESC) AS rang, '
            .'COUNT(*) OVER () AS total_comptes '
            .'FROM base '
            .'WHERE compte = :code';

        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative($sql, ['code' => $compteCode]);
        if (false === $row) {
            return null;
        }

        return [
            'score_criticite' => (float) $row['score_criticite'],
            'poids_montant' => (float) $row['poids_montant'],
            'poids_anciennete' => (float) $row['poids_anciennete'],
            'poids_incidents' => (float) $row['poids_incidents'],
            'encours' => (float) $row['encours'],
            'anciennete_max_jours' => (int) $row['anciennete_max_jours'],
            'nb_incidents' => (int) $row['nb_incidents'],
            'rang' => (int) $row['rang'],
            'total_comptes' => (int) $row['total_comptes'],
        ];
    }

    /**
     * Comptes sans activite (note, action, promesse, dossier, relance envoyee)
     * depuis N jours, qui ont encore une creance ouverte.
     *
     * @return list<array{
     *     compte: string,
     *     nom: string,
     *     prenom: ?string,
     *     codeetab: ?string,
     *     encours: float,
     *     derniere_activite: ?string,
     *     jours_inactivite: ?int,
     *     score_criticite: float
     * }>
     */
    public function comptesInactifs(int $jours = self::SEUIL_INACTIVITE_JOURS, int $limit = 50): array
    {
        // Optimisation : au lieu de 5 sous-requetes scalaires correlees par
        // ligne (catastrophe a 10k+ comptes), on agrege une seule fois par
        // table via UNION ALL puis GROUP BY. Chaque source contribue son
        // timestamp max — Postgres utilise les index (compte_code, *_le)
        // pour les MAX et tres peu de pages.
        $sql = 'WITH base AS ('.$this->sqlScoreBase().'), '
            .'activite AS ('
            .'  SELECT compte_code, MAX(ts) AS derniere FROM ('
            .'    SELECT compte_code, cree_le AS ts FROM creances.note '
            .'    UNION ALL SELECT compte_code, modifie_le FROM creances.action '
            .'    UNION ALL SELECT compte_code, modifie_le FROM creances.promesse '
            .'    UNION ALL SELECT compte_code, modifie_le FROM creances.dossier '
            .'    UNION ALL SELECT compte_code, envoye_le FROM creances.relance_envoi WHERE envoye_le IS NOT NULL '
            .'  ) u GROUP BY compte_code'
            .') '
            .'SELECT b.compte, b.nom, b.prenom, b.codeetab, b.encours, b.score_criticite, '
            .'  a.derniere AS derniere_activite, '
            .'  CASE WHEN a.derniere IS NOT NULL '
            .'    THEN EXTRACT(DAY FROM (CURRENT_TIMESTAMP - a.derniere))::INT '
            .'    ELSE NULL END AS jours_inactivite '
            .'FROM base b '
            .'LEFT JOIN activite a ON a.compte_code = b.compte '
            .'WHERE b.encours > 0 '
            ."AND (a.derniere IS NULL OR a.derniere < (CURRENT_TIMESTAMP - INTERVAL '".(int) $jours." days')) "
            .'ORDER BY b.score_criticite DESC, b.encours DESC LIMIT :lim';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['lim' => $limit]);

        return array_map(static fn (array $r) => [
            'compte' => (string) ($r['compte'] ?? ''),
            'nom' => (string) ($r['nom'] ?? ''),
            'prenom' => null === ($r['prenom'] ?? null) ? null : (string) $r['prenom'],
            'codeetab' => null === ($r['codeetab'] ?? null) ? null : (string) $r['codeetab'],
            'encours' => (float) ($r['encours'] ?? 0),
            'derniere_activite' => null === ($r['derniere_activite'] ?? null) ? null : (string) $r['derniere_activite'],
            'jours_inactivite' => null === ($r['jours_inactivite'] ?? null) ? null : (int) $r['jours_inactivite'],
            'score_criticite' => (float) ($r['score_criticite'] ?? 0),
        ], $rows);
    }

    /**
     * Indicateurs d'intensite de relance pour un compte : combien de
     * relances envoyees, sur quelle periode, et verdict "suffisamment
     * relance ou neglige".
     *
     * @return array{
     *     nb_relances_30j: int,
     *     nb_relances_90j: int,
     *     nb_actions_30j: int,
     *     nb_actions_90j: int,
     *     derniere_relance: ?string,
     *     jours_depuis_derniere_relance: ?int,
     *     frequence_moyenne_jours: ?float,
     *     verdict: string,
     *     verdict_label: string,
     *     verdict_couleur: string
     * }
     */
    public function intensiteRelance(string $compteCode): array
    {
        // 2 passes au lieu de 5 sous-requetes scalaires : une sur
        // relance_envoi, une sur action. Couvert par les index composites
        // (compte_code, envoye_le) et (compte_code, cree_le).
        $sqlRel = 'SELECT '
            ."COUNT(*) FILTER (WHERE envoye_le > CURRENT_TIMESTAMP - INTERVAL '30 days') AS rel30, "
            ."COUNT(*) FILTER (WHERE envoye_le > CURRENT_TIMESTAMP - INTERVAL '90 days') AS rel90, "
            .'MAX(envoye_le) AS derniere_relance '
            .'FROM creances.relance_envoi WHERE compte_code = :code AND envoye_le IS NOT NULL';
        /** @var array<string, mixed> $rRel */
        $rRel = $this->connection->fetchAssociative($sqlRel, ['code' => $compteCode]) ?: [];

        $sqlAct = 'SELECT '
            ."COUNT(*) FILTER (WHERE cree_le > CURRENT_TIMESTAMP - INTERVAL '30 days') AS act30, "
            ."COUNT(*) FILTER (WHERE cree_le > CURRENT_TIMESTAMP - INTERVAL '90 days') AS act90 "
            .'FROM creances.action WHERE compte_code = :code';
        /** @var array<string, mixed> $rAct */
        $rAct = $this->connection->fetchAssociative($sqlAct, ['code' => $compteCode]) ?: [];

        $row = $rRel + $rAct;

        $rel30 = (int) ($row['rel30'] ?? 0);
        $rel90 = (int) ($row['rel90'] ?? 0);
        $act30 = (int) ($row['act30'] ?? 0);
        $act90 = (int) ($row['act90'] ?? 0);
        $derniereRelance = isset($row['derniere_relance']) && is_string($row['derniere_relance']) ? $row['derniere_relance'] : null;

        $jours = null;
        if (null !== $derniereRelance) {
            try {
                $jours = (int) (new DateTimeImmutable())->diff(new DateTimeImmutable($derniereRelance))->days;
            } catch (Exception) {
                $jours = null;
            }
        }

        // Frequence moyenne entre relances (sur 180 derniers jours).
        $sqlFreq = 'SELECT AVG(EXTRACT(EPOCH FROM (envoye_le - prev_envoye_le)) / 86400) AS freq '
            .'FROM ('
            .'  SELECT envoye_le, LAG(envoye_le) OVER (ORDER BY envoye_le) AS prev_envoye_le '
            .'  FROM creances.relance_envoi '
            .'  WHERE compte_code = :code AND envoye_le IS NOT NULL '
            ."  AND envoye_le > CURRENT_TIMESTAMP - INTERVAL '180 days'"
            .') sub WHERE prev_envoye_le IS NOT NULL';
        /** @var float|string|false|null $freq */
        $freq = $this->connection->fetchOne($sqlFreq, ['code' => $compteCode]);
        $frequence = (false === $freq || null === $freq) ? null : (float) $freq;

        // Verdict :
        //  - 'jamais'    : jamais relance
        //  - 'neglige'   : pas de relance depuis > 45j
        //  - 'a_relancer': pas de relance depuis 15-45j
        //  - 'suffisant' : relance recente < 15j
        $verdict = 'jamais';
        if (null !== $jours) {
            if ($jours <= 15) {
                $verdict = 'suffisant';
            } elseif ($jours <= 45) {
                $verdict = 'a_relancer';
            } else {
                $verdict = 'neglige';
            }
        }
        $verdictMeta = match ($verdict) {
            'suffisant' => ['label' => 'Suivi suffisant', 'couleur' => 'emerald'],
            'a_relancer' => ['label' => 'A relancer bientot', 'couleur' => 'amber'],
            'neglige' => ['label' => 'Neglige', 'couleur' => 'rose'],
            default => ['label' => 'Jamais relance', 'couleur' => 'slate'],
        };

        return [
            'nb_relances_30j' => $rel30,
            'nb_relances_90j' => $rel90,
            'nb_actions_30j' => $act30,
            'nb_actions_90j' => $act90,
            'derniere_relance' => $derniereRelance,
            'jours_depuis_derniere_relance' => $jours,
            'frequence_moyenne_jours' => $frequence,
            'verdict' => $verdict,
            'verdict_label' => $verdictMeta['label'],
            'verdict_couleur' => $verdictMeta['couleur'],
        ];
    }

    /**
     * Date de la derniere activite sur un compte. Renvoie null si jamais
     * touche.
     */
    public function derniereActivite(string $compteCode): ?DateTimeImmutable
    {
        // UNION ALL + MAX au lieu de 5 sous-requetes : Postgres prend chaque
        // index (compte_code, *_le) une fois, sans plan correlé.
        $sql = 'SELECT MAX(ts) FROM ('
            .'  SELECT cree_le AS ts FROM creances.note WHERE compte_code = :code '
            .'  UNION ALL SELECT modifie_le FROM creances.action WHERE compte_code = :code '
            .'  UNION ALL SELECT modifie_le FROM creances.promesse WHERE compte_code = :code '
            .'  UNION ALL SELECT modifie_le FROM creances.dossier WHERE compte_code = :code '
            .'  UNION ALL SELECT envoye_le FROM creances.relance_envoi WHERE compte_code = :code AND envoye_le IS NOT NULL '
            .') sub';

        /** @var string|false|null $val */
        $val = $this->connection->fetchOne($sql, ['code' => $compteCode]);
        if (false === $val || null === $val || '' === $val) {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $val);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Combien de comptes sont inactifs depuis le seuil par defaut.
     */
    public function compterInactifs(int $jours = self::SEUIL_INACTIVITE_JOURS): int
    {
        // Meme strategie d'optimisation que comptesInactifs.
        $sql = 'WITH base AS ('.$this->sqlScoreBase().'), '
            .'activite AS ('
            .'  SELECT compte_code, MAX(ts) AS derniere FROM ('
            .'    SELECT compte_code, cree_le AS ts FROM creances.note '
            .'    UNION ALL SELECT compte_code, modifie_le FROM creances.action '
            .'    UNION ALL SELECT compte_code, modifie_le FROM creances.promesse '
            .'    UNION ALL SELECT compte_code, modifie_le FROM creances.dossier '
            .'    UNION ALL SELECT compte_code, envoye_le FROM creances.relance_envoi WHERE envoye_le IS NOT NULL '
            .'  ) u GROUP BY compte_code'
            .') '
            .'SELECT COUNT(*) FROM base b '
            .'LEFT JOIN activite a ON a.compte_code = b.compte '
            .'WHERE b.encours > 0 '
            ."AND (a.derniere IS NULL OR a.derniere < (CURRENT_TIMESTAMP - INTERVAL '".(int) $jours." days'))";

        /** @var int|string|false $count */
        $count = $this->connection->fetchOne($sql);

        return (int) $count;
    }

    // ============================================================
    // Construction du score (interne)
    // ============================================================

    private function sqlScoreBase(): string
    {
        // Aggregation par compte + composantes normalisees via PERCENT_RANK
        // (resilient aux outliers, auto-adapte au portefeuille).
        return 'WITH agg AS ('
            ."  SELECT b.donnees->>'compte' AS compte, "
            ."    MAX(b.donnees->>'nom') AS nom, "
            ."    MAX(b.donnees->>'prenom') AS prenom, "
            ."    MAX(b.donnees->>'codeetab') AS codeetab, "
            ."    SUM((b.donnees->>'Montant (valeur absolue)')::NUMERIC) AS encours, "
            ."    MAX(CASE b.donnees->>'retard' "
            ."      WHEN '>240' THEN 270 WHEN '>180' THEN 210 WHEN '>120' THEN 150 WHEN '>90' THEN 105 "
            ."      WHEN '>60' THEN 75 WHEN '>30' THEN 45 WHEN '<30' THEN 15 ELSE 0 END) AS anciennete_max_jours, "
            ."    SUM(CASE WHEN b.donnees->>'retard' IN ('>240','>180','>120','>90') THEN 1 ELSE 0 END) AS nb_incidents "
            .'  FROM creances.v_balance_agee b '
            .'  WHERE b.present_dans_sage '
            ."  GROUP BY b.donnees->>'compte'"
            .') '
            .'SELECT compte, nom, prenom, codeetab, encours, anciennete_max_jours, nb_incidents, '
            .'  ROUND((PERCENT_RANK() OVER (ORDER BY encours))::NUMERIC * 100, 1) AS poids_montant, '
            .'  ROUND((PERCENT_RANK() OVER (ORDER BY anciennete_max_jours))::NUMERIC * 100, 1) AS poids_anciennete, '
            .'  ROUND((PERCENT_RANK() OVER (ORDER BY nb_incidents))::NUMERIC * 100, 1) AS poids_incidents, '
            .'  ROUND(('
            .'    0.45 * PERCENT_RANK() OVER (ORDER BY encours) '
            .'    + 0.35 * PERCENT_RANK() OVER (ORDER BY anciennete_max_jours) '
            .'    + 0.20 * PERCENT_RANK() OVER (ORDER BY nb_incidents) '
            .'  )::NUMERIC * 100, 1) AS score_criticite '
            .'FROM agg';
    }

    private function sqlScoreCriticite(): string
    {
        return 'SELECT * FROM ('.$this->sqlScoreBase().') sub';
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{compte: string, nom: string, prenom: ?string, codeetab: ?string, encours: float, anciennete_max_jours: int, nb_incidents: int, score_criticite: float, poids_montant: float, poids_anciennete: float, poids_incidents: float}>
     */
    private function mapperLignes(array $rows): array
    {
        return array_map(static fn (array $r) => [
            'compte' => (string) ($r['compte'] ?? ''),
            'nom' => (string) ($r['nom'] ?? ''),
            'prenom' => null === ($r['prenom'] ?? null) ? null : (string) $r['prenom'],
            'codeetab' => null === ($r['codeetab'] ?? null) ? null : (string) $r['codeetab'],
            'encours' => (float) ($r['encours'] ?? 0),
            'anciennete_max_jours' => (int) ($r['anciennete_max_jours'] ?? 0),
            'nb_incidents' => (int) ($r['nb_incidents'] ?? 0),
            'score_criticite' => (float) ($r['score_criticite'] ?? 0),
            'poids_montant' => (float) ($r['poids_montant'] ?? 0),
            'poids_anciennete' => (float) ($r['poids_anciennete'] ?? 0),
            'poids_incidents' => (float) ($r['poids_incidents'] ?? 0),
        ], $rows);
    }
}
