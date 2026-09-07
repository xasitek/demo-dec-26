<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement — le code client ICAR devient obligatoire au rachat sec.
 *
 * Jusqu'ici le formulaire ne le demandait qu'au trop-percu, et le CSV comptable
 * laissait la colonne `codeClientICAR` vide au rachat sec. Le code client fait partie
 * de l'ecriture dans les deux cas : il est desormais saisi, lu par l'IA sur la facture
 * d'achat VO, et exporte.
 *
 * Deux dossiers rachat sec etaient deja deposes sans code (REMB-25C1A9A4 et
 * REMB-3E2769DA, tous deux au statut `a_verifier` le 2026-09-03 — et les deux seuls
 * rachats secs de la base). Ils recoivent la valeur sentinelle `0`, qui ne peut pas
 * passer pour un vrai numero de compte : la comptable la voit dans la colonne
 * « Saisie » du tableau de controle et la corrige avant paiement. `0` est en outre
 * neutralise par `CleDoublon::normaliserIcar()` (les zeros de tete tombent), donc le
 * code lu par l'IA sur la facture ressortira comme divergence a verifier.
 *
 * Portee volontairement etroite : uniquement les rachats secs SANS code et encore
 * corrigeables. Un dossier deja paye ou lettre garde son historique tel quel — son
 * ecriture est partie sans le code, la reecrire ici ne changerait rien au fichier
 * deja transmis a la comptabilite.
 *
 * La colonne reste NULLABLE : l'exigence est portee par le formulaire et par
 * DepotController, pas par une contrainte qui bloquerait les brouillons.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : code ICAR sentinelle « 0 » sur les rachats secs déposés sans code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE remboursement.dossier SET code_icar = '0' WHERE motif = 'rachat_sec' AND coalesce(trim(code_icar), '') = '' AND statut NOT IN ('paye', 'lettre', 'refuse', 'doublon', 'fraude')");
    }

    public function down(Schema $schema): void
    {
        // On ne peut pas distinguer un « 0 » pose ici d'un « 0 » saisi ensuite par une
        // secretaire. Le retour en arriere se limite donc aux dossiers restes au statut
        // ou la migration les a trouves.
        $this->addSql("UPDATE remboursement.dossier SET code_icar = NULL WHERE motif = 'rachat_sec' AND trim(code_icar) = '0' AND statut = 'a_verifier'");
    }
}
