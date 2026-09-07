<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : capture des pieces jointes et du CC des reponses clients.
 *
 * - colonne cc sur retour_client (adresses en copie visibles du mail recu) ;
 * - table retour_piece_jointe : contenu binaire stocke en base (justificatifs de
 *   quelques Mo), supprime en cascade avec le retour.
 *
 * NB : le CCI (BCC) n'est pas capturable (retire par les serveurs mail avant
 * livraison, invisible du destinataire).
 */
final class Version20260709100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : cc + pieces jointes des retours clients';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.retour_client ADD cc TEXT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.retour_piece_jointe (
                id BIGSERIAL NOT NULL,
                retour_id BIGINT NOT NULL,
                nom VARCHAR(255) NOT NULL,
                type_mime VARCHAR(180) DEFAULT NULL,
                taille INT NOT NULL,
                contenu BYTEA NOT NULL,
                cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_retour_piece_retour ON recouvrement.retour_piece_jointe (retour_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE recouvrement.retour_piece_jointe
            ADD CONSTRAINT fk_retour_piece_retour FOREIGN KEY (retour_id)
            REFERENCES recouvrement.retour_client (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recouvrement.retour_piece_jointe');
        $this->addSql('ALTER TABLE recouvrement.retour_client DROP cc');
    }
}
