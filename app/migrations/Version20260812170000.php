<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared : auteur du dernier passage sur shared.etablissement (modifie_par),
 * pour tracer qui a edite la fiche (le modifie_le existe deja). Additif, nullable.
 */
final class Version20260812170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : etablissement.modifie_par (auteur du dernier passage)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.etablissement ADD modifie_par VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.etablissement DROP modifie_par');
    }
}
