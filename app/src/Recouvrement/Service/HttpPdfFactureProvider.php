<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use Psr\Log\LoggerInterface;

/**
 * Adaptateur reel : telecharge le PDF de la facture depuis son URL Progiciel
 * (v_impayes.chemin_pdf, ex. http://serveur-progiciel/INTERFACES/PDF/...).
 *
 * A executer la ou Progiciel est joignable (worker sur le serveur interne, ou poste
 * de dev sur le reseau / VPN). Si l'URL est absente, injoignable, ou que la
 * reponse n'est pas un PDF, retourne null : la relance part alors SANS cette
 * piece jointe (l'envoi n'est jamais bloque par un PDF manquant).
 *
 * Sans dependance HTTP supplementaire : file_get_contents avec timeout court.
 */
final class HttpPdfFactureProvider implements PdfFactureProvider
{
    private const TIMEOUT_SECONDES = 10;
    private const TAILLE_MAX = 20_000_000; // garde-fou : 20 Mo

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function recuperer(string $referenceFacture, string $compteCode, ?string $cheminPdf = null): ?string
    {
        $url = null !== $cheminPdf ? trim($cheminPdf) : '';
        if ('' === $url || 1 !== preg_match('#^https?://#i', $url)) {
            return null;
        }

        $contexte = stream_context_create([
            'http' => ['timeout' => self::TIMEOUT_SECONDES, 'ignore_errors' => true],
            'https' => ['timeout' => self::TIMEOUT_SECONDES, 'ignore_errors' => true],
        ]);

        $contenu = @file_get_contents($url, false, $contexte, 0, self::TAILLE_MAX);

        if (false === $contenu || '' === $contenu) {
            $this->logger->warning('Recouvrement : PDF facture injoignable.', [
                'url' => $url,
                'compte' => $compteCode,
                'reference' => $referenceFacture,
            ]);

            return null;
        }

        // Verifie que la reponse est bien un PDF (et pas une page d'erreur HTML).
        if (!str_starts_with($contenu, '%PDF')) {
            $this->logger->warning('Recouvrement : reponse non-PDF pour une facture.', [
                'url' => $url,
                'compte' => $compteCode,
            ]);

            return null;
        }

        return $contenu;
    }
}
