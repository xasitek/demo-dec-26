<?php

declare(strict_types=1);

namespace App\Shared\Entity;

/**
 * Pole d'affectation d'un comptable (specialisation au sein du role Comptable).
 * N'a de sens que pour un utilisateur ayant ROLE_COMPTABLE.
 */
enum PoleComptable: string
{
    case GENERAL = 'general';
    case FOURNISSEUR = 'fournisseur';
    case CLIENT = 'client';
    case BANQUE = 'banque';
    case CONSTRUCTEUR = 'constructeur';

    public function libelle(): string
    {
        return match ($this) {
            self::GENERAL => 'Général',
            self::FOURNISSEUR => 'Fournisseur',
            self::CLIENT => 'Client',
            self::BANQUE => 'Banque',
            self::CONSTRUCTEUR => 'Constructeur',
        };
    }

    /**
     * Libelles indexes par valeur, pour construire les menus deroulants.
     *
     * @return array<string, string>
     */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::cases() as $pole) {
            $choix[$pole->value] = $pole->libelle();
        }

        return $choix;
    }
}
