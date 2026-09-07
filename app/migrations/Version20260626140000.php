<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : passage de la relance PAR FACTURE a la relance GROUPEE PAR COMPTE.
 *
 * Le dispositif relance desormais un COMPTE client (un seul email/releve listant
 * toutes ses factures echues impayees) et non plus chaque facture isolement.
 * Consequences sur recouvrement.relance_envoi :
 *   - ecriture_id devient NULLABLE (sans objet en envoi groupe) ;
 *   - ajout de nb_factures (nombre de factures listees dans la relance) ;
 *   - la cle metier d'idempotence passe de (ecriture_id, niveau) a
 *     (compte_code, niveau) : on ne relance jamais deux fois le meme palier d'un
 *     compte. L'index unique partiel (WHERE statut='envoye') est recree en
 *     consequence.
 *
 * En production la table relance_envoi est vide : la migration s'applique seule.
 * En local, les lignes de TEST du modele par-facture violeraient le nouvel index
 * (meme compte+niveau sur plusieurs ecritures) : elles doivent etre purgees
 * manuellement (TRUNCATE) AVANT d'appliquer cette migration.
 */
final class Version20260626140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : relance groupee par compte (ecriture_id nullable, nb_factures, index unique compte_code+niveau)';
    }

    public function up(Schema $schema): void
    {
        // ecriture_id : sans objet en envoi groupe -> nullable.
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ALTER COLUMN ecriture_id DROP NOT NULL');

        // Nombre de factures listees dans la relance (0 par defaut pour l'historique).
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ADD COLUMN nb_factures INTEGER NOT NULL DEFAULT 0');

        // Cle metier d'idempotence : (compte_code, niveau) remplace (ecriture_id, niveau).
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_ecriture_niveau');
        $this->addSql("CREATE UNIQUE INDEX uniq_relance_compte_niveau ON recouvrement.relance_envoi (compte_code, niveau) WHERE statut = 'envoye'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.uniq_relance_compte_niveau');
        $this->addSql("CREATE UNIQUE INDEX uniq_relance_ecriture_niveau ON recouvrement.relance_envoi (ecriture_id, niveau) WHERE statut = 'envoye'");

        $this->addSql('ALTER TABLE recouvrement.relance_envoi DROP COLUMN nb_factures');
        $this->addSql('ALTER TABLE recouvrement.relance_envoi ALTER COLUMN ecriture_id SET NOT NULL');
    }
}
