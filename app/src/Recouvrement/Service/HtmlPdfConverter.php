<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Convertit du HTML (styles inline) en PDF A4 portrait via dompdf. Pur PHP :
 * fonctionne sans Ghostscript (celui-ci ne sert qu'a FUSIONNER des PDF, pas a
 * les produire). Partage par la lettre courrier et la page de garde des e-mails.
 */
final class HtmlPdfConverter
{
    public function enPdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
