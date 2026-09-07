<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garanties : dossier dedoublonne par (emetteur, mvs, numero_or).
 *
 * Probleme : la cle unique etait (num_dg, mvs, emetteur). Or le meme OR
 * (meme reparation) pouvait revenir avec un num_dg different selon le robot /
 * le mode de scrape (full vs incremental), creant un doublon. Mesure :
 * ~549 lignes Toyota en double (Fiat/Opel deja propres).
 *
 * Vraie identite d'une DG = vehicule + ordre de reparation = (emetteur, mvs,
 * numero_or). Plusieurs OR sur un meme vehicule = garanties distinctes (on
 * garde). Meme OR deux fois = doublon (on garde la ligne vivante / la plus
 * recente, on supprime l'ancienne).
 *
 * Etapes :
 *   1. Collapse des doublons existants (garde present_dans_scrap=TRUE en
 *      priorite, sinon le plus recent importe_le, sinon le plus grand id).
 *   2. Bascule de l'index unique vers (emetteur, mvs, numero_or). Ainsi un
 *      re-scrape de la meme DG (full, incremental ou sheet reconstruit) met a
 *      jour la ligne au lieu d'en creer une 2e. L'historique n'est jamais
 *      supprime (aucune ligne unique perdue).
 *
 * Les chemins d'upsert (GarantiesSheetSync, IngestToyotaReportCommand,
 * GarantiesScrapImporter) sont alignes sur cette cle dans le meme commit.
 */
final class Version20260623120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garanties : dedoublonnage dossier + cle unique (emetteur, mvs, numero_or)';
    }

    public function up(Schema $schema): void
    {
        // 1. Nettoyage des doublons existants. La FK dossier_changement est en
        //    ON DELETE CASCADE : l'historique de statut des lignes supprimees
        //    part avec elles (lignes en doublon, donc redondantes).
        $this->addSql(<<<'SQL'
            DELETE FROM garanties.dossier d
            USING (
                SELECT id, row_number() OVER (
                    PARTITION BY emetteur, mvs, numero_or
                    ORDER BY (present_dans_scrap IS TRUE) DESC, importe_le DESC NULLS LAST, id DESC
                ) AS rn
                FROM garanties.dossier
            ) ranked
            WHERE d.id = ranked.id AND ranked.rn > 1
            SQL);

        // 2. Bascule de la cle unique.
        $this->addSql('DROP INDEX IF EXISTS garanties.uniq_dossier_dg');
        $this->addSql('CREATE UNIQUE INDEX uniq_dossier_dg ON garanties.dossier (emetteur, mvs, numero_or)');
    }

    public function down(Schema $schema): void
    {
        // Les lignes supprimees ne sont pas restaurables ; on ne retablit que la cle.
        $this->addSql('DROP INDEX IF EXISTS garanties.uniq_dossier_dg');
        $this->addSql('CREATE UNIQUE INDEX uniq_dossier_dg ON garanties.dossier (num_dg, mvs, emetteur)');
    }
}
