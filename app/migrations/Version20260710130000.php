<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : index pour le "Journal des relances" (consultation par jour).
 *
 * Le journal liste les relances d'une journée via leur "date d'activité" =
 * COALESCE(envoye_le, prepare_le, cree_le) : un échec ne pose pas envoye_le, on
 * retombe alors sur la date de tentative (prepare_le) puis, à défaut, de création.
 * Un index fonctionnel sur cette expression EXACTE rend la recherche d'un jour
 * (borne demi-ouverte >= début AND < fin) O(log n), même quand l'historique des
 * relances grossit (x100).
 *
 * Les index (compte_code) et (statut) existent déjà (cf. entité RelanceEnvoi) et
 * couvrent respectivement la timeline d'un compte et le bloc "à envoyer".
 */
final class Version20260710130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : index date d activite sur relance_envoi (journal par jour)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_relance_activite ON recouvrement.relance_envoi (COALESCE(envoye_le, prepare_le, cree_le))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.idx_relance_activite');
    }
}
