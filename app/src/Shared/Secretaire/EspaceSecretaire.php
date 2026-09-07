<?php

declare(strict_types=1);

namespace App\Shared\Secretaire;

/**
 * Un espace du poste secretaire : une entree de la barre laterale, ses ecrans, et
 * les routes qu'elle est autorisee a visiter.
 *
 * Le poste secretaire n'est pas un formulaire, c'est un logiciel : chaque metier y
 * a son espace, qui peut grandir en ecrans sans que les autres bougent.
 */
final readonly class EspaceSecretaire
{
    /**
     * @param string           $cle              identifiant court ('remboursement', 'livraison')
     * @param string           $libelle          nom affiche dans la barre laterale
     * @param string           $description      une phrase, affichee sur la carte de l'accueil
     * @param string           $icone            cle d'icone, resolue par _secretaire_icone.html.twig
     * @param string           $couleur          classe Tailwind de teinte de l'icone
     * @param string           $routeAccueil     ou mene le clic sur l'espace
     * @param list<ItemEspace> $items            ecrans de l'espace, dans l'ordre d'affichage
     * @param list<string>     $routesAutorisees toutes les routes de l'espace, y compris
     *                                           les fragments et appels AJAX de ses ecrans
     * @param int              $ordre            position dans la barre laterale
     */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $description,
        public string $icone,
        public string $couleur,
        public string $routeAccueil,
        public array $items,
        public array $routesAutorisees,
        public int $ordre = 100,
    ) {
    }

    /** Vrai si la route donnee appartient a cet espace (pour marquer l'actif). */
    public function contient(string $route): bool
    {
        return \in_array($route, $this->routesAutorisees, true);
    }
}
