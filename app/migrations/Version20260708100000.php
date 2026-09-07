<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : affine l'anti-doublon des relances pour distinguer la relance
 * "releve" (tout le compte) de la relance ciblee sur UNE facture (relance
 * manuelle).
 *
 * Avant : UNIQUE (compte_code, niveau) WHERE statut='envoye' -> deux factures du
 * meme compte au meme niveau se bloquaient (impossible de relancer facture par
 * facture).
 *
 * Apres :
 *   - relance releve (ecriture_id NULL) : idempotence par (compte_code, niveau) ;
 *   - relance ciblee (ecriture_id renseigne) : idempotence par (ecriture_id, niveau).
 */
final class Version20260708100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : anti-doublon distinct relance releve (compte) vs ciblee (facture)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_compte_niveau');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_compte_niveau
            ON recouvrement.relance_envoi (compte_code, niveau)
            WHERE statut = 'envoye' AND ecriture_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_facture_niveau
            ON recouvrement.relance_envoi (ecriture_id, niveau)
            WHERE statut = 'envoye' AND ecriture_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_facture_niveau');
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_compte_niveau');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_relance_compte_niveau
            ON recouvrement.relance_envoi (compte_code, niveau)
            WHERE statut = 'envoye'
        SQL);
    }
}
