<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : table facture_pdf.
 *
 * Certaines factures echues n'ont pas de PDF cote Sage (chemin_pdf vide). On ne
 * peut pas ecrire dans Sage (lecture seule), donc le comptable televerse le PDF
 * manquant ici (cle = ecriture_id, l'identifiant unique de la ligne Sage). Le
 * moteur de relance utilise ce PDF a defaut du PDF Sage. Volume borne (quelques
 * centaines de factures au plus). Meme motif de stockage bytea que courrier_pdf.
 */
final class Version20260731130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table facture_pdf (PDF de facture televerse manuellement, cle ecriture_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.facture_pdf (
                id BIGSERIAL PRIMARY KEY,
                ecriture_id VARCHAR(64) NOT NULL,
                numpiece VARCHAR(128) DEFAULT NULL,
                compte_code VARCHAR(64) DEFAULT NULL,
                nom_fichier VARCHAR(255) NOT NULL,
                taille_octets INTEGER NOT NULL,
                contenu BYTEA NOT NULL,
                uploaded_par VARCHAR(255) DEFAULT NULL,
                uploaded_par_user_id BIGINT DEFAULT NULL,
                uploaded_le TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_facture_pdf_ecriture ON recouvrement.facture_pdf (ecriture_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recouvrement.facture_pdf');
    }
}
