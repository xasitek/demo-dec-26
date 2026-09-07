<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Recouvrement — isolation des donnees de demo hors du schema `mirror`.
 *
 * `mirror.*` est strictement reserve aux donnees pousseees par l'ETL Sage
 * (lecture seule cote application). Le SeedDemoCommand avait ete cable pour
 * y inserer des fakes (prefixe content_hash `DEMO_`), ce qui melange donnees
 * Sage et donnees de demo. On les isole dans un schema dedie :
 *
 *   - `creances_demo` (nouveau) : meme structure que `mirror.*` pour les 3
 *     tables exploitees par le module (tiers, balance_agee, bal_eloficash).
 *
 * Les vues `creances.v_tiers`, `creances.v_balance_agee` et
 * `creances.v_bal_eloficash` font un `UNION ALL` entre mirror et creances_demo.
 * Le code de lecture interroge ces vues, donc il consomme une source logique
 * unique :
 *   - en prod : `creances_demo` reste vide, l'UNION est trivial pour le
 *     planner et le cout est negligeable ;
 *   - en dev / staging : le seed remplit `creances_demo` et l'UI affiche les
 *     fakes sans polluer le miroir Sage.
 *
 * NB : la migration ne touche **jamais** au contenu de `mirror.*` (regle
 * d'hygiene Synthauto : `mirror.*` est en lecture seule cote applicatif et ne
 * doit etre alimente que par l'ETL Sage). La purge des lignes `DEMO_*` deja
 * presentes dans `mirror.*` est une operation one-shot a executer
 * manuellement par Tiffany via psql ; elle ne figure pas dans cette
 * migration pour eviter qu'elle soit rejouee par megarde.
 */
final class Version20260529063000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : schema creances_demo (isolation seed) + vues UNION mirror+demo';
    }

    public function up(Schema $schema): void
    {
        // ----- schema dedie aux donnees de demo --------------------------
        $this->addSql('CREATE SCHEMA creances_demo');

        // ----- creances_demo.tiers --------------------------------------
        // Structure strictement alignee sur mirror.tiers pour que les vues
        // UNION ALL aient des types identiques colonne par colonne.
        $this->addSql(
            'CREATE TABLE creances_demo.tiers ('
            .'cle VARCHAR(255) NOT NULL, '
            .'donnees JSONB NOT NULL, '
            .'content_hash VARCHAR(32) NOT NULL, '
            .'present_dans_sage BOOLEAN NOT NULL DEFAULT TRUE, '
            .'cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'vu_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'modifie_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'PRIMARY KEY (cle))',
        );
        $this->addSql('CREATE INDEX idx_creances_demo_tiers_present ON creances_demo.tiers (present_dans_sage)');

        // ----- creances_demo.balance_agee -------------------------------
        $this->addSql(
            'CREATE TABLE creances_demo.balance_agee ('
            .'cle VARCHAR(255) NOT NULL, '
            .'donnees JSONB NOT NULL, '
            .'content_hash VARCHAR(32) NOT NULL, '
            .'present_dans_sage BOOLEAN NOT NULL DEFAULT TRUE, '
            .'cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'vu_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'modifie_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'PRIMARY KEY (cle))',
        );
        $this->addSql('CREATE INDEX idx_creances_demo_balance_agee_present ON creances_demo.balance_agee (present_dans_sage)');

        // ----- creances_demo.bal_eloficash ------------------------------
        $this->addSql(
            'CREATE TABLE creances_demo.bal_eloficash ('
            .'cle VARCHAR(255) NOT NULL, '
            .'donnees JSONB NOT NULL, '
            .'content_hash VARCHAR(32) NOT NULL, '
            .'present_dans_sage BOOLEAN NOT NULL DEFAULT TRUE, '
            .'cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'vu_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'modifie_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'PRIMARY KEY (cle))',
        );
        $this->addSql('CREATE INDEX idx_creances_demo_bal_eloficash_present ON creances_demo.bal_eloficash (present_dans_sage)');

        // ----- vues UNION ALL exposees dans le schema `creances` --------
        // Le module ne lit jamais `mirror.X` ni `creances_demo.X` en direct :
        // il interroge `creances.v_X`. La PK `cle` etant garantie par chaque
        // table, l'UNION ALL ne risque pas de doublon si les conventions de
        // nommage de cle sont respectees (cle Sage en prod, cle prefixee
        // `DEMO_` cote demo).
        $this->addSql(
            'CREATE VIEW creances.v_tiers AS '
            .'SELECT cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le FROM mirror.tiers '
            .'UNION ALL '
            .'SELECT cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le FROM creances_demo.tiers',
        );
        $this->addSql(
            'CREATE VIEW creances.v_balance_agee AS '
            .'SELECT cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le FROM mirror.balance_agee '
            .'UNION ALL '
            .'SELECT cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le FROM creances_demo.balance_agee',
        );
        $this->addSql(
            'CREATE VIEW creances.v_bal_eloficash AS '
            .'SELECT cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le FROM mirror.bal_eloficash '
            .'UNION ALL '
            .'SELECT cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le FROM creances_demo.bal_eloficash',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS creances.v_bal_eloficash');
        $this->addSql('DROP VIEW IF EXISTS creances.v_balance_agee');
        $this->addSql('DROP VIEW IF EXISTS creances.v_tiers');
        $this->addSql('DROP SCHEMA IF EXISTS creances_demo CASCADE');
    }
}
