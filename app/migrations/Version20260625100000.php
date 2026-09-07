<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement (Foundation) : enrichissement de la vue v_impayes + modele du domaine.
 *
 * (a) RECREE recouvrement.v_impayes en conservant toutes les colonnes existantes
 *     (cf. Version20260624120000) et en AJOUTANT les champs tiers necessaires aux
 *     exclusions et au profil de paiement :
 *       - statut_juridique               (tiers 'Statut juridique')
 *       - code_statut                    (tiers 'Code statut')
 *       - code_conditions_reglement      (tiers 'Code conditions de règlement' puis 'condcomid')
 *       - code_mode_paiement             (tiers 'Code mode de paiement' puis 'modecomid')
 *     Les valeurs Sage portent des espaces parasites ('01 ', '3  ') : on TRIM pour
 *     que les comparaisons de profil fonctionnent. Lecture seule sur mirror.
 *
 * (b) recouvrement.relance_envoi : une relance preparee/envoyee par (facture, niveau).
 *     Idempotence stricte via index unique (ecriture_id, niveau).
 *
 * (c) recouvrement.retour_client : reponses des clients (IMAP / manuel / webhook),
 *     reliees a une relance quand on retrouve le token / message-id.
 */
final class Version20260625100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement (Foundation) : v_impayes + champs tiers, tables relance_envoi et retour_client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS recouvrement');

        // (a) Vue enrichie des impayes (recreee a l'identique + champs tiers).
        $this->addSql('DROP VIEW IF EXISTS recouvrement.v_impayes');
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
                -- Cles & identifiants
                (src.donnees->>'clé écriture') AS ecriture_id,
                (src.donnees->>'Code relancé') AS compte,
                (src.donnees->>'Code facturé') AS code_facture,
                (src.donnees->>'Code payeur') AS code_payeur,
                (src.donnees->>'No pièce') AS numpiece,
                (src.donnees->>'reference') AS reference_facture,
                -- Dates
                NULLIF(src.donnees->>'Date d''échéance', '')::DATE AS date_echeance,
                NULLIF(src.donnees->>'Date de pièce', '')::DATE AS date_piece,
                -- Jours de retard depuis l'echeance (pilote la cadence de relance)
                CASE
                    WHEN NULLIF(src.donnees->>'Date d''échéance','')::DATE IS NULL THEN NULL
                    ELSE (CURRENT_DATE - NULLIF(src.donnees->>'Date d''échéance','')::DATE)
                END AS jours_retard,
                -- Tranche retard (lisible UI)
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
                -- Montant solde restant du (parse format FR "1 234,56 €")
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant solde en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_solde,
                (src.donnees->>'Montant solde en devise entité') AS montant_solde_brut,
                -- Montant initial (TTC facture)
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant initial en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ), ''
                )::NUMERIC, 0) AS montant_initial,
                -- Metadonnees Sage
                (src.donnees->>'Code entité') AS code_entite,
                (src.donnees->>'codeetab') AS codeetab,
                (src.donnees->>'Marque (BU)') AS marque,
                (src.donnees->>'numimmat') AS numimmat,
                (src.donnees->>'numvin') AS numvin,
                (src.donnees->>'Libellé') AS libelle,
                (src.donnees->>'collectif') AS collectif,
                -- Identite client via JOIN tiers (sur 'Code relancé')
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
                -- Champs tiers pilotant exclusions + profil de paiement.
                -- Valeurs Sage avec espaces parasites : on TRIM puis NULLIF('').
                NULLIF(TRIM(t.donnees->>'Statut juridique'), '') AS statut_juridique,
                NULLIF(TRIM(t.donnees->>'Code statut'), '') AS code_statut,
                NULLIF(TRIM(COALESCE(
                    NULLIF(TRIM(t.donnees->>'Code conditions de règlement'), ''),
                    t.donnees->>'condcomid'
                )), '') AS code_conditions_reglement,
                NULLIF(TRIM(COALESCE(
                    NULLIF(TRIM(t.donnees->>'Code mode de paiement'), ''),
                    t.donnees->>'modecomid'
                )), '') AS code_mode_paiement
            FROM src
            LEFT JOIN mirror.tiers t
                ON t.present_dans_sage
               AND t.donnees->>'code' = src.donnees->>'Code relancé'
SQL
        );

        // (b) Table des relances preparees/envoyees.
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.relance_envoi (
                id BIGSERIAL NOT NULL,
                compte_code VARCHAR(64) NOT NULL,
                ecriture_id VARCHAR(64) NOT NULL,
                reference_facture VARCHAR(128) DEFAULT NULL,
                niveau SMALLINT NOT NULL,
                profil VARCHAR(32) NOT NULL,
                vecteur VARCHAR(32) NOT NULL,
                statut VARCHAR(32) NOT NULL,
                destinataire VARCHAR(255) DEFAULT NULL,
                sujet TEXT DEFAULT NULL,
                corps_html TEXT DEFAULT NULL,
                token VARCHAR(64) NOT NULL,
                montant_solde NUMERIC(14, 2) DEFAULT NULL,
                erreur_message TEXT DEFAULT NULL,
                prepare_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                envoye_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
SQL
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_relance_token ON recouvrement.relance_envoi (token)');
        // Unicite PARTIELLE : un palier (ecriture_id, niveau) ne peut etre ENVOYE
        // qu'une seule fois. Les statuts a_envoyer / echec / annule ne sont pas
        // contraints, ce qui autorise le rejeu d'un envoi en echec (nouvelle
        // tentative) sans violer l'unicite metier (un seul envoi reussi par palier).
        $this->addSql("CREATE UNIQUE INDEX uniq_relance_ecriture_niveau ON recouvrement.relance_envoi (ecriture_id, niveau) WHERE statut = 'envoye'");
        $this->addSql('CREATE INDEX idx_relance_compte ON recouvrement.relance_envoi (compte_code)');
        $this->addSql('CREATE INDEX idx_relance_statut ON recouvrement.relance_envoi (statut)');

        // (c) Table des retours clients (reponses aux relances).
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.retour_client (
                id BIGSERIAL NOT NULL,
                relance_envoi_id BIGINT DEFAULT NULL,
                compte_code VARCHAR(64) DEFAULT NULL,
                ecriture_id VARCHAR(64) DEFAULT NULL,
                message_id VARCHAR(255) DEFAULT NULL,
                in_reply_to VARCHAR(255) DEFAULT NULL,
                expediteur VARCHAR(255) DEFAULT NULL,
                sujet TEXT DEFAULT NULL,
                corps_texte TEXT DEFAULT NULL,
                corps_html TEXT DEFAULT NULL,
                categorie VARCHAR(32) DEFAULT NULL,
                source VARCHAR(32) NOT NULL,
                traite BOOLEAN DEFAULT false NOT NULL,
                traite_par VARCHAR(255) DEFAULT NULL,
                traite_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                commentaire_traitement TEXT DEFAULT NULL,
                payload JSONB DEFAULT NULL,
                recu_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
SQL
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE recouvrement.retour_client
                ADD CONSTRAINT fk_retour_relance
                FOREIGN KEY (relance_envoi_id)
                REFERENCES recouvrement.relance_envoi (id)
                ON DELETE SET NULL
SQL
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_retour_message_id ON recouvrement.retour_client (message_id) WHERE message_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_retour_traite ON recouvrement.retour_client (traite)');
        $this->addSql('CREATE INDEX idx_retour_compte ON recouvrement.retour_client (compte_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recouvrement.retour_client');
        $this->addSql('DROP TABLE IF EXISTS recouvrement.relance_envoi');

        // On restaure la vue v_impayes dans sa version initiale (sans les champs tiers).
        $this->addSql('DROP VIEW IF EXISTS recouvrement.v_impayes');
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
                (t.donnees->>'bloqué') AS bloque
            FROM src
            LEFT JOIN mirror.tiers t
                ON t.present_dans_sage
               AND t.donnees->>'code' = src.donnees->>'Code relancé'
SQL
        );
    }
}
