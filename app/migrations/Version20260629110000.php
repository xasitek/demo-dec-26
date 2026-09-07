<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : valeur probante du marquage (mise en demeure niveau 3).
 *
 * Ajoute l'identifiant IMMUABLE de l'operateur ayant marque un courrier comme
 * poste (en plus de son nom d'affichage envoye_par, modifiable et non probant).
 * Le contenu reellement emis est fige a part dans corps_html (snapshot pose au
 * marquage cote applicatif) : ensemble, ils permettent de prouver qui a poste
 * quoi et quand, sans dependre d'une regeneration ulterieure.
 */
final class Version20260629110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : colonne envoye_par_user_id sur relance_envoi (valeur probante)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN envoye_par_user_id BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN envoye_par_user_id');
    }
}
