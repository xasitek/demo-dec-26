<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement — la sentinelle du code ICAR passe de `0` a `000000`.
 *
 * `Version20260903120000` a pose `0` sur les rachats secs deposes sans code client,
 * pour que la comptable le voie et le corrige. Elle ne l'a jamais vu : la chaine
 * « 0 » est FAUSSE en PHP comme en Twig, et toute la chaine d'affichage la traverse
 * par des `?:`. Dans la macro de l'ecran de verification :
 *
 *     {{ saisie|default('—') ?: '—' }}
 *
 * « 0 » etant faux, la colonne « Saisie » affichait « — », exactement comme une
 * valeur absente. Le meme piege vaut pour le recapitulatif du panneau lateral et
 * pour les replis de GenerateurCsvComptable, LibellePaiement et AppariementLettrage.
 *
 * `000000` est une chaine NON VIDE et donc vraie : elle s'affiche partout, reste
 * impossible a confondre avec un vrai numero de compte, et respecte la consigne
 * (« mettre des 0 »). Elle ne peut pas partir dans le CSV comptable sans une action
 * humaine : l'export prend d'abord la valeur validee, et le bouton « Valider » reste
 * grise tant que la comptable n'a pas rempli le champ.
 *
 * Aucun vrai code client ne vaut « 0 » : la portee ne peut donc atteindre que les
 * lignes posees par la migration precedente.
 */
final class Version20260903150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : sentinelle ICAR « 0 » remplacée par « 000000 », invisible car la chaîne « 0 » est fausse';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE remboursement.dossier SET code_icar = '000000' WHERE motif = 'rachat_sec' AND trim(code_icar) = '0' AND statut NOT IN ('paye', 'lettre', 'refuse', 'doublon', 'fraude')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE remboursement.dossier SET code_icar = '0' WHERE motif = 'rachat_sec' AND trim(code_icar) = '000000' AND statut NOT IN ('paye', 'lettre', 'refuse', 'doublon', 'fraude')");
    }
}
