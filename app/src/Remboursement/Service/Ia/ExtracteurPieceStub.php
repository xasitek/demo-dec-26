<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

use App\Remboursement\Enum\DossierMotif;

/**
 * Implementation par defaut de l'extraction IA : NE FAIT AUCUN APPEL EXTERNE
 * (cout nul, pas de cle requise). Rend un resultat vide : les champs "controle_*"
 * ne sont pas pre-remplis, le comptable saisit/verifie manuellement.
 *
 * Sert de bouchon tant que l'implementation Gemini reelle (ExtracteurPieceGemini,
 * appel HTTP + cle API en variable d'env) n'est pas branchee. Le contrat et la
 * couture (interface, aggregation, workflow) sont ainsi en place sans depense.
 */
final class ExtracteurPieceStub implements ExtracteurPiece
{
    public function extraire(string $cheminAbsolu, string $mimeType, string $typePiece, DossierMotif $motif): array
    {
        return [];
    }

    public function nom(): string
    {
        return 'stub';
    }
}
