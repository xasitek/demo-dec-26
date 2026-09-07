<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement / perf : index d'expression sur mirror.tiers pour la jointure de
 * la vue v_impayes (tiers.donnees->>'code' = bal_eloficash.donnees->>'Code relancé').
 *
 * Sans cet index, PostgreSQL sous-estime la selectivite des filtres JSONB et
 * choisit une jointure par hachage sur des lignes tres larges (le JSONB donnees),
 * dont les fichiers temporaires debordent le disque de la petite instance
 * (SQLSTATE 53100 "No space left on device" lors de app:recouvrement:preparer).
 *
 * L'index (partiel sur present_dans_sage, comme la jointure) permet une jointure
 * imbriquee par index : les lignes de bal_eloficash sont diffusees et chaque tiers
 * est retrouve par index -> plus de gros hash, plus de debordement en pgsql_tmp.
 */
final class Version20260707100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement/perf : index mirror.tiers((donnees->>code)) pour la jointure v_impayes (evite le hash join qui sature le disque)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_tiers_donnees_code
            ON mirror.tiers ((donnees->>'code'))
            WHERE present_dans_sage
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS mirror.idx_tiers_donnees_code');
    }
}
