<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Legal : table shared.legal_acceptance. Trace l'acceptation / prise de
 * connaissance des documents legaux (CGU, confidentialite) par utilisateur et
 * par version, comme preuve d'information (RGPD). Voir docs/SECURITY.md.
 */
final class Version20260619160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legal : table legal_acceptance (preuve d acceptation CGU / confidentialite par version)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared.legal_acceptance (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES shared.users(id) ON DELETE CASCADE,
                document VARCHAR(50) NOT NULL,
                version VARCHAR(30) NOT NULL,
                accepted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip VARCHAR(45) DEFAULT NULL,
                CONSTRAINT uniq_legal_user_doc_version UNIQUE (user_id, document, version)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_legal_user ON shared.legal_acceptance (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS shared.legal_acceptance');
    }
}
