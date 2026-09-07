<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Categorie d'un retour client (recouvrement.retour_client.categorie).
 *
 * NON_CATEGORISE est l'etat par defaut tant qu'aucun classement (manuel ou
 * automatique) n'a ete applique au message recu.
 */
enum RetourCategorie: string
{
    case PROMESSE_PAIEMENT = 'promesse_paiement';
    case CONTESTATION = 'contestation';
    case COORDONNEES = 'coordonnees';
    case AUTRE = 'autre';
    case NON_CATEGORISE = 'non_categorise';

    public function libelle(): string
    {
        return match ($this) {
            self::PROMESSE_PAIEMENT => 'Promesse de paiement',
            self::CONTESTATION => 'Contestation',
            self::COORDONNEES => 'Coordonnées',
            self::AUTRE => 'Autre',
            self::NON_CATEGORISE => 'Non catégorisé',
        };
    }
}
