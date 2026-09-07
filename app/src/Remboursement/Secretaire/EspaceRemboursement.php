<?php

declare(strict_types=1);

namespace App\Remboursement\Secretaire;

use App\Shared\Secretaire\EspaceSecretaire;
use App\Shared\Secretaire\FournisseurEspaceInterface;
use App\Shared\Secretaire\ItemEspace;

/**
 * Declaration de l'espace « Remboursement client » du poste secretaire.
 *
 * La secretaire y depose un dossier de remboursement (rachat sec ou trop-percu) et
 * suit les siens, dont ceux qui lui reviennent en correction.
 */
final class EspaceRemboursement implements FournisseurEspaceInterface
{
    public function espace(): EspaceSecretaire
    {
        return new EspaceSecretaire(
            cle: 'remboursement',
            libelle: 'Remboursement client',
            description: 'Rachat sec ou trop-perçu : déposez le dossier, le directeur valide, le client est remboursé.',
            icone: 'colis',
            couleur: 'text-sky-400',
            routeAccueil: 'app_remboursement_deposer_formulaire',
            items: [
                new ItemEspace(
                    cle: 'deposer',
                    libelle: 'Déposer un dossier',
                    route: 'app_remboursement_deposer_formulaire',
                ),
                new ItemEspace(
                    cle: 'mes_dossiers',
                    libelle: 'Mes dossiers',
                    route: 'app_remboursement_mes_dossiers',
                    exigeConnexion: true,
                    badge: 'remb_nb_correction',
                ),
            ],
            routesAutorisees: [
                'app_remboursement_deposer_formulaire',
                'app_remboursement_deposer',
                'app_remboursement_mes_dossiers',
                'app_remboursement_mon_dossier',
                'app_remboursement_mon_dossier_panneau',
                'app_remboursement_mon_dossier_corriger',
                'app_remboursement_ma_piece',
                // Appels du formulaire de depot (aide Gestion commerciale, controle Buy Back,
                // identite de la secretaire apres connexion en popup).
                'app_remboursement_moi',
                'app_remboursement_controle_buyback',
                'app_remboursement_chercher_client',
                'app_remboursement_client_donnees',
            ],
            ordre: 10,
        );
    }
}
