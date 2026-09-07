<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Etat de curation d'un compte vis-a-vis de la relance automatique.
 *
 * RELANCABLE : le compte part en relance auto (cas par defaut).
 * ECARTE      : le compte ne part jamais en relance auto (compte technique,
 *               financement, leasing, garantie, ou decision humaine).
 */
enum CompteExclusionEtat: string
{
    case RELANCABLE = 'relancable';
    case ECARTE = 'ecarte';

    public function libelle(): string
    {
        return match ($this) {
            self::RELANCABLE => 'Relançable',
            self::ECARTE => 'Écarté',
        };
    }
}
