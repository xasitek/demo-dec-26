<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Activite : table shared.activite_jour. Temps de presence active agrege par
 * utilisateur et par jour (suivi teletravail). Une ligne par (user, jour),
 * incrementee par le heartbeat de presence. Donnee de surveillance : voir le
 * Lot 3 (purge a 6 mois) et docs/SECURITY.md.
 */
final class Version20260619153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activite : table activite_jour (temps de presence active agrege par user/jour)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared.activite_jour (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES shared.users(id) ON DELETE CASCADE,
                jour DATE NOT NULL,
                secondes_actives INT NOT NULL DEFAULT 0,
                derniere_activite TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_activite_jour_user_jour UNIQUE (user_id, jour)
            )
        SQL);

        // Lookup principal du dashboard : agreger sur une plage de jours.
        $this->addSql('CREATE INDEX idx_activite_jour_jour ON shared.activite_jour (jour)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS shared.activite_jour');
    }
}
