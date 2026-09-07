<?php

declare(strict_types=1);

namespace App\Remboursement\Message;

/**
 * Demande d'analyse IA d'un dossier deposé (asynchrone). Dispatch au depot / a la
 * re-soumission apres correction. En dev : transport sync (traite en ligne).
 */
final readonly class AnalyserDossier
{
    public function __construct(
        public int $dossierId,
    ) {
    }
}
