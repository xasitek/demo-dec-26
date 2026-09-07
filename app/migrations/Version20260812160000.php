<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared : compte de contrepartie comptable (« cinq » de l'onglet donnees, classe
 * 512xxxx) sur shared.etablissement, utilise dans l'ecriture OD de remboursement
 * (CSV Eloficash). Additif et nullable : aucun impact sur l'existant.
 */
final class Version20260812160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : compte de contrepartie (cinq) sur etablissement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.etablissement ADD compte_contrepartie VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.etablissement DROP compte_contrepartie');
    }
}
