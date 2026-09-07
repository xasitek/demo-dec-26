<?php

declare(strict_types=1);

namespace App\Recouvrement\Postal;

use App\Recouvrement\Enum\TypeCourrier;

/**
 * Donnees d'un courrier a expedier, independantes du prestataire : le PDF (relevé +
 * factures deja fusionne), le destinataire, l'adresse postale et le produit voulu.
 */
final class CourrierAEnvoyer
{
    public function __construct(
        public readonly string $pdf,
        public readonly string $destinataireNom,
        public readonly string $adresse,
        public readonly TypeCourrier $type,
        /** Notre reference metier (ex. compte + niveau) pour rapprochement. */
        public readonly ?string $reference = null,
    ) {
    }
}
