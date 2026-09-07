<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/** Resultat d'une extraction : JSON valide + reponse brute (audit) + telemetrie. */
final readonly class ResultatExtraction
{
    /**
     * @param array<string, mixed> $champsExtraits JSON valide contre le schema
     * @param array<string, mixed> $resultatBrut   reponse integrale du provider (audit/rejeu)
     */
    public function __construct(
        public array $champsExtraits,
        public array $resultatBrut,
        public ?int $tokensEntree = null,
        public ?int $tokensSortie = null,
        public ?int $latenceMs = null,
        public string $modele = '',
    ) {
    }
}
