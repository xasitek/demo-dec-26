<?php

declare(strict_types=1);

namespace App\Shared\Enum;

/**
 * Nature d'une remontee envoyee depuis l'ampoule (boite a idees).
 *
 * Le type decide de la VISIBILITE : seule une idee part sur le mur public et se
 * vote. Une anomalie ou une question n'est visible que de son auteur et des
 * administrateurs — un bug n'a pas besoin d'etre vote, et le mur ne doit pas
 * devenir un tableau de reproches sur le travail d'un collegue.
 */
enum TypeSuggestion: string
{
    case IDEE = 'idee';
    case ANOMALIE = 'anomalie';
    case QUESTION = 'question';

    public function libelle(): string
    {
        return match ($this) {
            self::IDEE => 'Idée',
            self::ANOMALIE => 'Anomalie',
            self::QUESTION => 'Question',
        };
    }

    /** Aide affichee sous la pastille de choix, dans le panneau. */
    public function aide(): string
    {
        return match ($this) {
            self::IDEE => 'Une amélioration à proposer',
            self::ANOMALIE => 'Quelque chose ne fonctionne pas',
            self::QUESTION => 'Un doute sur le fonctionnement',
        };
    }

    /** Une idee est publique et votable ; le reste va aux administrateurs. */
    public function surLeMur(): bool
    {
        return self::IDEE === $this;
    }

    /**
     * @return array<string, string> valeur => libelle (pour les filtres)
     */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::cases() as $case) {
            $choix[$case->value] = $case->libelle();
        }

        return $choix;
    }
}
