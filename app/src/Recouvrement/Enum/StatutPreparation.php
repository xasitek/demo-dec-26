<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Etat d'un lancement manuel de preparation de relances (bouton "Lancer
 * maintenant" d'une strategie).
 */
enum StatutPreparation: string
{
    case EN_COURS = 'en_cours';
    case TERMINE = 'termine';
    case ECHEC = 'echec';
}
