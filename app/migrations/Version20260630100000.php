<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : expose l'URL Sage du PDF de facture (champ "cheminpdf" deja
 * present dans mirror.bal_eloficash) dans la vue v_impayes, colonne chemin_pdf.
 *
 * L'interface pointe directement vers cette URL (lien ouvert par le navigateur
 * du comptable, sur le reseau interne / VPN) : ni Render ni le serveur applicatif
 * n'ont besoin d'acceder a Sage pour l'affichage. CREATE OR REPLACE : on se
 * contente d'ajouter une colonne en fin de vue (definition identique sinon).
 */
final class Version20260630100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : ajoute chemin_pdf (URL Sage du PDF facture) a la vue v_impayes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE VIEW recouvrement.v_impayes AS
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

    public function down(Schema $schema): void
    {
        // Retire la colonne chemin_pdf en recreant la vue sans elle.
        $this->addSql('DROP VIEW IF EXISTS recouvrement.v_impayes');
    }
}
