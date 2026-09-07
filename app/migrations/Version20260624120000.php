<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : schema dedie + vue des impayes clients a relancer.
 *
 * Module Recouvrement (relances automatiques d'impayes clients). Source unique
 * des impayes = factures clients ouvertes dans mirror.bal_eloficash :
 *   - collectif client (compte commencant par 411),
 *   - piece de type FC (facture),
 *   - solde non nul (= non lettree / non soldee),
 *   - present dans Sage.
 * JOIN mirror.tiers (sur 'Code relance') pour l'identite + les coordonnees
 * (email / telephone), indispensables a l'envoi des relances.
 *
 * Logique SQL reprise/adaptee de la V2 du manager (parse montant format FR,
 * tranches de retard calculees depuis la date d'echeance), mais :
 *   - schema isole 'recouvrement' (pas le schema 'creances' historique),
 *   - filtre collectif 411 (strictement les creances clients),
 *   - lecture mirror.* uniquement (aucune dependance a creances_demo),
 *   - vue en LECTURE SEULE sur mirror : aucune ecriture sur mirror.
 */
final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : schema recouvrement + vue v_impayes (FC clients 411 echues non soldees)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS recouvrement');

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
                (t.donnees->>'bloqué') AS bloque
            FROM src
            LEFT JOIN mirror.tiers t
                ON t.present_dans_sage
               AND t.donnees->>'code' = src.donnees->>'Code relancé'
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS recouvrement.v_impayes');
        // On laisse le schema recouvrement (il accueillera les tables du module).
    }
}
