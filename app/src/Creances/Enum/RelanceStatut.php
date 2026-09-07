<?php

declare(strict_types=1);

namespace App\Creances\Enum;

enum RelanceStatut: string
{
    case AEnvoyer = 'a_envoyer';
    case Envoye = 'envoye';
    case Erreur = 'erreur';
    case Supprime = 'supprime';
    case Archive = 'archive';

    public function libelle(): string
    {
        return match ($this) {
            self::AEnvoyer => 'A envoyer',
            self::Envoye => 'Envoye',
            self::Erreur => 'En erreur',
            self::Supprime => 'Supprime',
            self::Archive => 'Archive',
        };
    }
}
