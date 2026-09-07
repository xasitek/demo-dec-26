<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : index de tri pour la page Impayés.
 *
 * Depuis l'élargissement de v_impayes (tous types + collectifs 41x, ~100k lignes),
 * la page /recouvrement/impayes (WHERE jours_retard > 0 ORDER BY date_echeance,
 * montant_solde) faisait un seq scan + tri complet -> ~190 ms. Un index partiel
 * ordonné sur les factures échues rend le LIMIT quasi instantané (~17 ms).
 */
final class Version20260722100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : index de tri idx_impayes_echu_tri (page Impayes instantanee)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_impayes_echu_tri ON recouvrement.v_impayes (date_echeance ASC, montant_solde DESC) WHERE jours_retard > 0');
        // Nettoyage d'un index experimental qui degradait la liste Relancer.
        $this->addSql('DROP INDEX IF EXISTS recouvrement.idx_impayes_net');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recouvrement.idx_impayes_echu_tri');
    }
}
