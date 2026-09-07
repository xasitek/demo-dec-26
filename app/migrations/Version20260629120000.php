<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : cadence de relance EDITABLE (table cadence_palier).
 *
 * Sort les paliers (jours par niveau + mise en demeure) du code vers la base,
 * pour les rendre modifiables depuis l'espace admin (onglet Strategies). La
 * table est semee avec les valeurs jusqu'ici figees dans CadenceRelance.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table cadence_palier (cadence de relance editable) + valeurs par defaut';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.cadence_palier (
                id BIGSERIAL NOT NULL,
                profil VARCHAR(255) NOT NULL,
                niveau SMALLINT NOT NULL,
                jours INT NOT NULL,
                mise_en_demeure BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_cadence_profil_niveau ON recouvrement.cadence_palier (profil, niveau)');

        // Valeurs par defaut (anciennes constantes de CadenceRelance).
        $defauts = [
            ['comptant', 1, 3, 'false'], ['comptant', 2, 10, 'false'], ['comptant', 3, 25, 'true'],
            ['trente_j_fdm', 1, 7, 'false'], ['trente_j_fdm', 2, 21, 'false'], ['trente_j_fdm', 3, 45, 'true'],
            ['soixante_j_fdm', 1, 10, 'false'], ['soixante_j_fdm', 2, 30, 'false'], ['soixante_j_fdm', 3, 60, 'true'],
            ['standard', 1, 7, 'false'], ['standard', 2, 20, 'false'], ['standard', 3, 40, 'true'],
        ];
        foreach ($defauts as [$profil, $niveau, $jours, $med]) {
            $this->addSql(sprintf(
                "INSERT INTO recouvrement.cadence_palier (profil, niveau, jours, mise_en_demeure) VALUES ('%s', %d, %d, %s)",
                $profil,
                $niveau,
                $jours,
                $med,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recouvrement.cadence_palier');
    }
}
