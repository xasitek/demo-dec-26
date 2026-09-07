<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : seuil de montant minimum de relance CONFIGURABLE par strategie.
 *
 * Jusqu'ici le montant net minimum (100 EUR TTC) etait code en dur dans
 * SelectionRelanceService. Il devient un champ par regle (montant_min), defaut 100
 * pour ne rien changer aux strategies existantes. 0 = pas de seuil.
 */
final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : seuil de montant minimum de relance par strategie (regle_relance.montant_min)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.regle_relance ADD COLUMN montant_min INTEGER NOT NULL DEFAULT 100');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.regle_relance DROP COLUMN IF EXISTS montant_min');
    }
}
