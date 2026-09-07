<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared : coordonnees bancaires du DEBITEUR (compte SYNTHAUTO de l'etablissement) sur
 * shared.etablissement, pour le virement SEPA du module Remboursement client
 * (nom legal beneficiaire, IBAN, BIC, banque). Source metier = onglet `donnees`
 * du Sheet remboursement. Additif et nullable : aucun impact sur l'existant.
 */
final class Version20260812150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : coordonnees bancaires debiteur SEPA sur etablissement (nom_legal_sepa, iban, bic, banque)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.etablissement ADD nom_legal_sepa VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE shared.etablissement ADD iban VARCHAR(34) DEFAULT NULL');
        $this->addSql('ALTER TABLE shared.etablissement ADD bic VARCHAR(11) DEFAULT NULL');
        $this->addSql('ALTER TABLE shared.etablissement ADD banque VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.etablissement DROP nom_legal_sepa');
        $this->addSql('ALTER TABLE shared.etablissement DROP iban');
        $this->addSql('ALTER TABLE shared.etablissement DROP bic');
        $this->addSql('ALTER TABLE shared.etablissement DROP banque');
    }
}
