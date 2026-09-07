<?php

declare(strict_types=1);

namespace App\Remboursement\Enum;

/**
 * Etat de l'extraction IA d'UNE piece. Voir docs/MODULE_REMBOURSEMENT.md (4.4.2).
 */
enum StatutExtraction: string
{
    case EN_ATTENTE = 'en_attente';
    case EN_COURS = 'en_cours';
    case REUSSIE = 'reussie';
    case ECHOUEE_METIER = 'echouee_metier'; // "DOCUMENT INVALIDE" (contenu illisible/non conforme)
    case SURCHARGE = 'surcharge';           // panne technique apres retries (jamais un refus)

    public function estTerminal(): bool
    {
        return match ($this) {
            self::REUSSIE, self::ECHOUEE_METIER, self::SURCHARGE => true,
            default => false,
        };
    }
}
