<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/**
 * Gabarit d'extraction pour un couple (cas, type de piece) : prompt + schema JSON
 * (responseSchema Gemini) + version (cache/audit). Le schema garantit une reponse
 * JSON structuree (fin du parsing bancal N8N).
 */
final readonly class GabaritExtraction
{
    /**
     * @param array<string, mixed> $schema responseSchema (sous-ensemble OpenAPI)
     */
    public function __construct(
        public string $version,
        public string $prompt,
        public array $schema,
    ) {
    }
}
