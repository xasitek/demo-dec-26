<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Prepare les pieces justificatives pour un stockage EN BASE (colonne bytea de
 * DossierPiece). Choix impose par l'hebergement Render : le web et le worker (analyse IA
 * + generation OD/SEPA) sont des services distincts SANS disque partage ; la base commune
 * est le seul stockage que les deux voient. Bonus : plus de perte au redeploy (le disque
 * d'un service Render est ephemere).
 *
 * Ce service ne PERSISTE rien : il rend le contenu binaire (+ conversion en PDF des
 * tableurs/CSV pour l'IA et l'affichage en ligne) et les metadonnees a poser sur
 * DossierPiece. La persistance reste a la charge de l'appelant.
 */
final class StockagePieces
{
    public function __construct(
        private readonly ConvertisseurPiece $convertisseur,
    ) {
    }

    /**
     * Prepare une piece uploadee (contenu binaire + metadonnees) pour le stockage en base.
     *
     * @return array{contenu: string, nomOriginal: string, mime: string, taille: int, hash: string}
     */
    public function stocker(Dossier $dossier, string $typePiece, UploadedFile $fichier): array
    {
        $nomOriginal = $fichier->getClientOriginalName();
        $mime = $fichier->getMimeType() ?? 'application/octet-stream';

        // Piece NON PDF/image (tableur, CSV, texte) -> convertie en PDF pour l'IA et
        // l'affichage en ligne. Repli : si la conversion echoue, on garde l'original.
        $pdf = null;
        if (!ConvertisseurPiece::estPdfOuImage($mime)) {
            try {
                $pdf = $this->convertisseur->versPdf($fichier);
            } catch (Throwable) {
                $pdf = null;
            }
        }

        if (null !== $pdf) {
            $contenu = $pdf;
            $mimeFinal = 'application/pdf';
            $extension = 'pdf';
        } else {
            $contenu = (string) file_get_contents($fichier->getPathname());
            $mimeFinal = $mime;
            $extension = $fichier->guessExtension() ?: 'bin';
        }

        return [
            'contenu' => $contenu,
            'nomOriginal' => '' !== $nomOriginal ? $nomOriginal : self::assainir($typePiece).'.'.$extension,
            'mime' => $mimeFinal,
            'taille' => \strlen($contenu),
            'hash' => hash('sha256', $contenu),
        ];
    }

    /**
     * Prepare un CONTENU deja genere (CSV OD, XML SEPA...) pour le stockage en base.
     *
     * @return array{contenu: string, mime: string, taille: int, hash: string}
     */
    public function stockerContenu(string $contenu, string $mime): array
    {
        return [
            'contenu' => $contenu,
            'mime' => $mime,
            'taille' => \strlen($contenu),
            'hash' => hash('sha256', $contenu),
        ];
    }

    private static function assainir(string $valeur): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $valeur) ?? '_';
    }
}
