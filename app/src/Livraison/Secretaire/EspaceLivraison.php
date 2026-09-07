<?php

declare(strict_types=1);

namespace App\Livraison\Secretaire;

use App\Shared\Secretaire\EspaceSecretaire;
use App\Shared\Secretaire\FournisseurEspaceInterface;
use App\Shared\Secretaire\ItemEspace;

/**
 * Declaration de l'espace « Livraison » du poste secretaire.
 *
 * La secretaire y declare les vehicules livres a un loueur et joint les pieces du
 * dossier (PV de livraison, bon de commande, CPI). Remplace les trente-cinq
 * formulaires Google — un par concession — et le script qui les tenait a jour.
 */
final class EspaceLivraison implements FournisseurEspaceInterface
{
    public function espace(): EspaceSecretaire
    {
        return new EspaceSecretaire(
            cle: 'livraison',
            libelle: 'Livraison',
            description: 'Déclarez les véhicules livrés à un loueur et joignez les pièces du dossier.',
            icone: 'camion',
            couleur: 'text-amber-400',
            routeAccueil: 'app_livraison_declarer',
            items: [
                new ItemEspace(
                    cle: 'declarer',
                    libelle: 'Déclarer une livraison',
                    route: 'app_livraison_declarer',
                ),
                new ItemEspace(
                    cle: 'mes_declarations',
                    libelle: 'Mes déclarations',
                    route: 'app_livraison_mes_declarations',
                    exigeConnexion: true,
                ),
            ],
            routesAutorisees: [
                'app_livraison_declarer',
                'app_livraison_declarer_valider',
                'app_livraison_vehicules',
                'app_livraison_mes_declarations',
            ],
            ordre: 20,
        );
    }
}
