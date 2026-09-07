<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

use App\Remboursement\Enum\DossierMotif;

/**
 * Contrat d'extraction IA d'une piece justificative (OCR + extraction structuree),
 * remplacant les nœuds Gemini de N8N. Deux implementations :
 *   - ExtracteurPieceStub : aucun appel externe (defaut ; cout nul) ;
 *   - (a venir) ExtracteurPieceGemini : appel reel a Gemini 2.5 Pro (cle en env).
 *
 * L'IA n'a qu'un role d'AIDE : elle pre-remplit les valeurs "controle_*", que le
 * comptable verifie/corrige. Elle ne debloque JAMAIS seule un paiement (la garde
 * reste le workflow + la validation directeur/comptable).
 */
interface ExtracteurPiece
{
    /**
     * Extrait les champs pertinents d'une piece. Cles de retour normalisees :
     * 'nom', 'iban', 'bic', 'montant', 'immatriculation', 'icar' (selon la piece).
     * "Extraire sans normaliser" : on rend la valeur lue telle quelle.
     *
     * @param string $cheminAbsolu chemin du fichier stocke par l'app
     * @param string $typePiece    cle de DossierMotif::piecesRequises (rib, carte_grise, ...)
     *
     * @return array<string, string> champs extraits (vide si rien / non supporte)
     */
    public function extraire(string $cheminAbsolu, string $mimeType, string $typePiece, DossierMotif $motif): array;

    /** Identifiant lisible de l'implementation (pour l'audit / la trace). */
    public function nom(): string;
}
