<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garanties : alignement sur la cle naturelle utilisee par le RPA Fiat.
 *
 * Le RPA identifie une DG par le triplet (Num DG, MVS, Emetteur). Notre cle
 * `cle = concession|num_dg|chassis|numero_or` etait une supposition au moment
 * du seed CSV ; on bascule sur la vraie. On ajoute aussi present_dans_scrap
 * (false quand le RPA ne re-voit pas la ligne dans son run) pour le pattern
 * "jamais de suppression" du mirror, applique cote scrap.
 */
final class Version20260528084204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garanties : cle (num_dg, mvs, emetteur) + present_dans_scrap';
    }

    public function up(Schema $schema): void
    {
        // 1. Ajout des nouvelles colonnes.
        $this->addSql('ALTER TABLE garanties.dossier ADD COLUMN emetteur VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE garanties.dossier ADD COLUMN present_dans_scrap BOOLEAN DEFAULT TRUE NOT NULL');

        // 2. Backfill emetteur depuis le JSONB brut (donnees->>'Emetteur').
        $this->addSql("UPDATE garanties.dossier SET emetteur = NULLIF(trim(donnees->>'Emetteur'), '')");

        // 3. Bascule de cle : on retire l'ancien index unique et la colonne cle.
        $this->addSql('DROP INDEX garanties.uniq_dossier_cle');
        $this->addSql('ALTER TABLE garanties.dossier DROP COLUMN cle');

        // 4. Nouveau index unique sur (num_dg, mvs, emetteur) — la cle RPA.
        //    L'absence de doublons est verifiee : 10 169 lignes = 10 169 triples
        //    distincts au moment de la migration.
        $this->addSql('CREATE UNIQUE INDEX uniq_dossier_dg ON garanties.dossier (num_dg, mvs, emetteur)');

        // 5. Index sur present_dans_scrap pour filtrer rapidement les actifs.
        $this->addSql('CREATE INDEX idx_dossier_present_dans_scrap ON garanties.dossier (present_dans_scrap)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX garanties.idx_dossier_present_dans_scrap');
        $this->addSql('DROP INDEX garanties.uniq_dossier_dg');
        $this->addSql("ALTER TABLE garanties.dossier ADD COLUMN cle VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE garanties.dossier SET cle = concession || '|' || COALESCE(num_dg, '') || '|' || COALESCE(chassis, '') || '|' || COALESCE(numero_or, '')");
        $this->addSql('ALTER TABLE garanties.dossier ALTER COLUMN cle DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX uniq_dossier_cle ON garanties.dossier (cle)');
        $this->addSql('ALTER TABLE garanties.dossier DROP COLUMN present_dans_scrap');
        $this->addSql('ALTER TABLE garanties.dossier DROP COLUMN emetteur');
    }
}
