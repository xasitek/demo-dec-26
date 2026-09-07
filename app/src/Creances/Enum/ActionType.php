<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Type d'une action de recouvrement.
 */
enum ActionType: string
{
    case Telephone = 'telephone';
    case Email = 'email';
    case Courrier = 'courrier';
    case Sms = 'sms';
    case Visite = 'visite';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Telephone => 'Appel telephonique',
            self::Email => 'E-mail',
            self::Courrier => 'Courrier postal',
            self::Sms => 'SMS',
            self::Visite => 'Visite client',
            self::Autre => 'Autre',
        };
    }
}
