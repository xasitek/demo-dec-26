<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : projection ANNUAIRE CLIENTS (vue "Clients" orientee recherche).
 *
 * Vue materialisee `recouvrement.mv_annuaire_clients` : UNE ligne par client 411
 * present dans Sage (~15 000, pas les 100k ecritures de v_impayes), pre-agregeant
 * identite (mirror.tiers) + encours et buckets de retard (v_impayes) + derniere
 * relance + statut de curation, avec une colonne `recherche` normalisee (compte,
 * raison sociale, nom/prenom, e-mail, SIREN, numeros de facture).
 *
 * Index : trigramme GIN (pg_trgm) sur `recherche` -> ILIKE '%q%' indexee et tolerante
 * a la casse/accents (unaccent) ; index de tri (retard, encours, compte) pour la
 * pagination keyset. Rafraichie apres v_impayes (RefreshImpayesCommand).
 */
final class Version20260805130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : vue materialisee mv_annuaire_clients (annuaire clients orientee recherche) + index trigramme';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');

        $this->addSql(<<<'SQL'
            CREATE MATERIALIZED VIEW recouvrement.mv_annuaire_clients AS
            WITH tiers_client AS (
                SELECT DISTINCT ON (t.donnees->>'code') t.donnees AS d
                FROM mirror.tiers t
                WHERE t.present_dans_sage = true AND COALESCE(t.donnees->>'code', '') <> ''
                ORDER BY t.donnees->>'code', t.modifie_le DESC NULLS LAST
            ),
            agg AS (
                SELECT compte,
                       sum(montant_solde) AS encours,
                       sum(montant_solde) FILTER (WHERE jours_retard > 0) AS echu,
                       sum(montant_solde) FILTER (WHERE jours_retard <= 0) AS a_echoir,
                       sum(montant_solde) FILTER (WHERE jours_retard BETWEEN 1 AND 30) AS b_0_30,
                       sum(montant_solde) FILTER (WHERE jours_retard > 30) AS b_30_plus,
                       max(jours_retard) AS retard_max,
                       count(*) FILTER (WHERE jours_retard > 0 AND montant_solde > 0) AS nb_factures,
                       max(codeetab) AS codeetab, max(marque) AS marque, max(collectif) AS collectif,
                       string_agg(DISTINCT COALESCE(NULLIF(numpiece, ''), reference_facture), ' ') AS numeros
                FROM recouvrement.v_impayes
                GROUP BY compte
            )
            SELECT
                tc.d->>'code' AS compte,
                COALESCE(NULLIF(tc.d->>'Raison Sociale', ''), NULLIF(TRIM(CONCAT_WS(' ', tc.d->>'prenom', tc.d->>'nom')), ''), tc.d->>'code') AS client,
                NULLIF(tc.d->>'email', '') AS email,
                NULLIF(tc.d->>'Téléphone', '') AS telephone,
                NULLIF(tc.d->>'portable', '') AS portable,
                NULLIF(tc.d->>'siren', '') AS siren,
                NULLIF(tc.d->>'ville', '') AS ville,
                NULLIF(tc.d->>'Code statut', '') AS code_statut,
                COALESCE(a.encours, 0)::numeric(14,2) AS encours,
                COALESCE(a.echu, 0)::numeric(14,2) AS echu,
                COALESCE(a.a_echoir, 0)::numeric(14,2) AS a_echoir,
                COALESCE(a.b_0_30, 0)::numeric(14,2) AS b_0_30,
                COALESCE(a.b_30_plus, 0)::numeric(14,2) AS b_30_plus,
                COALESCE(a.retard_max, 0) AS retard_max,
                COALESCE(a.nb_factures, 0) AS nb_factures,
                COALESCE(a.codeetab, '') AS codeetab,
                COALESCE(a.marque, '') AS marque,
                COALESCE(a.collectif, '') AS collectif,
                r.niveau_max AS niveau_max_envoye,
                COALESCE(e.etat = 'ecarte', false) AS ecarte,
                lower(unaccent(CONCAT_WS(' ',
                    tc.d->>'code', tc.d->>'Raison Sociale', tc.d->>'nom', tc.d->>'prenom',
                    tc.d->>'email', tc.d->>'siren', a.numeros
                ))) AS recherche
            FROM tiers_client tc
            LEFT JOIN agg a ON a.compte = tc.d->>'code'
            LEFT JOIN (
                SELECT compte_code, max(niveau) AS niveau_max
                FROM recouvrement.relance_envoi
                WHERE statut = 'envoye' AND cycle_clos = false AND ecriture_id IS NULL
                GROUP BY compte_code
            ) r ON r.compte_code = tc.d->>'code'
            LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = tc.d->>'code'
            SQL);

        $this->addSql('CREATE UNIQUE INDEX mv_annuaire_clients_compte ON recouvrement.mv_annuaire_clients (compte)');
        $this->addSql('CREATE INDEX mv_annuaire_clients_recherche ON recouvrement.mv_annuaire_clients USING gin (recherche gin_trgm_ops)');
        $this->addSql('CREATE INDEX mv_annuaire_clients_tri ON recouvrement.mv_annuaire_clients (retard_max DESC, encours DESC, compte)');
    }

    public function down(Schema $schema): void
    {
        // On laisse les extensions (partagees, potentiellement utilisees ailleurs).
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS recouvrement.mv_annuaire_clients');
    }
}
