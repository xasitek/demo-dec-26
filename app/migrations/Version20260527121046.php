<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tables de staging du mirror Sage : copie fidele (JSONB) des tables source,
 * avec colonnes de gestion (presence, horodatage). Voir docs/ARCHITECTURE.md.
 */
final class Version20260527121046 extends AbstractMigration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['bal_eloficash', 'balance_agee', 'tiers', 'reporting_ecritures'];

    public function getDescription(): string
    {
        return 'Tables mirror (staging Sage) : bal_eloficash, balance_agee, tiers, reporting_ecritures';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf(
                'CREATE TABLE mirror.%s ('
                .'cle VARCHAR(255) NOT NULL, '
                .'donnees JSONB NOT NULL, '
                .'content_hash VARCHAR(32) NOT NULL, '
                .'present_dans_sage BOOLEAN DEFAULT true NOT NULL, '
                .'cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
                .'vu_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
                .'modifie_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
                .'PRIMARY KEY (cle))',
                $table,
            ));
            $this->addSql(sprintf('CREATE INDEX idx_%s_present ON mirror.%s (present_dans_sage)', $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('DROP TABLE mirror.%s', $table));
        }
    }
}
