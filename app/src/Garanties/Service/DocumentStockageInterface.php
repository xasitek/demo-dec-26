<?php

declare(strict_types=1);

namespace App\Garanties\Service;

/**
 * Abstraction du stockage du binaire d'un document, indexe par sa reference.
 *
 * Impl actuelle : Postgres bytea (PostgresDocumentStockage), reste 100% sur
 * Render. On pourra basculer vers un stockage objet (Cloudflare R2, Backblaze)
 * en fournissant une autre implementation, sans toucher a l'ingestion ni a
 * l'affichage. Les metadonnees (reference, periode, mime...) restent en base
 * dans garanties.document quel que soit le stockage du binaire.
 */
interface DocumentStockageInterface
{
    /**
     * Ecrit (ou ecrase) le binaire associe a une reference deja presente dans
     * garanties.document.
     */
    public function ecrire(string $reference, string $contenu): void;

    /**
     * Lit le binaire associe a une reference, ou null s'il n'existe pas encore.
     */
    public function lire(string $reference): ?string;
}
