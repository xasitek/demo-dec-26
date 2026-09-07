<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

/**
 * Fusionne plusieurs CSV OD (ecritures comptables Gestion commerciale, meme format) en un seul :
 * l'en-tete UNE fois, puis toutes les lignes d'ecriture du lot. Sert au "OD lettrage"
 * global (archive de paiement ET depot sur le partage comptable X:). Renvoie '' si rien
 * d'exploitable.
 */
final class FusionOd
{
    /**
     * @param list<string> $contenus
     */
    public static function fusionner(array $contenus): string
    {
        $entete = null;
        $lignes = [];
        foreach ($contenus as $contenu) {
            $rows = preg_split('/\r\n|\r|\n/', trim($contenu)) ?: [];
            if ('' === trim((string) ($rows[0] ?? ''))) {
                continue;
            }
            $entete ??= $rows[0];
            foreach (\array_slice($rows, 1) as $ligne) {
                if ('' !== trim($ligne)) {
                    $lignes[] = $ligne;
                }
            }
        }

        return null === $entete ? '' : $entete."\n".implode("\n", $lignes)."\n";
    }
}
