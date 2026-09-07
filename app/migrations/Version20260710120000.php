<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : corrige la LENTEUR du refresh de v_impayes.
 *
 * La jointure tiers en `LEFT JOIN LATERAL (... LIMIT 1)` forçait un nested-loop
 * (une sous-requete par ligne, ~45 000 fois) -> REFRESH en plusieurs MINUTES,
 * voire bloque. On remplace par une **dedup des tiers en CTE (DISTINCT ON code)**
 * puis un `LEFT JOIN` simple (hash join) : une passe sur tiers + une jointure,
 * O(n+m) -> refresh en quelques secondes. Unicite de `cle` toujours garantie
 * (un seul tiers par code grace au DISTINCT ON). Meme colonnes que la vue
 * precedente (sans `donnees`).
 */
final class Version20260710120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : refresh v_impayes rapide (dedup tiers en CTE + hash join au lieu de LATERAL)';
    }

    public function up(Schema $schema): void
    {
        $this->recreer(false);
    }

    public function down(Schema $schema): void
    {
        // Revert : rétablit la jointure LATERAL (état de Version20260710100000).
        $this->recreer(true);
    }

    private function recreer(bool $lateral): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS recouvrement.v_impayes');
        $this->addSql('CREATE MATERIALIZED VIEW recouvrement.v_impayes AS '.self::selectImpayes($lateral).' WITH DATA');
        $this->addSql('CREATE UNIQUE INDEX uniq_impayes_cle ON recouvrement.v_impayes (cle)');
        $this->addSql('CREATE INDEX idx_impayes_compte ON recouvrement.v_impayes (compte)');
        $this->addSql('CREATE INDEX idx_impayes_ecriture ON recouvrement.v_impayes (ecriture_id)');
        $this->addSql('CREATE INDEX idx_impayes_email ON recouvrement.v_impayes (lower(btrim(email)))');
    }

    private static function selectImpayes(bool $lateral): string
    {
        // src : les factures FC 411 a solde non nul (CTE de base).
        $src = <<<'SQL'
            WITH src AS (
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM mirror.bal_eloficash
                WHERE present_dans_sage
                  AND donnees->>'Code type pièce' = 'FC'
                  AND LEFT(donnees->>'collectif', 3) = '411'
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
            )
            SQL;

        // En mode rapide : une CTE tiers dédupliquée (1 ligne par code).
        $cteTiers = $lateral ? '' : <<<'SQL'
            , tiers_uniq AS (
                SELECT DISTINCT ON (donnees->>'code') donnees->>'code' AS code, donnees
                FROM mirror.tiers
                WHERE present_dans_sage
                ORDER BY donnees->>'code', cle
            )
            SQL;

        // Liste des colonnes (identique aux 2 modes ; t.donnees vient de la CTE ou du LATERAL).
        $colonnes = <<<'SQL'
            SELECT
                src.cle,
                src.present_dans_sage,
                src.cree_le, src.vu_le, src.modifie_le,
                (src.donnees->>'clé écriture') AS ecriture_id,
                (src.donnees->>'Code relancé') AS compte,
                (src.donnees->>'Code facturé') AS code_facture,
                (src.donnees->>'Code payeur') AS code_payeur,
                (src.donnees->>'No pièce') AS numpiece,
                (src.donnees->>'reference') AS reference_facture,
                NULLIF(src.donnees->>'Date d''échéance', '')::DATE AS date_echeance,
                NULLIF(src.donnees->>'Date de pièce', '')::DATE AS date_piece,
                CASE
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE IS NULL THEN NULL
                    ELSE (CURRENT_DATE - NULLIF(src.donnees->>'Date d''échéance','')::DATE)
                END AS jours_retard,
                CASE
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE IS NULL THEN NULL
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE > CURRENT_DATE - INTERVAL '30 days' THEN '<30'
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE > CURRENT_DATE - INTERVAL '60 days' THEN '>30'
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE > CURRENT_DATE - INTERVAL '90 days' THEN '>60'
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE > CURRENT_DATE - INTERVAL '120 days' THEN '>90'
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE > CURRENT_DATE - INTERVAL '180 days' THEN '>120'
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE > CURRENT_DATE - INTERVAL '240 days' THEN '>180'
                    ELSE '>240'
                END AS retard,
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant solde en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_solde,
                (src.donnees->>'Montant solde en devise entité') AS montant_solde_brut,
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant initial en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_initial,
                (src.donnees->>'Code entité') AS code_entite,
                (src.donnees->>'codeetab') AS codeetab,
                (src.donnees->>'Marque (BU)') AS marque,
                (src.donnees->>'numimmat') AS numimmat,
                (src.donnees->>'numvin') AS numvin,
                (src.donnees->>'Libellé') AS libelle,
                (src.donnees->>'collectif') AS collectif,
                (t.donnees->>'nom') AS nom,
                (t.donnees->>'prenom') AS prenom,
                (t.donnees->>'civilite') AS civilite,
                (t.donnees->>'Raison Sociale') AS raison_sociale,
                (t.donnees->>'email') AS email,
                (t.donnees->>'Téléphone') AS telephone,
                (t.donnees->>'portable') AS portable,
                TRIM(CONCAT_WS(' ',
                    NULLIF(t.donnees->>'Adresse 1', ''),
                    NULLIF(t.donnees->>'Adresse 2', ''),
                    NULLIF(t.donnees->>'Code postal', ''),
                    NULLIF(t.donnees->>'ville', '')
                )) AS adresse,
                (t.donnees->>'TYPE') AS type_compte,
                (t.donnees->>'bloqué') AS bloque,
                NULLIF(TRIM(t.donnees->>'Statut juridique'), '') AS statut_juridique,
                NULLIF(TRIM(t.donnees->>'Code statut'), '') AS code_statut,
                NULLIF(TRIM(COALESCE(NULLIF(TRIM(t.donnees->>'Code conditions de règlement'), ''), t.donnees->>'condcomid')), '') AS code_conditions_reglement,
                NULLIF(TRIM(COALESCE(NULLIF(TRIM(t.donnees->>'Code mode de paiement'), ''), t.donnees->>'modecomid')), '') AS code_mode_paiement,
                NULLIF(TRIM(src.donnees->>'cheminpdf'), '') AS chemin_pdf
            SQL;

        // Jointure tiers : hash join dédupliqué (rapide) ou LATERAL (ancien, lent).
        $join = $lateral ? <<<'SQL'
            FROM src
            LEFT JOIN LATERAL (
                SELECT t.donnees
                FROM mirror.tiers t
                WHERE t.present_dans_sage
                  AND t.donnees->>'code' = src.donnees->>'Code relancé'
                ORDER BY t.cle
                LIMIT 1
            ) t ON TRUE
            SQL : <<<'SQL'
            FROM src
            LEFT JOIN tiers_uniq t ON t.code = src.donnees->>'Code relancé'
            SQL;

        return $src.$cteTiers."\n".$colonnes."\n".$join;
    }
}
