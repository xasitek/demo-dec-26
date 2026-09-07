<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Cycle de vie d'une promesse de paiement.
 */
enum PromesseStatut: string
{
    case EnCours = 'en_cours';
    case Tenue = 'tenue';
    case NonTenue = 'non_tenue';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Tenue => 'Tenue',
            self::NonTenue => 'Non tenue',
            self::Annulee => 'Annulee',
        };
    }
}
