<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Espace Livraison — la vue accepte les vehicules sans immatriculation.
 *
 * Constat du controle de parite (`app:livraison:parite`, 2026-09-01) : la vue
 * ne couvrait que 90,1 % de l'onglet `ESPACE LIVREUR`. Diagnostic sur les
 * ecritures manquantes : dans 18 cas sur 38, **`numimmat` est vide en base**
 * alors que le Sheet affiche bien un identifiant.
 *
 * Cet identifiant, ce sont les **8 derniers caracteres du VIN** :
 *   VIN `W0VEHHNP8SJ656680` -> `SJ656680`
 *   VIN `TMAH881AXTJ094148` -> `TJ094148`
 *   VIN `YARKBBC3800455579` -> `00455579`
 * Ce sont des vehicules factures avant d'etre immatricules. Cela explique
 * enfin le `RIGHT(...; 8)` que le tableur utilisait en secours du
 * `RIGHT(...; 9)` : neuf caracteres pour une plaque, huit pour un suffixe de VIN.
 *
 * La vue expose donc deux colonnes distinctes :
 *   - `immatriculation`       : la plaque, NULL quand le vehicule n'en a pas
 *   - `identifiant_vehicule`  : la cle de rapprochement, jamais NULL
 *
 * Gain mesure : 455 -> 483 vehicules.
 *
 * Non traite volontairement : les payeurs `COMPTANT-*`. Une vingtaine figure
 * dans le Sheet, mais la base en compte 2 380 — un critere metier
 * supplementaire existe, il reste a etablir avec la comptabilite. Les inclure
 * en bloc ferait entrer 2 380 vehicules au lieu de 20.
 */
final class Version20260901150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraison : v_a_livrer accepte les vehicules sans plaque (identifiant = 8 derniers caracteres du VIN)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS livraison.v_a_livrer');

        $this->addSql(<<<'SQL'
            CREATE VIEW livraison.v_a_livrer AS
            SELECT
                e.donnees->>'clé écriture'                          AS cle_ecriture,
                e.donnees->>'Code payeur'                           AS code_payeur,
                l.libelle                                           AS loueur,
                nullif(e.donnees->>'Code facturé', '')              AS code_facture,
                -- La plaque, uniquement si c'en est une (format SIV ou FNI).
                CASE WHEN upper(e.donnees->>'numimmat') ~ '^[A-Z]{2}-[0-9]{3}-[A-Z]{2}$'
                       OR upper(e.donnees->>'numimmat') ~ '^[0-9]{1,4}\s?[A-Z]{2,3}\s?[0-9]{2}$'
                     THEN upper(e.donnees->>'numimmat') END         AS immatriculation,
                -- Cle de rapprochement : jamais nulle. Meme regle que le tableur.
                coalesce(
                    nullif(upper(e.donnees->>'numimmat'), ''),
                    right(upper(e.donnees->>'numvin'), 8)
                )                                                   AS identifiant_vehicule,
                nullif(e.donnees->>'numvin', '')                    AS vin,
                nullif(e.donnees->>'numor', '')                     AS num_or,
                nullif(e.donnees->>'reficar', '')                   AS ref_icar,
                nullif(e.donnees->>'codeetab', '')                  AS code_etab,
                nullif(e.donnees->>'Code entité', '')               AS code_entite,
                nullif(e.donnees->>'Libellé', '')                   AS libelle,
                nullif(e.donnees->>'reference', '')                 AS reference,
                nullif(e.donnees->>'No pièce', '')                  AS num_piece,
                CASE WHEN e.donnees->>'Date de pièce' ~ '^\d{4}-\d{2}-\d{2}$'
                     THEN (e.donnees->>'Date de pièce')::date END   AS date_piece,
                CASE WHEN e.donnees->>'Date d''échéance' ~ '^\d{4}-\d{2}-\d{2}$'
                     THEN (e.donnees->>'Date d''échéance')::date END AS date_echeance,
                CASE WHEN e.donnees->>'solde' ~ '^-?\d+(\.\d+)?$'
                     THEN (e.donnees->>'solde')::numeric(14,2) END  AS solde,
                nullif(e.donnees->>'cheminpdf', '')                 AS url_scan,
                e.vu_le
            FROM mirror.bal_eloficash e
            JOIN livraison.loueur l
              ON l.code_payeur = e.donnees->>'Code payeur'
             AND l.actif
            WHERE e.donnees->>'collectif' = '4111000'
              AND e.present_dans_sage
              AND e.donnees->>'Code type pièce' = 'FC'
              AND (
                    coalesce(e.donnees->>'numimmat', '') <> ''
                 OR length(coalesce(e.donnees->>'numvin', '')) >= 8
              )
            SQL);

        $this->addSql("COMMENT ON VIEW livraison.v_a_livrer IS 'Factures loueur rattachees a un vehicule. Remplace l''onglet ESPACE LIVREUR. identifiant_vehicule = numimmat sinon les 8 derniers caracteres du VIN (vehicule pas encore immatricule).'");
    }

    public function down(Schema $schema): void
    {
        // Retour a la vue de Version20260901140000, sans le repli sur le VIN.
        $this->addSql('DROP VIEW IF EXISTS livraison.v_a_livrer');
        $this->addSql(<<<'SQL'
            CREATE VIEW livraison.v_a_livrer AS
            SELECT
                e.donnees->>'clé écriture'                          AS cle_ecriture,
                e.donnees->>'Code payeur'                           AS code_payeur,
                l.libelle                                           AS loueur,
                nullif(e.donnees->>'Code facturé', '')              AS code_facture,
                upper(e.donnees->>'numimmat')                       AS immatriculation,
                nullif(e.donnees->>'numvin', '')                    AS vin,
                nullif(e.donnees->>'numor', '')                     AS num_or,
                nullif(e.donnees->>'reficar', '')                   AS ref_icar,
                nullif(e.donnees->>'codeetab', '')                  AS code_etab,
                nullif(e.donnees->>'Code entité', '')               AS code_entite,
                nullif(e.donnees->>'Libellé', '')                   AS libelle,
                nullif(e.donnees->>'reference', '')                 AS reference,
                nullif(e.donnees->>'No pièce', '')                  AS num_piece,
                CASE WHEN e.donnees->>'Date de pièce' ~ '^\d{4}-\d{2}-\d{2}$'
                     THEN (e.donnees->>'Date de pièce')::date END   AS date_piece,
                CASE WHEN e.donnees->>'Date d''échéance' ~ '^\d{4}-\d{2}-\d{2}$'
                     THEN (e.donnees->>'Date d''échéance')::date END AS date_echeance,
                CASE WHEN e.donnees->>'solde' ~ '^-?\d+(\.\d+)?$'
                     THEN (e.donnees->>'solde')::numeric(14,2) END  AS solde,
                nullif(e.donnees->>'cheminpdf', '')                 AS url_scan,
                e.vu_le
            FROM mirror.bal_eloficash e
            JOIN livraison.loueur l
              ON l.code_payeur = e.donnees->>'Code payeur'
             AND l.actif
            WHERE e.donnees->>'collectif' = '4111000'
              AND e.present_dans_sage
              AND e.donnees->>'Code type pièce' = 'FC'
              AND coalesce(e.donnees->>'numimmat', '') <> ''
            SQL);
    }
}
