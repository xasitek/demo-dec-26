<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : journal des messages sortants (réponses des comptables aux
 * clients), pour les afficher dans l'historique des échanges avec leurs pièces
 * jointes (noms seulement, pas le binaire).
 */
final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table message_sortant (journal des réponses envoyées)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.message_sortant (
                id BIGSERIAL NOT NULL,
                retour_id INTEGER DEFAULT NULL,
                compte_code VARCHAR(255) DEFAULT NULL,
                ecriture_id VARCHAR(255) DEFAULT NULL,
                destinataire VARCHAR(255) NOT NULL,
                sujet TEXT DEFAULT NULL,
                corps_html TEXT DEFAULT NULL,
                cc TEXT DEFAULT NULL,
                cci TEXT DEFAULT NULL,
                pieces_jointes JSONB NOT NULL DEFAULT '[]'::jsonb,
                auteur VARCHAR(255) DEFAULT NULL,
                envoye_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_message_sortant_compte ON recouvrement.message_sortant (compte_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recouvrement.message_sortant');
    }
}
