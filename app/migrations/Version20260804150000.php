<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : suivi de l'envoi POSTAL d'une relance (vecteur COURRIER, via un
 * prestataire type Maileva). Colonnes additives sur relance_envoi :
 *   - courrier_provider : prestataire ("maileva", "simule"...) ;
 *   - courrier_ref : reference renvoyee par le prestataire (pour le suivi) ;
 *   - courrier_type : produit postal (lettre / suivi / recommande AR) ;
 *   - courrier_statut : etat normalise (depose / poste / distribue / ...) ;
 *   - courrier_ar : accuse de reception / preuve (document renvoye, BYTEA) ;
 *   - courrier_maj_le : date de derniere mise a jour du statut.
 */
final class Version20260804150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : colonnes de suivi de l\'envoi postal sur relance_envoi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_provider VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_ref VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_type VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_statut VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_ar BYTEA DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN courrier_maj_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_provider');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_ref');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_type');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_statut');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_ar');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN IF EXISTS courrier_maj_le');
    }
}
