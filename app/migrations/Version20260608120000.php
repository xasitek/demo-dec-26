<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bonus Eco : table bonus_eco.relance pour tracer les relances envoyees.
 *
 * Une relance = un email envoye au vendeur ou a la secretaire pour faire
 * avancer un dossier ASP. On garde la trace pour la traçabilite RGPD et
 * pour eviter les doublons / spam.
 */
final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BonusEco : table relance (log des emails de relance envoyes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE bonus_eco.relance (
                id BIGSERIAL PRIMARY KEY,
                numero_ecriture_sage BIGINT NOT NULL,
                num_chassis VARCHAR(50) DEFAULT NULL,
                destinataire_type VARCHAR(20) NOT NULL,
                destinataire_email VARCHAR(255) NOT NULL,
                destinataire_nom VARCHAR(255) DEFAULT NULL,
                auteur_id BIGINT NOT NULL REFERENCES shared.users(id) ON DELETE RESTRICT,
                envoye_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sujet VARCHAR(500) NOT NULL,
                corps TEXT NOT NULL,
                erreur TEXT DEFAULT NULL
            )
        SQL);

        // Lookup principal : afficher les relances d'une ecriture donnee.
        $this->addSql('CREATE INDEX idx_relance_ecriture ON bonus_eco.relance (numero_ecriture_sage)');
        $this->addSql('CREATE INDEX idx_relance_chassis ON bonus_eco.relance (num_chassis)');
        $this->addSql('CREATE INDEX idx_relance_auteur ON bonus_eco.relance (auteur_id)');
        $this->addSql('CREATE INDEX idx_relance_date ON bonus_eco.relance (envoye_le)');

        // Garde-fou : type destinataire = vendeur | secretaire
        $this->addSql("ALTER TABLE bonus_eco.relance ADD CONSTRAINT chk_destinataire_type CHECK (destinataire_type IN ('vendeur', 'secretaire'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS bonus_eco.relance');
    }
}
