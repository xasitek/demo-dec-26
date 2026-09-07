<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Canal d'une relance (recouvrement.relance_envoi.vecteur).
 *
 * EMAIL est le seul canal automatise pour l'instant ; COURRIER est prevu pour
 * les mises en demeure papier (envoi manuel / prestataire ulterieur).
 */
enum RelanceVecteur: string
{
    case EMAIL = 'email';
    case COURRIER = 'courrier';

    public function libelle(): string
    {
        return match ($this) {
            self::EMAIL => 'E-mail',
            self::COURRIER => 'Courrier',
        };
    }
}
