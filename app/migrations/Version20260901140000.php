<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Espace Livraison — socle en lecture seule.
 *
 * Cree le schema `livraison`, le referentiel `livraison.loueur` et la vue
 * `livraison.v_a_livrer`, qui remplace l'onglet `ESPACE LIVREUR` du classeur
 * Google (cf. docs/MODULE_LIVRAISON.md section 3).
 *
 * Aucune donnee applicative n'est creee : la vue lit `mirror.bal_eloficash`,
 * alimente par l'extraction Eloficash. Migration sans effet sur l'existant.
 *
 * Deux pieges du miroir, verifies avant redaction (section 3.2 de la doc) :
 *   - les montants sont stockes en TEXTE (`solde` vaut `0.00`, pas `0`) : toute
 *     comparaison numerique exige un cast, et le cast doit etre garde par une
 *     regex sous peine de faire echouer la vue entiere sur une seule ligne sale ;
 *   - `lettree` vaut `'0'` sur les 41 560 lignes du compte 4111000 : ce champ ne
 *     discrimine rien, ne pas s'en servir comme filtre.
 *
 * Le loueur est identifie par `Code payeur` et non par `financeur`, renseigne sur
 * 77 lignes seulement. Les codes vivent dans une table plutot que dans la vue :
 * ajouter un loueur devient un INSERT, pas une migration.
 */
final class Version20260901140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraison : schema, referentiel loueur et vue v_a_livrer (lecture seule sur mirror.bal_eloficash)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS livraison');

        $this->addSql(<<<'SQL'
            CREATE TABLE livraison.loueur (
                code_payeur VARCHAR(32)  NOT NULL,
                libelle     VARCHAR(64)  NOT NULL,
                actif       BOOLEAN      NOT NULL DEFAULT TRUE,
                commentaire TEXT         DEFAULT NULL,
                PRIMARY KEY (code_payeur)
            )
            SQL);

        $this->addSql("COMMENT ON TABLE livraison.loueur IS 'Referentiel loueur. La cle est le Code payeur de mirror.bal_eloficash, seul champ identifiant le loueur de facon fiable.'");

        // Les onze codes releves sur les 465 vehicules d'ESPACE LIVREUR. Ils
        // correspondent un pour un aux onze loueurs du referentiel. Les raisons
        // sociales sont synthetiques : la demonstration ne nomme aucune societe reelle.
        $this->addSql(<<<'SQL'
            INSERT INTO livraison.loueur (code_payeur, libelle, commentaire) VALUES
                ('LOUEUR_A_1',    'LOUEUR A',              NULL),
                ('LOUEUR_A_2',    'LOUEUR A',              'Second code du meme loueur : pas de numero de commande sur ces dossiers'),
                ('LOUEUR_B_1',    'LOUEUR B',              'Plusieurs marques commerciales pour un meme payeur'),
                ('LOUEUR_C_1',    'LOUEUR C',              'Filiale de credit-bail bancaire'),
                ('LOUEUR_D_1',    'LOUEUR D',              'Filiale de credit-bail bancaire'),
                ('LOUEUR_E_1',    'LOUEUR E',              NULL),
                ('LOUEUR_F_1',    'LOUEUR F',              NULL),
                ('LOUEUR_G_1',    'LOUEUR G',              NULL),
                ('LOUEUR_H_1',    'LOUEUR H',              NULL),
                ('LOUEUR_I_1',    'LOUEUR I',              NULL),
                ('LOUEUR_J_1',    'LOUEUR J',              'Rattachement groupe a confirmer')
            SQL);

        // Vue : un enregistrement par ecriture de facture rattachee a un loueur.
        // Les casts sont gardes par regex — une seule valeur sale ne doit pas
        // faire echouer la lecture de toute la file de travail.
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

        $this->addSql("COMMENT ON VIEW livraison.v_a_livrer IS 'Factures loueur rattachees a un vehicule. Remplace l''onglet ESPACE LIVREUR du classeur Google. Parite constatee : 455 immatriculations contre 465 dans le Sheet.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS livraison.v_a_livrer');
        $this->addSql('DROP TABLE IF EXISTS livraison.loueur');
        $this->addSql('DROP SCHEMA IF EXISTS livraison');
    }
}
