<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : table preparation_run (suivi des lancements manuels de relances).
 *
 * Permet une barre de progression persistante (survit au changement de page) pour
 * le bouton "Lancer maintenant" d'une strategie : total, nombre traite, statut.
 */
final class Version20260804130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table preparation_run (suivi des lancements manuels de relances)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.preparation_run (
                id BIGSERIAL PRIMARY KEY,
                regle_id BIGINT DEFAULT NULL,
                regle_nom VARCHAR(120) NOT NULL,
                statut VARCHAR(255) NOT NULL,
                total INTEGER NOT NULL DEFAULT 0,
                traites INTEGER NOT NULL DEFAULT 0,
                lance_par VARCHAR(255) DEFAULT NULL,
                lance_le TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                termine_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_preparation_run_statut ON recouvrement.preparation_run (statut)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recouvrement.preparation_run');
    }
}
