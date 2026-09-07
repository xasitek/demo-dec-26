<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : table de reference des RIB par etablissement SYNTHAUTO.
 *
 * Alimentee (seedee) depuis un fichier hors-repo via
 * `app:recouvrement:importer-rib` (les IBAN ne sont JAMAIS versionnes). Sert a
 * afficher, dans les relances, le compte SYNTHAUTO sur lequel le client doit virer,
 * avec sa reference dossier en libelle. Cle = code etablissement normalise
 * (entier ; v_impayes.codeetab est zero-padde, on compare en entier).
 */
final class Version20260728130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table rib_etablissement (RIB SYNTHAUTO par etablissement, seed hors-repo)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.rib_etablissement (
                code_etab INTEGER NOT NULL,
                titulaire VARCHAR(160) NOT NULL DEFAULT '',
                banque VARCHAR(60) NOT NULL DEFAULT '',
                iban VARCHAR(40) NOT NULL,
                bic VARCHAR(20) NOT NULL DEFAULT '',
                maj_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (code_etab)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recouvrement.rib_etablissement');
    }
}
