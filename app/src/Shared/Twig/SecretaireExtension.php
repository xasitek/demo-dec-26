<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Secretaire\EspaceSecretaire;
use App\Shared\Secretaire\RegistreEspaces;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig du poste secretaire.
 *
 * - `espaces_secretaire()` : les espaces declares par les modules, tries. Alimente
 *   la barre laterale et la sous-navigation de chaque espace.
 * - `espace_secretaire(route)` : l'espace auquel appartient une route, pour marquer
 *   l'entree active et rendre la bonne sous-navigation.
 * - `poste_secretaire()` : vrai si la vue doit etre celle du poste secretaire, c'est
 *   a dire une secretaire pure, ou un visiteur anonyme sur un ecran public d'espace.
 */
final class SecretaireExtension extends AbstractExtension
{
    public function __construct(
        private readonly RegistreEspaces $registre,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('espaces_secretaire', $this->espaces(...)),
            new TwigFunction('espace_secretaire', $this->espacePourRoute(...)),
            new TwigFunction('poste_secretaire', $this->posteSecretaire(...)),
        ];
    }

    /** @return list<EspaceSecretaire> */
    public function espaces(): array
    {
        return $this->registre->espacesAccessibles();
    }

    public function espacePourRoute(?string $route): ?EspaceSecretaire
    {
        return null === $route || '' === $route ? null : $this->registre->espacePourRoute($route);
    }

    /**
     * Un role superieur garde l'application complete, meme s'il visite un ecran de
     * secretaire : il doit pouvoir revenir a ses propres modules.
     */
    public function posteSecretaire(?string $route): bool
    {
        if ($this->security->isGranted('ROLE_COMPTABLE')
            || $this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_DIRECTEUR')
            || $this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        if ($this->security->isGranted('ROLE_SECRETAIRE')) {
            return true;
        }

        // Visiteur anonyme : le poste s'affiche sur son accueil neutre et sur tout
        // ecran d'espace (les formulaires de depot sont publics, la secretaire ne se
        // connecte qu'au moment de valider).
        return RegistreEspaces::ACCUEIL === $route || null !== $this->espacePourRoute($route);
    }
}
