<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration de fondation : creation des schemas Postgres du multi-schemas.
 * Voir docs/ARCHITECTURE.md.
 */
final class Version20260527070932 extends AbstractMigration
{
    /**
     * @var list<string>
     */
    private const SCHEMAS = ['mirror', 'shared', 'garanties', 'creances', 'rappels', 'chat'];

    public function getDescription(): string
    {
        return 'Creation des schemas Postgres (mirror, shared, garanties, creances, rappels, chat)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Cette migration ne s applique qu a PostgreSQL.'
        );

        foreach (self::SCHEMAS as $name) {
            $this->addSql(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $name));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SCHEMAS as $name) {
            $this->addSql(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $name));
        }
    }
}
