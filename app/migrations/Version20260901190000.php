<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Boite a idees : tables shared.suggestion et shared.suggestion_vote.
 *
 * Socle transverse, ouvert a tous les roles : l'ampoule presente sur toutes les
 * pages depose ici les remontees, avec le contexte de la page d'origine (module,
 * route, url) — la valeur d'une remontee tient autant a « depuis quel ecran »
 * qu'a son texte.
 *
 * Choix a expliciter :
 * - auteur_id ON DELETE SET NULL (et auteur_nom fige en texte) : le depart d'un
 *   collaborateur ne doit pas emporter le backlog produit avec lui.
 * - nb_votes denormalise : le mur se trie par popularite sans un COUNT par ligne.
 *   Maintenu par UPDATE atomique (cf. SuggestionRepository::ajusterVotes()).
 * - unicite (suggestion_id, votant_id) : un vote par personne, garanti par la base
 *   et non par le code applicatif.
 */
final class Version20260901190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boite a idees : tables suggestion et suggestion_vote (schema shared)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared.suggestion (
                id BIGSERIAL PRIMARY KEY,
                auteur_id BIGINT DEFAULT NULL REFERENCES shared.users(id) ON DELETE SET NULL,
                auteur_nom VARCHAR(180) NOT NULL,
                type VARCHAR(20) NOT NULL,
                statut VARCHAR(20) NOT NULL DEFAULT 'nouvelle',
                message TEXT NOT NULL,
                module VARCHAR(20) DEFAULT NULL,
                route VARCHAR(255) DEFAULT NULL,
                url VARCHAR(1024) DEFAULT NULL,
                nb_votes INT NOT NULL DEFAULT 0,
                reponse TEXT DEFAULT NULL,
                traite_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                traite_par VARCHAR(180) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->addSql('CREATE INDEX idx_suggestion_statut ON shared.suggestion (statut, created_at)');
        $this->addSql('CREATE INDEX idx_suggestion_auteur ON shared.suggestion (auteur_id, created_at)');
        $this->addSql('CREATE INDEX idx_suggestion_mur ON shared.suggestion (type, nb_votes)');

        $this->addSql(<<<'SQL'
            CREATE TABLE shared.suggestion_vote (
                id BIGSERIAL PRIMARY KEY,
                suggestion_id BIGINT NOT NULL REFERENCES shared.suggestion(id) ON DELETE CASCADE,
                votant_id BIGINT NOT NULL REFERENCES shared.users(id) ON DELETE CASCADE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_suggestion_vote UNIQUE (suggestion_id, votant_id)
            )
        SQL);

        // Couvre « les idees deja soutenues par cet utilisateur, parmi celles
        // affichees » : une seule requete pour l'etat des boutons de toute la page.
        $this->addSql('CREATE INDEX idx_suggestion_vote_votant ON shared.suggestion_vote (votant_id, suggestion_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS shared.suggestion_vote');
        $this->addSql('DROP TABLE IF EXISTS shared.suggestion');
    }
}
