<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garanties — commenter aussi une ecriture Sage sans DG rapprochee.
 *
 * Une note s'accrochait uniquement a `dossier_id`, la DG principale de l'ecriture.
 * Consequence : les ecritures que le rapprochement ne relie a aucune DG (etats
 * « orpheline » et « OD sans VIN ») n'avaient AUCUN ancrage — le bloc notes du
 * panneau de detail etait purement masque pour elles. Or c'est souvent la qu'un
 * auditeur a quelque chose a ecrire : c'est precisement la ligne qui pose question.
 *
 * On ajoute donc un second ancrage, sur l'ecriture elle-meme. L'ancrage porte sur le
 * COUPLE (cle_ecriture, oidech), pas sur la cle seule : l'index unique de
 * `garanties.mv_reconciliation` est (vue_lettree, cle_ecriture, oidech), donc une meme
 * cle ecriture porte plusieurs lignes (c'est la raison du passage du mirror en cle
 * composite en juin). Ancrer sur la cle seule ferait apparaitre un commentaire sur des
 * lignes voisines.
 *
 * Regle : une note porte l'un OU l'autre ancrage, jamais aucun — la contrainte CHECK
 * le garantit en base, les fabriques de l'entite le garantissent dans le code.
 *
 * Additif et reversible : la FK vers `garanties.dossier` reste en place (elle accepte
 * NULL), aucune ligne existante n'est modifiee, et la vue materialisee n'est PAS
 * touchee — donc aucun REFRESH a prevoir et aucun risque sur l'ecran d'audit.
 */
final class Version20260903100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garanties : ancrer une note sur une ecriture Sage (cle + oidech) quand aucune DG n\'est rapprochee';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE garanties.note ALTER COLUMN dossier_id DROP NOT NULL');
        $this->addSql('ALTER TABLE garanties.note ADD cle_ecriture TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE garanties.note ADD oidech TEXT DEFAULT NULL');

        $this->addSql(
            'ALTER TABLE garanties.note ADD CONSTRAINT chk_note_ancre '
            .'CHECK (dossier_id IS NOT NULL OR cle_ecriture IS NOT NULL)',
        );

        // Sert la jointure d'agregation de la liste (nombre de commentaires par ligne)
        // et la lecture des notes d'une ecriture donnee.
        $this->addSql('CREATE INDEX idx_note_ecriture ON garanties.note (cle_ecriture, oidech)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX garanties.idx_note_ecriture');
        $this->addSql('ALTER TABLE garanties.note DROP CONSTRAINT chk_note_ancre');
        $this->addSql('ALTER TABLE garanties.note DROP COLUMN oidech');
        $this->addSql('ALTER TABLE garanties.note DROP COLUMN cle_ecriture');

        // Les notes ancrees sur une ecriture n'ont pas de dossier : elles doivent
        // disparaitre avant de remettre la colonne en NOT NULL.
        $this->addSql('DELETE FROM garanties.note WHERE dossier_id IS NULL');
        $this->addSql('ALTER TABLE garanties.note ALTER COLUMN dossier_id SET NOT NULL');
    }
}
