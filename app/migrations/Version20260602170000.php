<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Recouvrement — vue `creances.v_creances_ouvertes` (vraie source des
 * créances impayées Synthauto).
 *
 * Audit 2026-06-02 : `mirror.balance_agee` ne contient que 1 061 lignes
 * (sous-ensemble) alors qu'il y a 44 941 factures clients impayées dans
 * `mirror.bal_eloficash`. Le module doit pointer sur la vraie source.
 *
 * La vue :
 *   - Source : `mirror.bal_eloficash` filtré sur `Code type pièce = 'FC'`
 *     (factures clients) avec `Montant solde en devise entité` non nul.
 *   - JOIN avec `mirror.tiers` via `Code relancé = tiers.code` (99.99 %
 *     de match validé en BDD) pour récupérer identité client (nom, prénom,
 *     email, téléphones, adresse, TYPE en compte/comptant).
 *   - Calcule la tranche `retard` depuis `Date d'échéance` en buckets Sage
 *     standards : `<30`, `>30`, `>60`, `>90`, `>120`, `>180`, `>240`.
 *   - Parse le `Montant solde` (format FR `"1 234,56 €"`) en NUMERIC pour
 *     les sommes et tris.
 *   - Expose `compte` = `Code relancé` (tiers à relancer, parfois différent
 *     du facturé et du payeur, cf. dictionnaire annoté Tiffany 2026-05-29).
 *
 * Les pertes connues (vs `balance_agee`) : `bal_eloficash` ne contient pas
 * `nomvendeur`, `prenomvendeur`, `nomsecretaire`. L'analyse par
 * vendeur/secrétaire est donc retirée du module ou nécessite une autre
 * source.
 *
 * La vue inclut aussi `creances_demo.bal_eloficash` via UNION ALL avec la
 * même structure clé/donnees, pour que le seed local reste fonctionnel.
 */
final class Version20260602170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : vue creances.v_creances_ouvertes (bal_eloficash FC impayes + JOIN tiers + tranche calculee)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS creances.v_creances_ouvertes');

        $this->addSql(<<<'SQL'
            CREATE VIEW creances.v_creances_ouvertes AS
            WITH src AS (
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM mirror.bal_eloficash
                WHERE present_dans_sage
                  AND donnees->>'Code type pièce' = 'FC'
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
                UNION ALL
                SELECT cle, donnees, present_dans_sage, cree_le, vu_le, modifie_le
                FROM creances_demo.bal_eloficash
                WHERE present_dans_sage
                  AND donnees->>'Code type pièce' = 'FC'
                  AND COALESCE(donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
            ),
            tiers_all AS (
                SELECT donnees FROM mirror.tiers WHERE present_dans_sage
                UNION ALL
                SELECT donnees FROM creances_demo.tiers WHERE present_dans_sage
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
                -- Tranche retard calculée depuis Date d'échéance
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
                -- Montant solde parse depuis le format FR "1 234,56 €"
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant solde en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ),
                    ''
                )::NUMERIC, 0) AS montant_solde,
                (src.donnees->>'Montant solde en devise entité') AS montant_solde_brut,
                -- Montant initial (TTC facture)
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(src.donnees->>'Montant initial en devise entité', ',', '.'), '€', ''),
                        '[^0-9.\-]', '', 'g'
                    ),
                    ''
                )::NUMERIC, 0) AS montant_initial,
                -- HT numérique direct
                COALESCE(NULLIF(src.donnees->>'Montant HT en devise entité', '')::NUMERIC, 0) AS montant_ht,
                -- Métadonnées Sage
                (src.donnees->>'Code entité') AS code_entite,
                (src.donnees->>'codeetab') AS codeetab,
                (src.donnees->>'codejournal') AS codejournal,
                (src.donnees->>'Marque (BU)') AS marque,
                (src.donnees->>'numimmat') AS numimmat,
                (src.donnees->>'numvin') AS numvin,
                (src.donnees->>'Libellé') AS libelle,
                (src.donnees->>'observation') AS observation,
                (src.donnees->>'collectif') AS collectif,
                -- Identité client via JOIN tiers
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
                NULLIF(t.donnees->>'Plafond autorisé', '')::NUMERIC AS plafond_autorise
            FROM src
            LEFT JOIN tiers_all t ON t.donnees->>'code' = src.donnees->>'Code relancé'
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS creances.v_creances_ouvertes');
    }
}
