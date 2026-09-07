<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : champs CLIENT modifies par la secretaire lors d'une correction
 * (liste de cles), pour que le comptable les repere sur la page de verification.
 */
final class Version20260812200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : colonne correction_champs (champs modifies par la secretaire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier ADD COLUMN correction_champs JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier DROP COLUMN correction_champs');
    }
}
