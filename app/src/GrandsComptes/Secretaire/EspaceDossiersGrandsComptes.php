<?php

declare(strict_types=1);

namespace App\GrandsComptes\Secretaire;

use App\Shared\Secretaire\EspaceSecretaire;
use App\Shared\Secretaire\FournisseurEspaceInterface;
use App\Shared\Secretaire\ItemEspace;

/**
 * L'espace « Dossiers grands comptes » du poste secretaire.
 *
 * Le poste secretaire CONFINE : une secretaire ne visite que les routes que son
 * espace declare, et toute autre adresse la ramene a l'accueil neutre du poste.
 * C'est un mecanisme herite, et il est juste : une secretaire n'a pas affaire
 * aux ecrans de controle interne. Les routes de l'outil 7 sont donc declarees
 * ici, une par une -- rien n'est ouvert par defaut.
 *
 * Cet espace remplace, pour la demonstration, le formulaire externe de
 * trente-deux pages : une page unique, la grille du loueur affichee, les pieces
 * manquantes nommees, la certification, et la soumission.
 */
final class EspaceDossiersGrandsComptes implements FournisseurEspaceInterface
{
    public function espace(): EspaceSecretaire
    {
        return new EspaceSecretaire(
            cle: 'livraison',
            libelle: 'Dossiers grands comptes',
            description: 'Déposez les pièces que la grille de votre loueur exige, '
                .'et suivez ce qui manque encore.',
            icone: 'camion',
            couleur: 'text-amber-400',
            routeAccueil: 'app_gc_secretaire',
            items: [
                new ItemEspace(
                    cle: 'mes_dossiers_gc',
                    libelle: 'Mes dossiers',
                    route: 'app_gc_secretaire',
                    exigeConnexion: true,
                ),
                new ItemEspace(
                    cle: 'grilles_gc',
                    libelle: 'Ce que chaque loueur exige',
                    route: 'app_gc_methode',
                    exigeConnexion: true,
                ),
            ],
            routesAutorisees: [
                'app_gc_secretaire',
                'app_gc_deposer',
                'app_gc_deposer_agir',
                'app_gc_piece',
                'app_gc_methode',
            ],
            ordre: 15,
        );
    }
}
