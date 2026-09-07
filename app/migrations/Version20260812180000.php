<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : journal des extractions IA (extraction_piece) — audit Gemini +
 * telemetrie (tokens, cout, latence). Voir docs/MODULE_REMBOURSEMENT.md (4.4.3).
 */
final class Version20260812180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : table extraction_piece (journal extraction IA)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE remboursement.extraction_piece (
                id BIGSERIAL NOT NULL,
                dossier_id BIGINT NOT NULL,
                piece_id BIGINT DEFAULT NULL,
                type_piece VARCHAR(32) NOT NULL,
                statut VARCHAR(20) NOT NULL,
                piece_hash VARCHAR(64) DEFAULT NULL,
                provider VARCHAR(40) DEFAULT NULL,
                modele VARCHAR(60) DEFAULT NULL,
                gabarit_version VARCHAR(40) NOT NULL,
                resultat_brut JSON DEFAULT NULL,
                champs_extraits JSON DEFAULT NULL,
                message_erreur TEXT DEFAULT NULL,
                tokens_entree INT DEFAULT NULL,
                tokens_sortie INT DEFAULT NULL,
                latence_ms INT DEFAULT NULL,
                cree_le TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                termine_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_remb_extraction_dossier ON remboursement.extraction_piece (dossier_id)');
        $this->addSql('ALTER TABLE remboursement.extraction_piece ADD CONSTRAINT fk_remb_extraction_dossier FOREIGN KEY (dossier_id) REFERENCES remboursement.dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE remboursement.extraction_piece ADD CONSTRAINT fk_remb_extraction_piece FOREIGN KEY (piece_id) REFERENCES remboursement.dossier_piece (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE remboursement.extraction_piece');
    }
}
