<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement (Phase 3) : élargit v_impayes pour le moteur de règles et prépare
 * relance_envoi à la sélection pilotée par règles.
 *
 * - v_impayes : périmètre = TOUS les collectifs clients (41x) et TOUS les types de
 *   pièce (le filtrage FC/411 est désormais fait par les RÈGLES, pas par la vue) ;
 *   + colonnes `type_piece` et `montant_initial_facturation` (gate montant de la
 *   stratégie). Les autres colonnes sont inchangées.
 * - relance_envoi : `profil` devient nullable (la cadence vient de la règle) ;
 *   ajout de `regle_id` + `regle_nom` (traçabilité de la règle appliquée).
 * - règles Standard : le gate montant passe de `montant_initial` (devise entité) à
 *   `montant_initial_facturation` (devise facturation), conforme à la stratégie.
 */
final class Version20260713140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : v_impayes elargie (tous types/collectifs 41x + colonnes) + relance_envoi regle_id/regle_nom';
    }

    public function up(Schema $schema): void
    {
        $this->recreerVue(true);

        $this->addSql('ALTER TABLE recouvrement.relance_envoi ALTER COLUMN profil DROP NOT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN regle_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN regle_nom VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN mise_en_demeure BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN seuil_med SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE recouvrement.regle_relance
            SET filtres = '[{"champ":"collectif","operateur":"in","valeur":["4111000","4161000"]},{"champ":"montant_initial_facturation","operateur":"gt","valeur":0}]'
            WHERE nom = 'Standard VN'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE recouvrement.regle_relance
            SET filtres = '[{"champ":"collectif","operateur":"in","valeur":["4114000","4164000"]},{"champ":"montant_initial_facturation","operateur":"gt","valeur":0}]'
            WHERE nom = 'Standard APV'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Revenir au périmètre FC/411 et retirer les colonnes ajoutées.
        $this->recreerVue(false);

        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS seuil_med');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS mise_en_demeure');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS regle_nom');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS regle_id');
        // Repli avant de restaurer NOT NULL : un profil nul redevient 'standard'.
        $this->addSql("UPDATE recouvrement.relance_envoi SET profil = 'standard' WHERE profil IS NULL");
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ALTER COLUMN profil SET NOT NULL');
    }

    private function recreerVue(bool $large): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS recouvrement.v_impayes');
        $this->addSql('CREATE MATERIALIZED VIEW recouvrement.v_impayes AS '.self::selectImpayes($large).' WITH DATA');
        $this->addSql('CREATE UNIQUE INDEX uniq_impayes_cle ON recouvrement.v_impayes (cle)');
        $this->addSql('CREATE INDEX idx_impayes_compte ON recouvrement.v_impayes (compte)');
        $this->addSql('CREATE INDEX idx_impayes_ecriture ON recouvrement.v_impayes (ecriture_id)');
        $this->addSql('CREATE INDEX idx_impayes_email ON recouvrement.v_impayes (lower(btrim(email)))');
        // Perimetre elargi (tous types/41x) => la selection filtre par collectif sur
        // les factures echues : index partiel dedie (liste manuelle passee de 2,3s a ~0,2s).
        $this->addSql('CREATE INDEX idx_impayes_collectif_echue ON recouvrement.v_impayes (collectif) WHERE jours_retard > 0 AND montant_solde > 0');
        // Tri de la page Impayes (date d'echeance / montant) sur les factures echues.
        $this->addSql('CREATE INDEX idx_impayes_echu_tri ON recouvrement.v_impayes (date_echeance ASC, montant_solde DESC) WHERE jours_retard > 0');
    }

    private static function selectImpayes(bool $large): string
    {
        // Filtre de périmètre : large = tous types + collectifs clients 41x ;
        // sinon (down) = ancien périmètre FC + 411.
        $filtrePerimetre = $large
            ? "AND LEFT(donnees->>'collectif', 2) = '41'"
            : "AND donnees->>'Code type pièce' = 'FC'\n                  AND LEFT(donnees->>'collectif', 3) = '411'";

        $src = <<<SQL
            WITH src AS (
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM mirror.bal_eloficash
                WHERE present_dans_sage
                  {$filtrePerimetre}
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
            ), tiers_uniq AS (
                SELECT DISTINCT ON (donnees->>'code') donnees->>'code' AS code, donnees
                FROM mirror.tiers
                WHERE present_dans_sage
                ORDER BY donnees->>'code', cle
            )
            SQL;

        // Colonnes supplémentaires du périmètre large (type de pièce + gate montant).
        $colonnesLarge = $large ? <<<'SQL'
            ,
                (src.donnees->>'Code type pièce') AS type_piece,
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant initial en devise facturation', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_initial_facturation
            SQL : '';

        $colonnes = <<<SQL
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
                NULLIF(TRIM(src.donnees->>'cheminpdf'), '') AS chemin_pdf{$colonnesLarge}
            FROM src
            LEFT JOIN tiers_uniq t ON t.code = src.donnees->>'Code relancé'
            SQL;

        return $src."\n".$colonnes;
    }
}
