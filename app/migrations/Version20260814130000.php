<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement : les pieces justificatives passent du DISQUE a la BASE (bytea).
 *
 * Motif : sur Render le web et le worker (analyse IA + generation OD/SEPA) sont des
 * services distincts SANS disque partage. En stockant le contenu en base, le worker
 * lit les pieces depuis la base commune et n'a plus besoin d'un disque local ni d'un
 * worker interne ; les uploads ne sont plus perdus au redeploy.
 *
 * La table dossier_piece est vide (module non lance) : ADD NOT NULL sans reprise, et
 * chemin_stockage (chemin disque, devenu inutile) est supprime.
 */
final class Version20260814130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : contenu des pieces en base (bytea), suppression du chemin disque.';
    }

    public function up(Schema $schema): void
    {
        // Colonne ajoutee avec un defaut vide (compat si la table n'est pas vide en prod),
        // puis le defaut est retire : les insertions applicatives fournissent toujours le
        // contenu. Sur une table vide (cas nominal), equivaut a un simple ADD NOT NULL.
        $this->addSql("ALTER TABLE remboursement.dossier_piece ADD contenu BYTEA NOT NULL DEFAULT ''::bytea");
        $this->addSql('ALTER TABLE remboursement.dossier_piece ALTER COLUMN contenu DROP DEFAULT');
        $this->addSql('ALTER TABLE remboursement.dossier_piece DROP chemin_stockage');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier_piece ADD chemin_stockage VARCHAR(512) NOT NULL');
        $this->addSql('ALTER TABLE remboursement.dossier_piece DROP contenu');
    }
}
