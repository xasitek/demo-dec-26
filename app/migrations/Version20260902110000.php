<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Espace Livraison — rendre les tables insérables.
 *
 * `Version20260901160000` a cree les sequences `declaration_id_seq` et
 * `piece_id_seq`, mais ne les a jamais rattachees aux colonnes : `id` etait
 * `BIGINT NOT NULL` sans valeur par defaut ni identite. Consequence, decouverte au
 * premier INSERT reel le 2026-09-02 :
 *
 *     SQLSTATE[23502] : null value in column "id" of relation "declaration"
 *
 * Aucune declaration n'etait donc enregistrable — ni par la reprise, ni par l'ecran
 * de la secretaire. Le defaut est passe inapercu parce que le chemin d'ecriture
 * n'avait jamais ete execute, ni ici ni en production.
 *
 * Verification faite : `livraison.declaration` et `livraison.piece` etaient les deux
 * SEULES tables du projet dans ce cas. Toutes les autres portent soit une identite,
 * soit un `DEFAULT nextval(...)`. Cette migration aligne le module sur la seconde
 * convention, celle des tables creees avec une sequence nommee.
 *
 * `OWNED BY` rattache la sequence a sa colonne : elle sera supprimee avec la table,
 * et ne restera pas orpheline.
 */
final class Version20260902110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraison : rattacher les sequences a declaration.id et piece.id (aucun INSERT n\'etait possible)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE livraison.declaration ALTER COLUMN id SET DEFAULT nextval('livraison.declaration_id_seq')");
        $this->addSql('ALTER SEQUENCE livraison.declaration_id_seq OWNED BY livraison.declaration.id');

        $this->addSql("ALTER TABLE livraison.piece ALTER COLUMN id SET DEFAULT nextval('livraison.piece_id_seq')");
        $this->addSql('ALTER SEQUENCE livraison.piece_id_seq OWNED BY livraison.piece.id');

        // Si des lignes ont ete inserees a la main entre-temps, la sequence doit
        // repartir au-dela : sans quoi le prochain INSERT violerait la cle primaire.
        $this->addSql("SELECT setval('livraison.declaration_id_seq', coalesce((SELECT max(id) FROM livraison.declaration), 0) + 1, false)");
        $this->addSql("SELECT setval('livraison.piece_id_seq', coalesce((SELECT max(id) FROM livraison.piece), 0) + 1, false)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER SEQUENCE livraison.piece_id_seq OWNED BY NONE');
        $this->addSql('ALTER TABLE livraison.piece ALTER COLUMN id DROP DEFAULT');

        $this->addSql('ALTER SEQUENCE livraison.declaration_id_seq OWNED BY NONE');
        $this->addSql('ALTER TABLE livraison.declaration ALTER COLUMN id DROP DEFAULT');
    }
}
