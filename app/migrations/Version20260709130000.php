<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : journal des accuses automatiques aux emails SPONTANES (non
 * rattaches a une relance). Une ligne par expediteur -> garantit qu'on ne repond
 * automatiquement qu'UNE fois par expediteur et par fenetre (anti-boucle, anti-spam).
 */
final class Version20260709130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table auto_reponse_spontanee (accuse auto des mails spontanes, une fois par expediteur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.auto_reponse_spontanee (
                expediteur VARCHAR(255) NOT NULL,
                envoye_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (expediteur)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recouvrement.auto_reponse_spontanee');
    }
}
