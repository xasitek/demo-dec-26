<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : table MIRROR des vehicules Buy Back (engagements de reprise), copie
 * locale en lecture seule alimentee depuis la base externe par app:remboursement:import-buyback.
 * Sert au controle anti-surpaiement au depot d'un rachat sec (montant vs er_ttc de la plaque).
 */
final class Version20260813100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : table mirror buyback_vehicule (engagements de reprise).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE remboursement.buyback_vehicule (
                id BIGSERIAL NOT NULL,
                immat VARCHAR(32) NOT NULL,
                immatriculation VARCHAR(32) DEFAULT NULL,
                vin VARCHAR(32) DEFAULT NULL,
                marque VARCHAR(80) DEFAULT NULL,
                modele VARCHAR(120) DEFAULT NULL,
                contrat VARCHAR(64) DEFAULT NULL,
                financeur VARCHAR(120) DEFAULT NULL,
                type_fi VARCHAR(64) DEFAULT NULL,
                client VARCHAR(180) DEFAULT NULL,
                er_ht NUMERIC(12, 2) DEFAULT NULL,
                er_ttc NUMERIC(12, 2) DEFAULT NULL,
                date_echeance DATE DEFAULT NULL,
                km_contrat INT DEFAULT NULL,
                statut VARCHAR(16) DEFAULT NULL,
                importe_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_remb_buyback_immat ON remboursement.buyback_vehicule (immat)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE remboursement.buyback_vehicule');
    }
}
