<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garanties : regenere mv_reconciliation sur le SEUL compte collectif 4116000.
 *
 * Annule l'elargissement a 4114000 (Version20260619100000 contenait les deux).
 * Definition identique a la migration oidech, seul le filtre du compte change :
 *   up()   -> donnees->>'collectif' = '4116000'
 *   down() -> donnees->>'collectif' IN ('4114000','4116000')
 * Doit rester en phase avec ReconciliationRepository::sqlBase
 * (qui filtre desormais collectif = '4116000').
 */
final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garanties : mv_reconciliation regeneree sur le seul compte 4116000';
    }

    public function up(Schema $schema): void
    {
        $this->recreer("= '4116000'");
    }

    public function down(Schema $schema): void
    {
        $this->recreer("IN ('4114000','4116000')");
    }

    /**
     * Recree la vue materialisee avec la condition de compte donnee.
     */
    private function recreer(string $collectif): void
    {
        $core = <<<'SQLCORE'
WITH chassis_par_numor AS (   SELECT donnees->>'numor' AS numor,          array_agg(DISTINCT right(donnees->>'numvin', 8))            FILTER (WHERE donnees->>'numvin' IS NOT NULL) AS chassis_list   FROM mirror.bal_eloficash   WHERE donnees->>'collectif' __COLLECTIF__ AND present_dans_sage     AND donnees->>'numor' IS NOT NULL   GROUP BY donnees->>'numor' ) SELECT * FROM (   SELECT     e.cle_ecriture, e.oidech, e.collectif,     e.no_piece, e.type_piece, e.date_piece, e.libelle,     e.concession_sage, e.etablissement_sage, e.marque_sage, e.payeur_sage,     e.vin_complet, e.chassis, e.numor,     e.montant_initial, e.solde,     CASE WHEN e.montant_initial > 0 THEN e.montant_initial ELSE 0 END AS debit,     CASE WHEN e.montant_initial < 0 THEN abs(e.montant_initial) ELSE 0 END AS credit,     sum(e.solde) OVER (PARTITION BY e.chassis) AS solde_chassis_net,     count(e.chassis) OVER (PARTITION BY e.chassis) AS nb_lignes_chassis,     sum(e.solde) OVER (PARTITION BY e.numor) AS solde_numor_net,     count(e.numor) OVER (PARTITION BY e.numor) AS nb_lignes_numor,     dg.nb_dg, dg.nb_dg_payees, dg.nb_dg_refusees, dg.nb_dg_annulees,     dg.statut_dg_principal, dg.id_dg_principal, dg.num_dg_principal, dg.site_principal, dg.liste_sites, dg.liste_ids_dg, dg.total_montant_dg,     dg.total_montant_dg_paye,     dg.liste_num_dg, dg.liste_codes_statuts,     CASE       WHEN e.solde IS NULL THEN 'inconnu'       WHEN abs(e.solde) < 0.01 THEN 'soldee'       WHEN e.chassis IS NULL AND dg.nb_dg = 0 THEN 'od_sans_vin'       WHEN e.chassis IS NOT NULL AND dg.nb_dg = 0 THEN 'orpheline'       WHEN e.solde > 0 AND sum(e.solde) OVER (PARTITION BY e.chassis) < -0.01 THEN 'trop_percu'       WHEN e.solde > 0 AND dg.nb_dg_payees > 0 THEN 'normale'       WHEN e.solde > 0 AND dg.nb_dg_refusees > 0 AND dg.nb_dg_payees = 0 THEN 'refusee'       WHEN e.solde > 0 AND dg.nb_dg_annulees > 0 AND dg.nb_dg_payees = 0 THEN 'annulee'       ELSE 'normale'     END AS etat_rapprochement   FROM (     SELECT       donnees->>'No pièce' AS no_piece,       trim(donnees->>'Code type pièce') AS type_piece,       NULLIF(donnees->>'Date de pièce', '')::date AS date_piece,       donnees->>'Libellé' AS libelle,       trim(donnees->>'Code entité') AS concession_sage,       trim(donnees->>'codeetab') AS etablissement_sage,       trim(donnees->>'Marque (BU)') AS marque_sage,       trim(donnees->>'Code payeur') AS payeur_sage,       donnees->>'collectif' AS collectif, donnees->>'numvin' AS vin_complet,       right(donnees->>'numvin', 8) AS chassis,       donnees->>'numor' AS numor,       NULLIF(regexp_replace(replace(donnees->>'Montant initial en devise entité', ',', '.'), '[^0-9.\-]', '', 'g'), '')::numeric AS montant_initial,       NULLIF(regexp_replace(replace(donnees->>'Montant solde en devise entité', ',', '.'), '[^0-9.\-]', '', 'g'), '')::numeric AS solde,       (donnees->>'clé écriture') AS cle_ecriture,       (donnees->>'oidech') AS oidech     FROM mirror.bal_eloficash     WHERE donnees->>'collectif' __COLLECTIF__ AND present_dans_sage = __PRESENT__       AND 1 = 1  ) e   LEFT JOIN chassis_par_numor cpn ON cpn.numor = e.numor   LEFT JOIN LATERAL (     SELECT       count(*) AS nb_dg,       count(*) FILTER (WHERE statut_code = '21') AS nb_dg_payees,       count(*) FILTER (WHERE statut_code = '29') AS nb_dg_refusees,       count(*) FILTER (WHERE statut_code = '20') AS nb_dg_annulees,       (array_agg(statut_code ORDER BY CASE statut_code WHEN '21' THEN 1 WHEN '22' THEN 2 WHEN '23' THEN 3 WHEN '24' THEN 4 WHEN '25' THEN 5 WHEN '29' THEN 6 WHEN '20' THEN 7 ELSE 8 END))[1] AS statut_dg_principal,       (array_agg(id ORDER BY CASE statut_code WHEN '21' THEN 1 ELSE 2 END, id))[1] AS id_dg_principal,       (array_agg(num_dg ORDER BY CASE statut_code WHEN '21' THEN 1 ELSE 2 END, id))[1] AS num_dg_principal,       (array_agg(concession ORDER BY CASE statut_code WHEN '21' THEN 1 ELSE 2 END, id))[1] AS site_principal,       string_agg(DISTINCT concession, '; ') AS liste_sites,       array_agg(id) AS liste_ids_dg,       string_agg(num_dg, '; ' ORDER BY CASE statut_code WHEN '21' THEN 1 WHEN '22' THEN 2 WHEN '23' THEN 3 WHEN '24' THEN 4 WHEN '25' THEN 5 WHEN '29' THEN 6 WHEN '20' THEN 7 ELSE 8 END, id) AS liste_num_dg,       string_agg(statut_code, '; ' ORDER BY CASE statut_code WHEN '21' THEN 1 WHEN '22' THEN 2 WHEN '23' THEN 3 WHEN '24' THEN 4 WHEN '25' THEN 5 WHEN '29' THEN 6 WHEN '20' THEN 7 ELSE 8 END, id) AS liste_codes_statuts,       COALESCE(sum(montant_dg), 0) AS total_montant_dg,       COALESCE(sum(montant_dg) FILTER (WHERE statut_code = '21'), 0) AS total_montant_dg_paye     FROM garanties.dossier     WHERE (chassis = e.chassis            OR (cpn.chassis_list IS NOT NULL AND chassis = ANY(cpn.chassis_list)))       AND (         (e.marque_sage = 'Toyota' AND emetteur = 'TOYOTA')         OR (e.marque_sage = 'Opel' AND emetteur = 'OPEL')         OR (e.marque_sage = 'Fiat' AND emetteur = 'OPEL'             AND left(e.vin_complet, 3) IN ('VXK', 'W0L', 'W0V', 'VXE', 'KL1'))         OR (e.marque_sage = 'Fiat' AND emetteur = 'TOYOTA'             AND left(e.vin_complet, 3) IN ('VNK', 'JTM', 'JTD', 'AHT', 'JTP', 'YAR', 'KMH', 'JTE', 'JT2', 'JT3', 'JT4', 'JT5', 'JTH', 'MHF', 'NMT', 'NMP', 'SB1', 'SXY'))         OR (e.marque_sage = 'Fiat' AND emetteur NOT IN ('TOYOTA', 'OPEL')             AND left(COALESCE(e.vin_complet, ''), 3) NOT IN ('VXK', 'W0L', 'W0V', 'VXE', 'KL1', 'VNK', 'JTM', 'JTD', 'AHT', 'JTP', 'YAR', 'KMH', 'JTE', 'JT2', 'JT3', 'JT4', 'JT5', 'JTH', 'MHF', 'NMT', 'NMP', 'SB1', 'SXY'))       )   ) dg ON true ) r
SQLCORE;

        $core = str_replace('__COLLECTIF__', $collectif, $core);

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
}
