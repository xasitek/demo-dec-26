<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Statut d'une echeance d'un dossier echeancier.
 */
enum EcheanceStatut: string
{
    case AVenir = 'a_venir';
    case Partiel = 'partiel';
    case Regle = 'regle';
    case EnRetard = 'en_retard';

    public function libelle(): string
    {
        return match ($this) {
            self::AVenir => 'A venir',
            self::Partiel => 'Reglee partiellement',
            self::Regle => 'Reglee',
            self::EnRetard => 'En retard',
        };
    }
}
