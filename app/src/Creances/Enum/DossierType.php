<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Type d'un dossier metier de recouvrement.
 */
enum DossierType: string
{
    case Echeancier = 'echeancier';
    case Litige = 'litige';
    case Contentieux = 'contentieux';

    public function libelle(): string
    {
        return match ($this) {
            self::Echeancier => 'Echeancier',
            self::Litige => 'Litige',
            self::Contentieux => 'Contentieux',
        };
    }
}
