<?php

declare(strict_types=1);

namespace App\Shared\Secretaire;

/**
 * Un ecran d'un espace du poste secretaire, tel qu'il apparait dans la
 * sous-navigation (topbar) de cet espace.
 */
final readonly class ItemEspace
{
    /**
     * @param string      $cle            identifiant court, sert a marquer l'onglet actif
     * @param string      $libelle        texte affiche
     * @param string      $route          nom de route Symfony
     * @param bool        $exigeConnexion si vrai et la secretaire n'est pas connectee,
     *                                    le lien passe par la connexion Google et revient
     * @param string|null $badge          nom d'une fonction Twig rendant un compteur
     *                                    (ex. 'remb_nb_correction'), ou null
     */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $route,
        public bool $exigeConnexion = false,
        public ?string $badge = null,
    ) {
    }
}
