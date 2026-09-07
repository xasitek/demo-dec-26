<?php

declare(strict_types=1);

namespace App\Shared\Entity;

/**
 * Type d'evenement journalise dans le suivi d'activite. Voir docs/SECURITY.md.
 */
enum ActivityAction: string
{
    case Login = 'login';
    case Logout = 'logout';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Connexion',
            self::Logout => 'Déconnexion',
        };
    }
}
