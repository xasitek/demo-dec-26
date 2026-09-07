<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use RuntimeException;

/**
 * Garde-fou anti-abus de la boite a idees : l'auteur a depasse le quota horaire
 * d'envois (cf. SuggestionService::MAX_PAR_HEURE). Traduite en reponse 429 par
 * le controleur.
 */
final class LimiteSuggestionsAtteinte extends RuntimeException
{
    public function __construct(int $max)
    {
        parent::__construct(sprintf(
            'Vous avez atteint la limite de %d envois par heure. Réessayez un peu plus tard.',
            $max,
        ));
    }
}
