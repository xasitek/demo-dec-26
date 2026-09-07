<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : suivi du telechargement du fichier SEPA par le manager (ecran
 * « Virements SEPA » : a telecharger / deja telecharges).
 */
final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : dossier.sepa_telecharge_le / sepa_telecharge_par (suivi virements manager).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier ADD sepa_telecharge_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE remboursement.dossier ADD sepa_telecharge_par VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier DROP sepa_telecharge_le');
        $this->addSql('ALTER TABLE remboursement.dossier DROP sepa_telecharge_par');
    }
}
