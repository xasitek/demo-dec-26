<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : message libre de la comptable au SITE, par facture (chantier
 * « relance au site »). Colonne `note_site` sur recouvrement.facture_site : le
 * contexte / l'action attendue, saisi au moment du gel « relance site » et repris
 * dans l'e-mail de relance site et la page de reponse. Colonne nullable : aucun
 * impact sur les lignes existantes. Voir docs/RECOUVREMENT_RELANCE_SITE.md.
 */
final class Version20260807100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : facture_site.note_site (message comptable -> site, par facture)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.facture_site ADD note_site TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement.facture_site DROP note_site');
    }
}
