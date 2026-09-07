<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garanties : matching DG generique par longueur de VIN (au lieu d'une liste
 * de marques en dur).
 *
 * Avant (Version20260622140000) : Toyota/Opel = VIN complet ; tout le reste
 * (= NOT IN ('TOYOTA','OPEL')) = chassis 8 + OR. Probleme : BMW fournit le VIN
 * complet (17 car.) mais tombait dans la branche chassis+OR et ne matchait pas.
 *
 * Apres : la regle ne depend plus de la marque mais de la donnee disponible :
 *   - dossier.mvs de 17 caracteres -> match par VIN complet (Toyota, Opel, BMW,
 *     et toute future marque qui donne le VIN entier) ;
 *   - sinon (8 car., cas Fiat) -> chassis 8 + OR (right(numor,4)).
 * Ainsi ajouter une marque qui donne le VIN complet = config YAML seule, sans
 * nouvelle migration. Reste en phase avec ReconciliationRepository (filtre 4116000).
 */
final class Version20260623160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garanties : matching DG par longueur de VIN (17=VIN complet, sinon chassis+OR) - couvre BMW';
    }

    public function up(Schema $schema): void
    {
        $core = <<<'SQLCORE'
SELECT * FROM (   SELECT     e.cle_ecriture, e.oidech, e.collectif,     e.no_piece, e.type_piece, e.date_piece, e.libelle,     e.concession_sage, e.etablissement_sage, e.marque_sage, e.payeur_sage,     e.vin_complet, e.chassis, e.numor,     e.montant_initial, e.solde,     CASE WHEN e.montant_initial > 0 THEN e.montant_initial ELSE 0 END AS debit,     CASE WHEN e.montant_initial < 0 THEN abs(e.montant_initial) ELSE 0 END AS credit,     sum(e.solde) OVER (PARTITION BY e.chassis) AS solde_chassis_net,     count(e.chassis) OVER (PARTITION BY e.chassis) AS nb_lignes_chassis,     sum(e.solde) OVER (PARTITION BY e.numor) AS solde_numor_net,     count(e.numor) OVER (PARTITION BY e.numor) AS nb_lignes_numor,     dg.nb_dg, dg.nb_dg_payees, dg.nb_dg_refusees, dg.nb_dg_annulees,     dg.statut_dg_principal, dg.id_dg_principal, dg.num_dg_principal, dg.site_principal, dg.liste_sites, dg.liste_ids_dg, dg.total_montant_dg,     dg.total_montant_dg_paye,     dg.liste_num_dg, dg.liste_codes_statuts,     CASE       WHEN e.solde IS NULL THEN 'inconnu'       WHEN abs(e.solde) < 0.01 THEN 'soldee'       WHEN e.chassis IS NULL AND dg.nb_dg = 0 THEN 'od_sans_vin'       WHEN e.chassis IS NOT NULL AND dg.nb_dg = 0 THEN 'orpheline'       WHEN e.solde > 0 AND sum(e.solde) OVER (PARTITION BY e.chassis) < -0.01 THEN 'trop_percu'       WHEN e.solde > 0 AND dg.nb_dg_payees > 0 THEN 'normale'       WHEN e.solde > 0 AND dg.nb_dg_refusees > 0 AND dg.nb_dg_payees = 0 THEN 'refusee'       WHEN e.solde > 0 AND dg.nb_dg_annulees > 0 AND dg.nb_dg_payees = 0 THEN 'annulee'       ELSE 'normale'     END AS etat_rapprochement   FROM (     SELECT       donnees->>'No pièce' AS no_piece,       trim(donnees->>'Code type pièce') AS type_piece,       NULLIF(donnees->>'Date de pièce', '')::date AS date_piece,       donnees->>'Libellé' AS libelle,       trim(donnees->>'Code entité') AS concession_sage,       trim(donnees->>'codeetab') AS etablissement_sage,       trim(donnees->>'Marque (BU)') AS marque_sage,       trim(donnees->>'Code payeur') AS payeur_sage,       donnees->>'collectif' AS collectif, donnees->>'numvin' AS vin_complet,       right(donnees->>'numvin', 8) AS chassis,       donnees->>'numor' AS numor,       NULLIF(regexp_replace(replace(donnees->>'Montant initial en devise entité', ',', '.'), '[^0-9.\-]', '', 'g'), '')::numeric AS montant_initial,       NULLIF(regexp_replace(replace(donnees->>'Montant solde en devise entité', ',', '.'), '[^0-9.\-]', '', 'g'), '')::numeric AS solde,       (donnees->>'clé écriture') AS cle_ecriture,       (donnees->>'oidech') AS oidech     FROM mirror.bal_eloficash     WHERE donnees->>'collectif' = '4116000' AND present_dans_sage = __PRESENT__  ) e   LEFT JOIN LATERAL (     SELECT       count(*) AS nb_dg,       count(*) FILTER (WHERE statut_code = '21') AS nb_dg_payees,       count(*) FILTER (WHERE statut_code = '29') AS nb_dg_refusees,       count(*) FILTER (WHERE statut_code = '20') AS nb_dg_annulees,       (array_agg(statut_code ORDER BY CASE statut_code WHEN '21' THEN 1 WHEN '22' THEN 2 WHEN '23' THEN 3 WHEN '24' THEN 4 WHEN '25' THEN 5 WHEN '29' THEN 6 WHEN '20' THEN 7 ELSE 8 END))[1] AS statut_dg_principal,       (array_agg(id ORDER BY CASE statut_code WHEN '21' THEN 1 ELSE 2 END, id))[1] AS id_dg_principal,       (array_agg(num_dg ORDER BY CASE statut_code WHEN '21' THEN 1 ELSE 2 END, id))[1] AS num_dg_principal,       (array_agg(concession ORDER BY CASE statut_code WHEN '21' THEN 1 ELSE 2 END, id))[1] AS site_principal,       string_agg(DISTINCT concession, '; ') AS liste_sites,       array_agg(id) AS liste_ids_dg,       string_agg(num_dg, '; ' ORDER BY CASE statut_code WHEN '21' THEN 1 WHEN '22' THEN 2 WHEN '23' THEN 3 WHEN '24' THEN 4 WHEN '25' THEN 5 WHEN '29' THEN 6 WHEN '20' THEN 7 ELSE 8 END, id) AS liste_num_dg,       string_agg(statut_code, '; ' ORDER BY CASE statut_code WHEN '21' THEN 1 WHEN '22' THEN 2 WHEN '23' THEN 3 WHEN '24' THEN 4 WHEN '25' THEN 5 WHEN '29' THEN 6 WHEN '20' THEN 7 ELSE 8 END, id) AS liste_codes_statuts,       COALESCE(sum(montant_dg), 0) AS total_montant_dg,       COALESCE(sum(montant_dg) FILTER (WHERE statut_code = '21'), 0) AS total_montant_dg_paye     FROM garanties.dossier     WHERE (         (length(mvs) = 17 AND mvs = e.vin_complet)         OR (length(mvs) <> 17 AND e.chassis IS NOT NULL AND chassis = e.chassis AND e.numor IS NOT NULL AND numero_or = right(e.numor, 4))     )   ) dg ON true ) r
SQLCORE;

        $mv = 'SELECT sub.*, false AS vue_lettree FROM ('.str_replace('__PRESENT__', 'TRUE', $core).') sub'
            .' UNION ALL '
            .'SELECT sub.*, true AS vue_lettree FROM ('.str_replace('__PRESENT__', 'FALSE', $core).') sub';

        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS garanties.mv_reconciliation');
        $this->addSql('CREATE MATERIALIZED VIEW garanties.mv_reconciliation AS '.$mv);

        $this->addSql('CREATE UNIQUE INDEX uniq_mv_reco ON garanties.mv_reconciliation (vue_lettree, cle_ecriture, oidech)');
        $this->addSql('CREATE INDEX idx_mv_reco_date ON garanties.mv_reconciliation (vue_lettree, date_piece)');
        $this->addSql('CREATE INDEX idx_mv_reco_marque ON garanties.mv_reconciliation (vue_lettree, marque_sage)');
        $this->addSql('CREATE INDEX idx_mv_reco_etat ON garanties.mv_reconciliation (vue_lettree, etat_rapprochement)');
        $this->addSql('CREATE INDEX idx_mv_reco_statut ON garanties.mv_reconciliation (vue_lettree, statut_dg_principal)');
        $this->addSql('CREATE INDEX idx_mv_reco_concession ON garanties.mv_reconciliation (vue_lettree, concession_sage)');
        $this->addSql('CREATE INDEX idx_mv_reco_solde ON garanties.mv_reconciliation (vue_lettree, solde)');
        $this->addSql('CREATE INDEX idx_mv_reco_collectif ON garanties.mv_reconciliation (vue_lettree, collectif)');
    }

    public function down(Schema $schema): void
    {
        // La definition precedente (liste de marques en dur) est recreee par
        // Version20260622140000 (relancer cette migration).
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS garanties.mv_reconciliation');
    }
}
