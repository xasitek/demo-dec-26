<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Remboursement client — onglet « Lettrage » (remplace l'onglet « A lettrer »
 * de l'ancien dashboard FastAPI + Google Sheet).
 *
 * 1. Vue `remboursement.v_lettrage` : les paiements de remboursement encore NON
 *    LETTRES, lus dans le miroir comptable. Deux criteres, tous deux issus de la
 *    balance agee :
 *      - `Libellé` prefixe « RBC // » (rachat sec) ou « TP // » (trop-percu) : ce sont
 *        nos ecritures, apposees par GenerateurCsvComptable ;
 *      - `present_dans_sage` : la balance agee ne contient que les postes OUVERTS. Des
 *        que la compta lettre l'ecriture, la ligne disparait de l'export et le miroir
 *        la passe a false (soft-delete, cf. docs/MODULE_RECOUVREMENT.md).
 *    La colonne `lettree` de Sage est donc toujours 0 dans le miroir : elle ne
 *    discrimine rien, c'est bien `present_dans_sage` qui porte l'information.
 *
 * 2. Table `remboursement.lettrage_commentaire` : annotation de la comptable sur une
 *    ligne. Stockee A PART, indexee sur la cle d'ecriture Sage, pour survivre au
 *    resync nocturne du miroir (equivalent de l'onglet `lettrage_manuel` de l'ancien
 *    dashboard, dont c'etait deja la raison d'etre).
 */
final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : onglet Lettrage (vue v_lettrage sur le miroir + commentaires comptable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE remboursement.lettrage_commentaire (
                cle_ecriture BIGINT NOT NULL,
                commentaire TEXT NOT NULL,
                par VARCHAR(180) DEFAULT NULL,
                cree_le TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                modifie_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (cle_ecriture)
            )
        SQL);

        // Index partiel cible : la recherche de nos ecritures dans 200 000 lignes de
        // balance agee ne doit pas faire de seq scan. `text_pattern_ops` est
        // indispensable pour que le LIKE 'RBC //%' soit servi par l'index (sinon la
        // collation de la base le rend inutilisable pour un prefixe).
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_mirror_bal_lettrage
                ON mirror.bal_eloficash ((donnees->>'Libellé') text_pattern_ops)
                WHERE present_dans_sage
        SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE VIEW remboursement.v_lettrage AS
            SELECT
                (b.donnees->>'clé écriture')::BIGINT AS cle_ecriture,
                CASE WHEN b.donnees->>'Libellé' LIKE 'RBC //%' THEN 'RBC' ELSE 'TP' END AS type,
                TRIM(b.donnees->>'Libellé') AS libelle,
                -- Libelle sans le prefixe de type : ce que la comptable lit reellement.
                NULLIF(TRIM(SPLIT_PART(b.donnees->>'Libellé', '//', 2)), '') AS libelle_court,
                TRIM(b.donnees->>'codeetab') AS codeetab,
                TRIM(b.donnees->>'Code relancé') AS compte,
                TRIM(b.donnees->>'No pièce') AS numpiece,
                TRIM(b.donnees->>'Code entité') AS code_entite,
                NULLIF(TRIM(b.donnees->>'numimmat'), '') AS numimmat,
                NULLIF(TRIM(b.donnees->>'numvin'), '') AS numvin,
                NULLIF(b.donnees->>'Date de pièce', '')::DATE AS date_piece,
                CURRENT_DATE - NULLIF(b.donnees->>'Date de pièce', '')::DATE AS jours,
                CASE
                    WHEN NULLIF(b.donnees->>'Date de pièce', '')::DATE IS NULL THEN NULL
                    WHEN CURRENT_DATE - NULLIF(b.donnees->>'Date de pièce', '')::DATE <= 15 THEN '<15'
                    WHEN CURRENT_DATE - NULLIF(b.donnees->>'Date de pièce', '')::DATE <= 30 THEN '>15'
                    WHEN CURRENT_DATE - NULLIF(b.donnees->>'Date de pièce', '')::DATE <= 60 THEN '>30'
                    WHEN CURRENT_DATE - NULLIF(b.donnees->>'Date de pièce', '')::DATE <= 90 THEN '>60'
                    ELSE '>90'
                END AS retard,
                -- Montant de la balance agee : chaine « 16 001,37 € » -> numerique. Le
                -- separateur de milliers est une espace insecable, d'ou le nettoyage par
                -- regex (tout sauf chiffres, point et signe) plutot qu'un REPLACE d'espace.
                COALESCE(NULLIF(
                    REGEXP_REPLACE(
                        REPLACE(b.donnees->>'Montant solde en devise entité', ',', '.'),
                        '[^0-9.-]', '', 'g'
                    ), ''
                )::NUMERIC(14, 2), 0) AS montant,
                COALESCE(
                    NULLIF(TRIM(t.donnees->>'Raison Sociale'), ''),
                    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(t.donnees->>'prenom'), ''), NULLIF(TRIM(t.donnees->>'nom'), ''))), ''),
                    TRIM(b.donnees->>'Code relancé')
                ) AS nom,
                e.libelle AS etablissement,
                c.commentaire,
                c.par AS commentaire_par,
                COALESCE(c.modifie_le, c.cree_le) AS commentaire_le
            FROM mirror.bal_eloficash b
            -- LATERAL + LIMIT 1 : `mirror.tiers.code` n'a pas de contrainte d'unicite ;
            -- un doublon dupliquerait la ligne et donc les montants de la synthese.
            LEFT JOIN LATERAL (
                SELECT t.donnees
                FROM mirror.tiers t
                WHERE t.present_dans_sage
                  AND t.donnees->>'code' = b.donnees->>'Code relancé'
                LIMIT 1
            ) t ON TRUE
            LEFT JOIN shared.etablissement e
                ON e.code_etab = TRIM(b.donnees->>'codeetab')
            LEFT JOIN remboursement.lettrage_commentaire c
                ON c.cle_ecriture = (b.donnees->>'clé écriture')::BIGINT
            WHERE b.present_dans_sage
              AND (b.donnees->>'Libellé' LIKE 'RBC //%' OR b.donnees->>'Libellé' LIKE 'TP //%')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS remboursement.v_lettrage');
        $this->addSql('DROP INDEX IF EXISTS mirror.idx_mirror_bal_lettrage');
        $this->addSql('DROP TABLE remboursement.lettrage_commentaire');
    }
}
