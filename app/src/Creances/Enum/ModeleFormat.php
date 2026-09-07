<?php

declare(strict_types=1);

namespace App\Creances\Enum;

enum ModeleFormat: string
{
    case Email = 'email';
    case Courrier = 'courrier';

    public function libelle(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Courrier => 'Courrier postal',
        };
    }
}
