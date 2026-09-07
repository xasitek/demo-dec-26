<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : transforme recouvrement.v_impayes en VUE MATERIALISEE (perf).
 *
 * La vue simple rescannait mirror.bal_eloficash + reparsait le JSONB a chaque
 * requete (liste, selection, cloture, garde-fous d'envoi, rapprochement email,
 * panneau factures, PDF courrier). Sans index exploitable, chaque acces etait un
 * full-scan. La source n'evoluant que la nuit (ETL Sage), une vue materialisee a
 * la MEME fraicheur mais devient indexable et pre-calculee (meme pattern que
 * garanties.mv_reconciliation).
 *
 * - Meme SELECT que la vue precedente (Version20260630100000), a une exception :
 *   la jointure mirror.tiers passe en LEFT JOIN LATERAL ... LIMIT 1, ce qui
 *   GARANTIT une ligne par cle meme si un code tiers etait duplique (sinon
 *   l'index unique, requis par REFRESH CONCURRENTLY, echouerait). Resultat
 *   identique sur les donnees actuelles (cle deja unique).
 * - Index unique sur cle (REFRESH ... CONCURRENTLY sans verrou des lectures) +
 *   index d'acces sur compte, ecriture_id, lower(btrim(email)).
 *
 * NB : jours_retard / retard / tranche sont calcules avec CURRENT_DATE au moment
 * du REFRESH (snapshot). La chaine cron rafraichit apres l'ETL
 * (app:recouvrement:refresh-impayes) et PreparerRelancesCommand rafraichit en
 * tete pour selectionner sur le retard du jour.
 *
 * DEPLOIEMENT : ajouter app:recouvrement:refresh-impayes au cron, juste apres
 * app:etl:mirror-compta. Sans ce refresh, la vue resterait figee au snapshot de
 * creation.
 */
final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : v_impayes en vue materialisee + index (perf)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS recouvrement.v_impayes');
        $this->addSql('CREATE MATERIALIZED VIEW recouvrement.v_impayes AS '.self::selectImpayes().' WITH DATA');

        // Index unique OBLIGATOIRE pour REFRESH MATERIALIZED VIEW CONCURRENTLY.
        $this->addSql('CREATE UNIQUE INDEX uniq_impayes_cle ON recouvrement.v_impayes (cle)');
        // Index d'acces : couvrent les WHERE des consommateurs.
        $this->addSql('CREATE INDEX idx_impayes_compte ON recouvrement.v_impayes (compte)');
        $this->addSql('CREATE INDEX idx_impayes_ecriture ON recouvrement.v_impayes (ecriture_id)');
        $this->addSql('CREATE INDEX idx_impayes_email ON recouvrement.v_impayes (lower(btrim(email)))');
    }

    public function down(Schema $schema): void
    {
        // Retour a la vue simple (definition de Version20260630100000, jointure non laterale).
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS recouvrement.v_impayes');
        $this->addSql(<<<'SQL'
            CREATE VIEW recouvrement.v_impayes AS
            WITH src AS (
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM mirror.bal_eloficash
                WHERE present_dans_sage
                  AND donnees->>'Code type pièce' = 'FC'
                  AND LEFT(donnees->>'collectif', 3) = '411'
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
            )
            SELECT
                src.cle,
                src.donnees,
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
            FROM src
            LEFT JOIN mirror.tiers t
                ON t.present_dans_sage
               AND t.donnees->>'code' = src.donnees->>'Code relancé'
        SQL
        );
    }

    /**
     * SELECT de la vue materialisee : identique a Version20260630100000, mais la
     * jointure tiers est LATERALE (LIMIT 1) pour garantir une ligne par cle.
     */
    private static function selectImpayes(): string
    {
        return <<<'SQL'
            WITH src AS (
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM mirror.bal_eloficash
                WHERE present_dans_sage
                  AND donnees->>'Code type pièce' = 'FC'
                  AND LEFT(donnees->>'collectif', 3) = '411'
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
            )
            SELECT
                src.cle,
                src.donnees,
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
            FROM src
            LEFT JOIN LATERAL (
                SELECT t.donnees
                FROM mirror.tiers t
                WHERE t.present_dans_sage
                  AND t.donnees->>'code' = src.donnees->>'Code relancé'
                ORDER BY t.cle
                LIMIT 1
            ) t ON TRUE
            SQL;
    }
}
