<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : PDF de courrier pré-fusionné (relevé + factures).
 *
 * Le web (Render) ne peut ni joindre Sage ni fusionner (Ghostscript).
 * La machine interne (mRemote, réseau Synthauto) génère donc le PDF complet du courrier
 * et le stocke ici ; le web le sert tel quel. Volume borné (peu de clients papier).
 */
final class Version20260722110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : colonne courrier_pdf (releve + factures pre-fusionne cote machine interne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_pdf BYTEA DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_pdf');
    }
}
