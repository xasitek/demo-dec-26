<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bonus Eco : schema dedie + table dossier_asp (miroir du Google Sheet ASP).
 *
 * L'ASP (Agence de Services et de Paiement) publie l'etat des demandes de bonus
 * ecologique transmises par Synthauto. On le synchronise dans une table interne,
 * cle naturelle (num_chassis, num_dossier_mensuel). Pattern upsert (jamais de
 * delete) pour garder trace meme si une ligne disparait du sheet.
 */
final class Version20260608100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BonusEco : schema bonus_eco + table dossier_asp (miroir sheet ASP)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS bonus_eco');

        $this->addSql(<<<'SQL'
            CREATE TABLE bonus_eco.dossier_asp (
                id BIGSERIAL PRIMARY KEY,
                num_chassis VARCHAR(50) NOT NULL,
                num_dossier_mensuel VARCHAR(50) NOT NULL,
                denom_soc VARCHAR(255) DEFAULT NULL,
                num_siret VARCHAR(20) DEFAULT NULL,
                num_vente VARCHAR(50) DEFAULT NULL,
                immatriculation VARCHAR(20) DEFAULT NULL,
                nom_acquereur VARCHAR(255) DEFAULT NULL,
                code_postal VARCHAR(10) DEFAULT NULL,
                ville VARCHAR(100) DEFAULT NULL,
                modele_vehicule VARCHAR(255) DEFAULT NULL,
                cnit VARCHAR(50) DEFAULT NULL,
                date_acquisition DATE DEFAULT NULL,
                taux_co2 NUMERIC(6, 2) DEFAULT NULL,
                mt_bonus NUMERIC(10, 2) DEFAULT NULL,
                mt_or_bonus VARCHAR(50) DEFAULT NULL,
                mt_sup_bonus NUMERIC(10, 2) DEFAULT NULL,
                mt_or_sup_bonus VARCHAR(50) DEFAULT NULL,
                mt_prime_casse NUMERIC(10, 2) DEFAULT NULL,
                mt_or_prime_casse VARCHAR(50) DEFAULT NULL,
                mt_prime_conversion NUMERIC(10, 2) DEFAULT NULL,
                mt_or_prime_conversion VARCHAR(50) DEFAULT NULL,
                mt_leasing NUMERIC(10, 2) DEFAULT NULL,
                mt_or_leasing VARCHAR(50) DEFAULT NULL,
                mt_paye NUMERIC(10, 2) DEFAULT NULL,
                date_paie_bonus DATE DEFAULT NULL,
                date_paie_super_bonus DATE DEFAULT NULL,
                date_paie_prime_casse DATE DEFAULT NULL,
                date_paie_prime_conversion DATE DEFAULT NULL,
                date_paie_leasing DATE DEFAULT NULL,
                numero_or VARCHAR(50) DEFAULT NULL,
                login VARCHAR(100) DEFAULT NULL,
                date_creation DATE DEFAULT NULL,
                lib_etat VARCHAR(50) DEFAULT NULL,
                present_dans_sheet BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        // Cle naturelle : un dossier ASP = (chassis, n° dossier mensuel)
        $this->addSql('CREATE UNIQUE INDEX uniq_asp_chassis_dossier ON bonus_eco.dossier_asp (num_chassis, num_dossier_mensuel)');

        // Lookup principal : matching depuis une ecriture Sage par VIN
        $this->addSql('CREATE INDEX idx_asp_chassis ON bonus_eco.dossier_asp (num_chassis)');

        // Filtres dashboard
        $this->addSql('CREATE INDEX idx_asp_lib_etat ON bonus_eco.dossier_asp (lib_etat)');
        $this->addSql('CREATE INDEX idx_asp_present ON bonus_eco.dossier_asp (present_dans_sheet)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS bonus_eco.dossier_asp');
        $this->addSql('DROP SCHEMA IF EXISTS bonus_eco');
    }
}
