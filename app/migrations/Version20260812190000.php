<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : valeurs VALIDEES par le comptable (colonne "Valeur validée"),
 * stockees a part des controles IA pour ne pas ecraser la reference IA et pour que
 * la comptable ne reparte pas de zero apres une demande de correction. IBAN/BIC
 * chiffres au repos (comme les autres). + pieces demandees en correction (JSON).
 */
final class Version20260812190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : colonnes valide_* (valeurs validees comptable) + correction_pieces.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier
            ADD COLUMN valide_nom VARCHAR(180) DEFAULT NULL,
            ADD COLUMN valide_iban TEXT DEFAULT NULL,
            ADD COLUMN valide_bic TEXT DEFAULT NULL,
            ADD COLUMN valide_montant NUMERIC(14, 2) DEFAULT NULL,
            ADD COLUMN valide_immatriculation VARCHAR(32) DEFAULT NULL,
            ADD COLUMN valide_icar VARCHAR(32) DEFAULT NULL,
            ADD COLUMN valide_libelle VARCHAR(255) DEFAULT NULL,
            ADD COLUMN valide_code_comptable VARCHAR(32) DEFAULT NULL,
            ADD COLUMN valide_role_tiers VARCHAR(32) DEFAULT NULL,
            ADD COLUMN valide_par VARCHAR(150) DEFAULT NULL,
            ADD COLUMN correction_pieces JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier
            DROP COLUMN valide_nom,
            DROP COLUMN valide_iban,
            DROP COLUMN valide_bic,
            DROP COLUMN valide_montant,
            DROP COLUMN valide_immatriculation,
            DROP COLUMN valide_icar,
            DROP COLUMN valide_libelle,
            DROP COLUMN valide_code_comptable,
            DROP COLUMN valide_role_tiers,
            DROP COLUMN valide_par,
            DROP COLUMN correction_pieces');
    }
}
