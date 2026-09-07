<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Remboursement client — index aveugle IBAN. Ajoute `iban_hash` (empreinte
 * HMAC deterministe de l'IBAN retenu) + index, pour detecter deux dossiers portant
 * le MEME compte bancaire sans jamais stocker/indexer l'IBAN en clair (RGPD).
 *
 * Additive et nullable : sans impact sur l'existant. Le remplissage des dossiers
 * deja presents se fait via un re-enregistrement (ou la commande de donnees-demo).
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : index aveugle IBAN (iban_hash) pour la detection "meme compte" entre dossiers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier ADD iban_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_remb_dossier_iban_hash ON remboursement.dossier (iban_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX remboursement.idx_remb_dossier_iban_hash');
        $this->addSql('ALTER TABLE remboursement.dossier DROP iban_hash');
    }
}
