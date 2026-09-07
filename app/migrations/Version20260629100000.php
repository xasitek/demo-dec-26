<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : curation des comptes relancables (table compte_exclusion).
 *
 * Reprend la logique du module Creances du manager : aucun champ Sage ne separe
 * proprement un vrai client a relancer d'un compte technique (financement,
 * leasing, garantie constructeur). On materialise donc une decision PAR COMPTE :
 *   - etat = relancable / ecarte
 *   - origine = auto (regle de semis) / manuel (decision humaine)
 *   - motif = raison lisible de l'ecartement
 *
 * Le semis automatique (app:recouvrement:seed-exclusions) ecarte d'office les
 * garanties (collectif 4116/4166), les statuts sensibles / intra-groupe / codes
 * non codifies, et les comptes a volume anormal (garde-fou). Une decision
 * manuelle (origine=manuel) n'est jamais ecrasee par un re-semis.
 *
 * SelectionRelanceService ne relance que les comptes dont l'etat n'est pas
 * "ecarte" (un compte absent de la table reste relancable par defaut).
 */
final class Version20260629100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : table compte_exclusion (curation des comptes relancables / ecartes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recouvrement.compte_exclusion (
                id BIGSERIAL NOT NULL,
                compte_code VARCHAR(64) NOT NULL,
                etat VARCHAR(16) NOT NULL,
                origine VARCHAR(16) NOT NULL,
                motif TEXT DEFAULT NULL,
                decide_par VARCHAR(255) DEFAULT NULL,
                decide_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                modifie_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                cree_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_compte_exclusion_compte ON recouvrement.compte_exclusion (compte_code)');
        $this->addSql('CREATE INDEX idx_compte_exclusion_etat ON recouvrement.compte_exclusion (etat)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recouvrement.compte_exclusion');
    }
}
