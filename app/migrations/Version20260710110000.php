<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : index sur mirror.tiers (code) pour le refresh de v_impayes.
 *
 * v_impayes joint les tiers via `LEFT JOIN LATERAL (... WHERE t.donnees->>'code'
 * = src.donnees->>'Code relancé' ... LIMIT 1)`. Sans index sur cette expression,
 * chaque ligne (~45 000) refait un seq scan de mirror.tiers (~27 000 lignes) ->
 * le REFRESH MATERIALIZED VIEW peut prendre des dizaines de minutes (constate :
 * refresh bloque > 30 min). Avec l'index, la sous-requete laterale devient un
 * index scan -> refresh en quelques secondes. Indispensable aussi en prod (le
 * refresh nocturne subirait le meme blocage).
 *
 * Index partiel sur present_dans_sage = true : c'est le seul sous-ensemble que la
 * jointure interroge, et ca reste petit.
 */
final class Version20260710110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : index mirror.tiers(code) pour accelerer le refresh de v_impayes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_tiers_code ON mirror.tiers ((donnees->>'code')) WHERE present_dans_sage");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS mirror.idx_tiers_code');
    }
}
