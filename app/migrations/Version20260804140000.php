<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : fiabilisation des lancements manuels de strategie.
 *
 * - dernier_signe_le : heartbeat mis a jour a chaque palier ; permet de detecter
 *   un run "zombie" (worker tue avant terminer()) sans figer l'UI indefiniment.
 * - index unique partiel : un seul run EN_COURS a la fois par regle (anti double
 *   lancement concurrent / double-clic).
 */
final class Version20260804140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : heartbeat + unicite du run en cours par regle (anti-zombie / anti-doublon)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.preparation_run ADD COLUMN dernier_signe_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Amorce les runs existants pour que les anciens tests ne soient pas vus comme zombies.
        $this->addSql('UPDATE recouvrement.preparation_run SET dernier_signe_le = COALESCE(termine_le, lance_le)');
        // Un seul run EN_COURS par regle (regle_id NULL = distinct en unique index, sans effet).
        $this->addSql("CREATE UNIQUE INDEX uniq_preparation_run_regle_en_cours ON recouvrement.preparation_run (regle_id) WHERE statut = 'en_cours'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_preparation_run_regle_en_cours');
        $this->addSql('ALTER TABLE recouvrement.preparation_run DROP COLUMN IF EXISTS dernier_signe_le');
    }
}
