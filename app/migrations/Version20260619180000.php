<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications : table shared.notification (socle de notifications in-app par
 * utilisateur). Voir docs/REALTIME.md.
 */
final class Version20260619180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications : table notification (socle in-app par utilisateur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared.notification (
                id BIGSERIAL PRIMARY KEY,
                destinataire_id BIGINT NOT NULL REFERENCES shared.users(id) ON DELETE CASCADE,
                type VARCHAR(30) NOT NULL,
                titre VARCHAR(255) NOT NULL,
                message TEXT DEFAULT NULL,
                url VARCHAR(1024) DEFAULT NULL,
                lu_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->addSql('CREATE INDEX idx_notification_dest ON shared.notification (destinataire_id, created_at)');
        $this->addSql('CREATE INDEX idx_notification_non_lue ON shared.notification (destinataire_id, lu_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS shared.notification');
    }
}
