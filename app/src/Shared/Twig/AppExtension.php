<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Repository\DemandeAccesRepository;
use App\Shared\Repository\SuggestionRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig transverses.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly DemandeAccesRepository $demandes,
        private readonly SuggestionRepository $suggestions,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('demandes_en_attente', $this->countDemandesEnAttente(...)),
            new TwigFunction('suggestions_nouvelles', $this->countSuggestionsNouvelles(...)),
            new TwigFunction('pluriel', $this->pluriel(...)),
        ];
    }

    public function countDemandesEnAttente(): int
    {
        return $this->demandes->countEnAttente();
    }

    /**
     * Badge de la barre laterale, appele UNIQUEMENT dans le bloc administrateur du
     * layout : un COUNT indexe, jamais paye par les autres roles.
     */
    public function countSuggestionsNouvelles(): int
    {
        return $this->suggestions->compterNouvelles();
    }

    /**
     * Retourne "{count} {mot}" avec le mot accorde. En francais, 0 et 1 sont au singulier.
     */
    public function pluriel(int $count, string $singulier, string $pluriel): string
    {
        return $count.' '.(abs($count) > 1 ? $pluriel : $singulier);
    }
}
