<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Espace Livraison — la declaration de livraison et ses pieces.
 *
 * Ce sont les deux seules tables de donnees du module : tout le reste est derive de
 * `mirror.bal_eloficash` par la vue `v_a_livrer`. Elles portent exactement ce que les
 * humains ajoutent au circuit — l'acte de declarer, et les pieces jointes.
 *
 * `identifiant_vehicule` est unique : un vehicule se declare une fois. C'est la regle
 * que le tableur appliquait par dedoublonnage sur l'immatriculation, ici tenue par la
 * base plutot que par un script.
 *
 * Les pieces sont stockees en `bytea` et non sur disque : sur Render le web et le
 * worker sont deux services sans disque partage, la base est le seul stockage que les
 * deux voient — et le disque d'un service Render est ephemere.
 */
final class Version20260901160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraison : tables declaration et piece (declaration de livraison par la secretaire)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE livraison.declaration_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE livraison.piece_id_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE livraison.declaration (
                id                   BIGINT       NOT NULL,
                identifiant_vehicule VARCHAR(32)  NOT NULL,
                immatriculation      VARCHAR(16)  DEFAULT NULL,
                vin                  VARCHAR(32)  DEFAULT NULL,
                loueur               VARCHAR(64)  NOT NULL,
                code_payeur          VARCHAR(32)  NOT NULL,
                code_etab            VARCHAR(8)   NOT NULL,
                declaree_par         VARCHAR(180) NOT NULL,
                declaree_le          TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                statut               VARCHAR(24)  NOT NULL,
                commentaire          TEXT         DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_livr_decl_vehicule ON livraison.declaration (identifiant_vehicule)');
        $this->addSql('CREATE INDEX idx_livr_decl_etab ON livraison.declaration (code_etab)');
        $this->addSql('CREATE INDEX idx_livr_decl_par ON livraison.declaration (declaree_par)');
        $this->addSql('CREATE INDEX idx_livr_decl_statut ON livraison.declaration (statut)');

        $this->addSql("COMMENT ON COLUMN livraison.declaration.identifiant_vehicule IS 'Cle de rapprochement avec livraison.v_a_livrer : immatriculation, ou les 8 derniers caracteres du VIN si le vehicule n''est pas encore immatricule.'");

        $this->addSql(<<<'SQL'
            CREATE TABLE livraison.piece (
                id             BIGINT       NOT NULL,
                declaration_id BIGINT       NOT NULL,
                type           VARCHAR(24)  NOT NULL,
                nom_fichier    VARCHAR(255) NOT NULL,
                contenu        BYTEA        NOT NULL,
                mime_type      VARCHAR(100) NOT NULL,
                taille_octets  INT          NOT NULL,
                hash           VARCHAR(64)  DEFAULT NULL,
                uploade_le     TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_livr_piece_decl ON livraison.piece (declaration_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_livr_piece_type ON livraison.piece (declaration_id, type)');
        $this->addSql('ALTER TABLE livraison.piece ADD CONSTRAINT fk_livr_piece_decl FOREIGN KEY (declaration_id) REFERENCES livraison.declaration (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE livraison.piece DROP CONSTRAINT IF EXISTS fk_livr_piece_decl');
        $this->addSql('DROP TABLE IF EXISTS livraison.piece');
        $this->addSql('DROP TABLE IF EXISTS livraison.declaration');
        $this->addSql('DROP SEQUENCE IF EXISTS livraison.piece_id_seq');
        $this->addSql('DROP SEQUENCE IF EXISTS livraison.declaration_id_seq');
    }
}
