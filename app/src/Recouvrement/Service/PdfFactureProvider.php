<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

/**
 * Fournit le PDF d'une facture client pour l'attacher a une relance.
 *
 * Implementations :
 *   - HttpPdfFactureProvider : telecharge le vrai PDF depuis l'URL Progiciel (chemin_pdf) ;
 *   - StubPdfFactureProvider : PDF placeholder (hors reseau Progiciel / demo).
 *
 * Le couple (reference facture, code compte) identifie la facture ; cheminPdf
 * porte l'URL Progiciel quand elle est connue. Retourne le binaire PDF, ou null si
 * indisponible (la relance est alors envoyee sans cette piece jointe).
 */
interface PdfFactureProvider
{
    /**
     * @param string      $referenceFacture reference de la facture (v_impayes.reference_facture)
     * @param string      $compteCode       code compte client (v_impayes.compte)
     * @param string|null $cheminPdf        URL Progiciel du PDF (v_impayes.chemin_pdf), si connue
     *
     * @return string|null binaire PDF, ou null si indisponible
     */
    public function recuperer(string $referenceFacture, string $compteCode, ?string $cheminPdf = null): ?string;
}
