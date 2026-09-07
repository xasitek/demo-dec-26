<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/**
 * Fournisseur d'OCR/extraction (Gemini, Claude...). Ne connait NI le metier NI le
 * dossier : il recoit un gabarit (prompt + schema JSON) et un binaire, rend du JSON.
 * Toute la normalisation/comparaison reste en PHP (cf. docs/MODULE_REMBOURSEMENT.md 4.4).
 */
interface ProviderOcr
{
    /**
     * @throws Exception\ProviderIndisponibleException  erreur technique/transitoire (retryable)
     * @throws Exception\ReponseNonExploitableException reponse non decodable malgre le schema
     */
    public function extraire(ContenuPiece $contenu, GabaritExtraction $gabarit): ResultatExtraction;

    public function nom(): string;

    /** Circuit-breaker basique : le provider est-il utilisable (cle presente...). */
    public function estDisponible(): bool;
}
