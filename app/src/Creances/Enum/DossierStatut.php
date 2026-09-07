<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Statut courant d'un dossier de recouvrement.
 */
enum DossierStatut: string
{
    case Ouvert = 'ouvert';
    case Clos = 'clos';

    public function libelle(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::Clos => 'Clos',
        };
    }
}
