<?php

declare(strict_types=1);

namespace App\Recouvrement\Postal;

use App\Recouvrement\Enum\StatutCourrier;

/**
 * Resultat d'un depot de courrier chez un prestataire : le nom du prestataire, la
 * reference qu'il renvoie (pour le suivi ulterieur) et le statut normalise.
 */
final class ResultatCourrier
{
    public function __construct(
        public readonly string $prestataire,
        public readonly ?string $reference,
        public readonly StatutCourrier $statut,
        public readonly ?string $erreur = null,
    ) {
    }

    public static function erreur(string $prestataire, string $message): self
    {
        return new self($prestataire, null, StatutCourrier::ERREUR, $message);
    }
}
