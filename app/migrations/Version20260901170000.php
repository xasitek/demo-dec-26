<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Espace Livraison — tracabilite du controle comptable.
 *
 * Qui a controle la declaration, et quand. Le circuit precedent n'avait rien de
 * tel : l'onglet `Anomalies` portait un horodatage de controle, mais aucune trace
 * de la personne, et le statut valait « Conforme » PAR DEFAUT en l'absence
 * d'anomalie connue — on ne pouvait donc pas distinguer un dossier valide d'un
 * dossier jamais regarde.
 *
 * Additive et nullable : sans effet sur les declarations existantes, qui restent
 * en statut `deposee`.
 */
final class Version20260901170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraison : qui a controle la declaration, et quand (controlee_par / controlee_le)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE livraison.declaration ADD controlee_par VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE livraison.declaration ADD controlee_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_livr_decl_controle ON livraison.declaration (statut, declaree_le)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS livraison.idx_livr_decl_controle');
        $this->addSql('ALTER TABLE livraison.declaration DROP controlee_le');
        $this->addSql('ALTER TABLE livraison.declaration DROP controlee_par');
    }
}
