<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Garanties — index de performance pour la requête de réconciliation.
 *
 * Contexte : la page d'audit (ReconciliationRepository::sqlBase) fait un
 * LEFT JOIN LATERAL sur garanties.dossier avec `WHERE chassis = e.chassis`
 * pour chaque écriture Sage, et filtre mirror.bal_eloficash sur
 * `donnees->>'collectif' = '4116000'`. Sans index, cela donne un seq scan de
 * garanties.dossier par ligne + un seq scan complet du mirror : tenable sur une
 * machine de dev, mais > 30 s sur une petite instance Postgres de prod
 * (timeout PHP, 500). Voir docs/PERFORMANCE.md.
 *
 * Index ajoutés :
 *   - garanties.dossier(chassis)   : jointure principale (chassis = e.chassis)
 *   - garanties.dossier(numero_or) : jointure par OR (page détail)
 *   - mirror.bal_eloficash((donnees->>'collectif'), present_dans_sage) :
 *     restreint le scan au compte 4116000 et à la vue lettré/non lettré.
 */
final class Version20260617120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garanties : index de perf (dossier.chassis, dossier.numero_or, bal_eloficash collectif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_dossier_chassis ON garanties.dossier (chassis)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_dossier_numero_or ON garanties.dossier (numero_or)');
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_bal_eloficash_collectif ON mirror.bal_eloficash ((donnees->>'collectif'), present_dans_sage)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS garanties.idx_dossier_chassis');
        $this->addSql('DROP INDEX IF EXISTS garanties.idx_dossier_numero_or');
        $this->addSql('DROP INDEX IF EXISTS mirror.idx_bal_eloficash_collectif');
    }
}
