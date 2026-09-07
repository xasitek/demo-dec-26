<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

/**
 * Implementation de developpement : genere un PDF placeholder a la volee.
 *
 * Aucune dependance externe (dompdf n'est pas installe) : on ecrit un PDF 1.4
 * minimal mais valide (une page A4, police Helvetica) portant la reference de
 * facture et le code compte. Suffisant pour valider la chaine d'envoi en local
 * (MailHog) sans toucher Progiciel. En production, un adaptateur dedie remplacera ce
 * binding pour fournir le vrai PDF.
 *
 * NOTE : le PDF natif n'echappe que le strict necessaire (parentheses et
 * antislash, separateurs de chaines PostScript). Les libelles passes ici sont
 * des references comptables ASCII, jamais du contenu utilisateur libre.
 */
final class StubPdfFactureProvider implements PdfFactureProvider
{
    // Type de retour concret (string) plus precis que l'interface (?string) :
    // le stub genere toujours un PDF, il ne renvoie jamais null (covariance).
    public function recuperer(string $referenceFacture, string $compteCode, ?string $cheminPdf = null): string
    {
        $reference = '' !== trim($referenceFacture) ? trim($referenceFacture) : 'reference inconnue';

        $lignes = [
            'GROUPE SYNTHAUTO - Document de facturation',
            '',
            'Facture : '.$reference,
            'Compte client : '.$compteCode,
            '',
            'Document de demonstration (environnement de test).',
            'En production, la facture reelle issue de Progiciel est jointe.',
        ];

        return $this->construirePdf($lignes);
    }

    /**
     * Construit un PDF 1.4 monopage avec une suite de lignes de texte.
     *
     * @param list<string> $lignes
     */
    private function construirePdf(array $lignes): string
    {
        $flux = $this->contenuTexte($lignes);

        $objets = [];
        // 1 : catalogue
        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        // 2 : arbre de pages
        $objets[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        // 3 : page A4 (595 x 842 points)
        $objets[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            .'/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
        // 4 : police standard
        $objets[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        // 5 : flux de contenu
        $objets[5] = '<< /Length '.\strlen($flux)." >>\nstream\n".$flux."\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objets as $num => $corps) {
            $offsets[$num] = \strlen($pdf);
            $pdf .= $num." 0 obj\n".$corps."\nendobj\n";
        }

        $positionXref = \strlen($pdf);
        $nbObjets = \count($objets) + 1; // + l'objet libre 0

        $pdf .= "xref\n0 ".$nbObjets."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $nbObjets; ++$i) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size ".$nbObjets." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$positionXref."\n%%EOF";

        return $pdf;
    }

    /**
     * Genere le flux PostScript de texte (une ligne par entree).
     *
     * @param list<string> $lignes
     */
    private function contenuTexte(array $lignes): string
    {
        $flux = "BT\n/F1 14 Tf\n14 TL\n70 770 Td\n";
        foreach ($lignes as $ligne) {
            $flux .= '('.$this->echapper($ligne).") Tj\nT*\n";
        }
        $flux .= 'ET';

        return $flux;
    }

    /**
     * Echappe une chaine pour une litterale PostScript et retire les accents
     * (l'encodage par defaut du PDF natif ne gere pas l'UTF-8).
     */
    private function echapper(string $texte): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
        if (false === $ascii) {
            $ascii = $texte;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
