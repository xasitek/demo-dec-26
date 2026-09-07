<?php

declare(strict_types=1);

namespace App\Tests\Shared\Entity;

use App\Shared\Entity\Suggestion;
use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use PHPUnit\Framework\TestCase;

/**
 * Boite a idees : ce que garantit l'entite. Deux regles portent la valeur du
 * dispositif — l'identite de l'auteur figee (le backlog survit a un depart) et
 * la separation idee / anomalie (seule une idee va sur le mur public).
 */
final class SuggestionTest extends TestCase
{
    public function testAuteurNomEstFigeALaCreation(): void
    {
        $auteur = self::auteur('Tiffany', 'Martin');
        $suggestion = new Suggestion($auteur, TypeSuggestion::IDEE, 'Un raccourci vers le dossier client.');

        // Le compte change (mariage, correction de saisie) : la remontee garde le
        // nom sous lequel elle a ete envoyee.
        $auteur->setLastName('Durand');

        self::assertSame('Tiffany Martin', $suggestion->getAuteurNom());
    }

    public function testSeuleUneIdeeVaSurLeMur(): void
    {
        $auteur = self::auteur();

        self::assertTrue((new Suggestion($auteur, TypeSuggestion::IDEE, 'Idee'))->estSurLeMur());
        self::assertFalse((new Suggestion($auteur, TypeSuggestion::ANOMALIE, 'Bug'))->estSurLeMur());
        self::assertFalse((new Suggestion($auteur, TypeSuggestion::QUESTION, 'Doute'))->estSurLeMur());
    }

    public function testNouvelleSuggestionEstEnStatutNouvelleSansVote(): void
    {
        $suggestion = new Suggestion(self::auteur(), TypeSuggestion::IDEE, 'Idee');

        self::assertSame(StatutSuggestion::NOUVELLE, $suggestion->getStatut());
        self::assertSame(0, $suggestion->getNbVotes());
        self::assertNull($suggestion->getTraiteAt());
        self::assertNull($suggestion->getReponse());
    }

    public function testTraiterEnregistreStatutReponseEtTraitant(): void
    {
        $suggestion = new Suggestion(
            self::auteur(),
            TypeSuggestion::IDEE,
            'Un raccourci vers le dossier client.',
            Module::REMBOURSEMENT,
            'app_remboursement_accueil',
            '/remboursement',
        );

        $suggestion->traiter(StatutSuggestion::FAITE, '  Livre en version 2.4.  ', self::auteur('Fargil', 'Bot'));

        self::assertSame(StatutSuggestion::FAITE, $suggestion->getStatut());
        self::assertSame('Livre en version 2.4.', $suggestion->getReponse());
        self::assertSame('Fargil Bot', $suggestion->getTraitePar());
        self::assertNotNull($suggestion->getTraiteAt());
    }

    public function testTraiterSansReponseLaisseLaReponseVide(): void
    {
        $suggestion = new Suggestion(self::auteur(), TypeSuggestion::ANOMALIE, 'Le bouton ne repond pas.');

        $suggestion->traiter(StatutSuggestion::VUE, '   ', self::auteur('Fargil', 'Bot'));

        self::assertNull($suggestion->getReponse());
        self::assertSame(StatutSuggestion::VUE, $suggestion->getStatut());
    }

    public function testContexteDeLaPageEstConserve(): void
    {
        $suggestion = new Suggestion(
            self::auteur(),
            TypeSuggestion::IDEE,
            'Idee',
            Module::RECOUVREMENT,
            'app_recouvrement_index',
            '/recouvrement',
        );

        self::assertSame(Module::RECOUVREMENT, $suggestion->getModule());
        self::assertSame('app_recouvrement_index', $suggestion->getRoute());
        self::assertSame('/recouvrement', $suggestion->getUrl());
    }

    private static function auteur(string $prenom = 'Kirdan', string $nom = 'Dupont'): User
    {
        $user = new User();
        $user->setFirstName($prenom);
        $user->setLastName($nom);

        return $user;
    }
}
