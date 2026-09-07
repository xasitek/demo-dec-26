<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/** Binaire d'une piece a analyser (contenu brut + type MIME). */
final readonly class ContenuPiece
{
    public function __construct(
        public string $contenu,
        public string $mime,
    ) {
    }
}
