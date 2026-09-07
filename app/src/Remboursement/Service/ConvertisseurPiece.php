<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Recouvrement\Service\HtmlPdfConverter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Convertit une piece NON PDF/image (tableur Excel, CSV, texte) en PDF, pour qu'elle
 * soit lisible par l'IA d'extraction ET affichable en ligne. 100 % PHP (PhpSpreadsheet
 * en lecture + dompdf en sortie) : aucun worker LibreOffice/Ghostscript requis.
 */
final class ConvertisseurPiece
{
    /** Au-dela, on tronque (une "petit compte" reste petit ; garde-fou memoire dompdf). */
    private const MAX_LIGNES = 500;
    private const MAX_COLONNES = 30;

    public function __construct(
        private readonly HtmlPdfConverter $htmlPdf,
    ) {
    }

    public static function estPdfOuImage(string $mime): bool
    {
        return 'application/pdf' === $mime || str_starts_with($mime, 'image/');
    }

    /** PDF (binaire) d'un fichier tableur / CSV / texte. */
    public function versPdf(UploadedFile $fichier): string
    {
        return $this->htmlPdf->enPdf($this->versHtml($fichier));
    }

    private function versHtml(UploadedFile $fichier): string
    {
        $titre = $fichier->getClientOriginalName();
        $titre = '' !== $titre ? $titre : 'Document';
        $ext = strtolower($fichier->getClientOriginalExtension() ?: (string) $fichier->guessExtension());

        $lignes = \in_array($ext, ['csv', 'txt'], true)
            ? $this->lignesCsv($fichier)
            : $this->lignesTableur($fichier);

        return $this->htmlTable($titre, $lignes);
    }

    /** @return list<list<string>> */
    private function lignesCsv(UploadedFile $fichier): array
    {
        $contenu = (string) file_get_contents($fichier->getPathname());
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu) ?? $contenu; // BOM UTF-8

        $brutes = preg_split('/\r\n|\r|\n/', $contenu) ?: [];
        $brutes = array_values(array_filter($brutes, static fn (string $l): bool => '' !== trim($l)));

        $premiere = $brutes[0] ?? '';
        $delim = substr_count($premiere, ';') > substr_count($premiere, ',') ? ';' : ',';

        $lignes = [];
        foreach (\array_slice($brutes, 0, self::MAX_LIGNES) as $ligne) {
            $cellules = str_getcsv($ligne, $delim, '"', '\\');
            $lignes[] = array_map(static fn ($c): string => (string) $c, \array_slice($cellules, 0, self::MAX_COLONNES));
        }

        return $lignes;
    }

    /** @return list<list<string>> */
    private function lignesTableur(UploadedFile $fichier): array
    {
        $spreadsheet = IOFactory::load($fichier->getPathname());
        /** @var array<int, array<int, mixed>> $data */
        $data = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $lignes = [];
        foreach (\array_slice($data, 0, self::MAX_LIGNES) as $row) {
            $lignes[] = array_map(
                static fn ($c): string => (string) (\is_scalar($c) ? $c : ''),
                \array_slice(array_values($row), 0, self::MAX_COLONNES),
            );
        }

        return $lignes;
    }

    /**
     * @param list<list<string>> $lignes
     */
    private function htmlTable(string $titre, array $lignes): string
    {
        $corps = '';
        foreach ($lignes as $i => $row) {
            $tag = 0 === $i ? 'th' : 'td';
            $style = 0 === $i ? 'background:#2D3250;color:#fff;' : '';
            $cellules = '';
            foreach ($row as $c) {
                $cellules .= sprintf('<%s style="border:1px solid #ccc;padding:4px 6px;font-size:10px;%s">%s</%1$s>', $tag, $style, htmlspecialchars($c, \ENT_QUOTES));
            }
            $corps .= '<tr>'.$cellules.'</tr>';
        }

        return '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>@page{margin:14mm}body{font-family:"DejaVu Sans",sans-serif;color:#3A3A3A}</style></head>'
            .'<body><h3 style="color:#2D3250;margin:0 0 10px">'.htmlspecialchars($titre, \ENT_QUOTES).'</h3>'
            .'<table style="border-collapse:collapse;width:100%">'.$corps.'</table></body></html>';
    }
}
