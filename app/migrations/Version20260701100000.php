<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Utilisateurs : pole d'affectation des comptables (General / Fournisseur /
 * Client / Banque / Constructeur). Colonne nullable : n'a de sens que pour les
 * comptables, null pour les autres roles.
 */
final class Version20260701100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilisateurs : ajout colonne pole_comptable (specialisation des comptables)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.users ADD COLUMN pole_comptable VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.users DROP COLUMN pole_comptable');
    }
}
