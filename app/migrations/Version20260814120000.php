<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : index sur les colonnes CHAUDES de filtre/tri du module (perf « x100 »).
 * - sepa_telecharge_le : journal des paiements, ecran virements (a telecharger / deja pris),
 *   garde 1x/jour, confirmation par jour.
 * - cree_par : « Mes dossiers » de la secretaire + badge « a corriger » (chaque page secretaire).
 * - valide_directeur_le : tri de la file de paiement (virements).
 */
final class Version20260814120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : index dossier sur sepa_telecharge_le, cree_par, valide_directeur_le (perf).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_remb_dossier_sepa_tel ON remboursement.dossier (sepa_telecharge_le)');
        $this->addSql('CREATE INDEX idx_remb_dossier_cree_par ON remboursement.dossier (cree_par)');
        $this->addSql('CREATE INDEX idx_remb_dossier_valide_dir ON remboursement.dossier (valide_directeur_le)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX remboursement.idx_remb_dossier_sepa_tel');
        $this->addSql('DROP INDEX remboursement.idx_remb_dossier_cree_par');
        $this->addSql('DROP INDEX remboursement.idx_remb_dossier_valide_dir');
    }
}
