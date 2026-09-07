<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : trace de l'operateur ayant poste un courrier (vecteur COURRIER).
 *
 * V1 "relance par courrier" pour les clients sans email : la relance est preparee
 * en statut A_ENVOYER (vecteur COURRIER), le comptable telecharge la lettre PDF,
 * la poste, puis coche "envoye" dans le module. On trace alors QUI a marque
 * l'envoi (envoye_par) en plus de QUAND (envoye_le, deja present).
 *
 * Colonne nullable : les envois email automatiques n'ont aucun operateur humain
 * et conservent donc envoye_par = NULL.
 */
final class Version20260626160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : ajout colonne envoye_par sur relance_envoi (operateur ayant poste un courrier)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN envoye_par VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN envoye_par');
    }
}
