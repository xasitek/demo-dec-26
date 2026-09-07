<?php

declare(strict_types=1);

namespace App\Creances\Enum;

enum RelanceVecteur: string
{
    case Email = 'email';
    case Courrier = 'courrier';
    case Extraction = 'extraction';
    case Sms = 'sms';

    public function libelle(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Courrier => 'Courrier postal',
            self::Extraction => 'Extraction fichier',
            self::Sms => 'SMS',
        };
    }
}
