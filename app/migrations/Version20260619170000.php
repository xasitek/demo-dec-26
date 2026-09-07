<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Presence : reglages d'affichage par utilisateur.
 *  - presence_visible : apparait dans la pile d'avatars (reglable par le manager) ;
 *  - presence_forcee_en_ligne : affiche toujours "en ligne" (reglage personnel).
 */
final class Version20260619170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Presence : colonnes presence_visible et presence_forcee_en_ligne sur users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.users ADD COLUMN presence_visible BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE shared.users ADD COLUMN presence_forcee_en_ligne BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.users DROP COLUMN IF EXISTS presence_visible');
        $this->addSql('ALTER TABLE shared.users DROP COLUMN IF EXISTS presence_forcee_en_ligne');
    }
}
