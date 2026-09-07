<?php

declare(strict_types=1);

namespace App\Shared\Entity;

/**
 * Statut d'une demande d'accès (workflow d'habilitation). Voir docs/SECURITY.md.
 */
enum StatutDemande: string
{
    case EnAttente = 'en_attente';
    case Approuvee = 'approuvee';
    case Refusee = 'refusee';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Approuvee => 'Approuvée',
            self::Refusee => 'Refusée',
        };
    }
}
