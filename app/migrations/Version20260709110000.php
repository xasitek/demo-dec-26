<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : remise a zero du cycle de relance apres solde.
 *
 * Probleme : l'escalade lit TOUS les niveaux deja envoyes a un compte. Un compte
 * relance (niveaux 1-2-3) puis solde puis RE-FACTURE n'est plus jamais relance
 * (le systeme croit tous les niveaux epuises).
 *
 * Solution : une colonne cycle_clos. Quand un compte repasse a zero impaye, ses
 * relances sont marquees cycle_clos=true (archivees, PAS supprimees -> la mise en
 * demeure reste une preuve). L'escalade et l'anti-doublon ne comptent que les
 * relances a cycle_clos=false -> une nouvelle dette repart au niveau 1.
 *
 * Les index uniques partiels sont donc restreints a cycle_clos=false pour ne pas
 * bloquer une nouvelle relance (compte/facture, niveau) apres un cycle archive.
 */
final class Version20260709110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : colonne cycle_clos (remise a zero du cycle apres solde) + index anti-doublon restreints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD cycle_clos BOOLEAN DEFAULT FALSE NOT NULL');

        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_compte_niveau');
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_facture_niveau');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_compte_niveau
            ON recouvrement.relance_envoi (compte_code, niveau)
            WHERE statut = 'envoye' AND ecriture_id IS NULL AND cycle_clos = FALSE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_facture_niveau
            ON recouvrement.relance_envoi (ecriture_id, niveau)
            WHERE statut = 'envoye' AND ecriture_id IS NOT NULL AND cycle_clos = FALSE
        SQL);
        // Index d'appui pour la cloture de cycle (recherche des relances actives).
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_relance_cycle_actif
            ON recouvrement.relance_envoi (compte_code)
            WHERE statut = 'envoye' AND cycle_clos = FALSE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.idx_relance_cycle_actif');
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_facture_niveau');
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_compte_niveau');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_compte_niveau
            ON recouvrement.relance_envoi (compte_code, niveau)
            WHERE statut = 'envoye' AND ecriture_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_facture_niveau
            ON recouvrement.relance_envoi (ecriture_id, niveau)
            WHERE statut = 'envoye' AND ecriture_id IS NOT NULL
        SQL);
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP cycle_clos');
    }
}
