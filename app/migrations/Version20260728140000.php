<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : expose le montant HT par ligne dans v_impayes (montant_ht), pour
 * afficher le detail HT / TVA / TTC dans le total des relances.
 *
 * Le HT est celui du document (`Montant HT en devise entite`) ; comme il n'existe
 * aucun paiement partiel dans le perimetre (solde = montant initial), ce HT est
 * exactement le HT du solde -> pas de proratisation. Calcul comptable : HT par
 * ligne (chaque ligne porte son propre taux effectif, TVA sur marge incluse),
 * somme ensuite ; jamais TTC global divise par un taux unique.
 *
 * Vue materialisee => DROP + CREATE (numor conserve) + re-creation des index.
 */
final class Version20260728140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : v_impayes + colonne montant_ht (detail HT/TVA/TTC)';
    }

    public function up(Schema $schema): void
    {
        $this->recreerVue(true);
    }

    public function down(Schema $schema): void
    {
        $this->recreerVue(false);
    }

    private function recreerVue(bool $avecHt): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS recouvrement.v_impayes');
        $this->addSql('CREATE MATERIALIZED VIEW recouvrement.v_impayes AS '.self::selectImpayes($avecHt).' WITH DATA');
        $this->addSql('CREATE UNIQUE INDEX uniq_impayes_cle ON recouvrement.v_impayes (cle)');
        $this->addSql('CREATE INDEX idx_impayes_compte ON recouvrement.v_impayes (compte)');
        $this->addSql('CREATE INDEX idx_impayes_ecriture ON recouvrement.v_impayes (ecriture_id)');
        $this->addSql('CREATE INDEX idx_impayes_email ON recouvrement.v_impayes (lower(btrim(email)))');
        $this->addSql('CREATE INDEX idx_impayes_collectif_echue ON recouvrement.v_impayes (collectif) WHERE jours_retard > 0 AND montant_solde > 0');
        $this->addSql('CREATE INDEX idx_impayes_echu_tri ON recouvrement.v_impayes (date_echeance ASC, montant_solde DESC) WHERE jours_retard > 0');
    }

    private static function selectImpayes(bool $avecHt): string
    {
        $colonneHt = $avecHt ? <<<'SQL'
            ,
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant HT en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_ht
            SQL : '';

        $src = <<<SQL
            WITH src AS (
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM mirror.bal_eloficash
                WHERE present_dans_sage
                  AND LEFT(donnees->>'collectif', 2) = '41'
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
            ), tiers_uniq AS (
                SELECT DISTINCT ON (donnees->>'code') donnees->>'code' AS code, donnees
                FROM mirror.tiers
                WHERE present_dans_sage
                ORDER BY donnees->>'code', cle
            )
            SQL;

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
                (src.donnees->>'numor') AS numor,
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
                NULLIF(TRIM(src.donnees->>'cheminpdf'), '') AS chemin_pdf,
                (src.donnees->>'Code type pièce') AS type_piece,
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant initial en devise facturation', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_initial_facturation{$colonneHt}
            FROM src
            LEFT JOIN tiers_uniq t ON t.code = src.donnees->>'Code relancé'
            SQL;

        return $src."\n".$colonnes;
    }
}
