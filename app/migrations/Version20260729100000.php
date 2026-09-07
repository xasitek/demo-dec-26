<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recouvrement : exclut des relances les etablissements cedes (societes vendues
 * AVEC leurs dettes) - ex. 111 et 112. On ajoute un filtre `codeetab notin [...]`
 * aux regles Standard : comme toute la selection (auto, manuelle, preview,
 * curation) passe par les regles actives, l'exclusion est globale.
 *
 * La liste des etablissements exclus se gere ensuite depuis la vue Strategies
 * (operateur "n'est pas dans la liste" sur le champ Etablissement).
 */
final class Version20260729100000 extends AbstractMigration
{
    private const FILTRE = '[{"champ":"codeetab","operateur":"notin","valeur":["111","112"]}]';

    public function getDescription(): string
    {
        return 'Recouvrement : exclusion des etablissements cedes 111/112 des relances (filtre codeetab notin)';
    }

    public function up(Schema $schema): void
    {
        // Ajoute le filtre uniquement s'il n'est pas deja present (idempotent).
        $this->addSql(
            "UPDATE recouvrement.regle_relance
             SET filtres = (filtres::jsonb || '".self::FILTRE."'::jsonb)::json
             WHERE nom IN ('Standard VN', 'Standard APV')
               AND NOT (filtres::jsonb @> '[{\"champ\":\"codeetab\",\"operateur\":\"notin\"}]'::jsonb)"
        );
    }

    public function down(Schema $schema): void
    {
        // Retire l'element codeetab/notin des filtres (les autres filtres conserves).
        $this->addSql(
            "UPDATE recouvrement.regle_relance r
             SET filtres = (
                 SELECT COALESCE(jsonb_agg(e ORDER BY ord), '[]'::jsonb)::json
                 FROM jsonb_array_elements(r.filtres::jsonb) WITH ORDINALITY AS t(e, ord)
                 WHERE NOT (e @> '{\"champ\":\"codeetab\",\"operateur\":\"notin\"}'::jsonb)
             )
             WHERE nom IN ('Standard VN', 'Standard APV')"
        );
    }
}
