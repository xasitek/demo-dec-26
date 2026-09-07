<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Resultat enregistre lors de la cloture d'une action.
 */
enum ActionResultat: string
{
    case ContactOk = 'contact_ok';
    case ContactKo = 'contact_ko';
    case Promesse = 'promesse';
    case Refus = 'refus';
    case Litige = 'litige';
    case Reglement = 'reglement';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::ContactOk => 'Contact etabli',
            self::ContactKo => 'Pas de contact',
            self::Promesse => 'Promesse obtenue',
            self::Refus => 'Refus',
            self::Litige => 'Litige declare',
            self::Reglement => 'Reglement',
            self::Autre => 'Autre',
        };
    }
}
