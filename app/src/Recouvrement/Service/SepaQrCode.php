<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Genere un QR virement SEPA (norme EPC069-12, « BCD ») a partir des coordonnees
 * de paiement d'un etablissement. Rendu en PNG data-URI, embarquable directement
 * dans le PDF du releve (dompdf lit les data-URI PNG).
 *
 * Le QR n'est lu que par les applis bancaires qui supportent l'EPC (support
 * inegal en France) : c'est un BONUS a cote du RIB en clair, jamais un blocage.
 */
final class SepaQrCode
{
    /** Memoisation intra-requete : un meme payload n'est encode qu'une fois. */
    /** @var array<string, string> */
    private array $cache = [];

    /**
     * PNG data-URI du QR SEPA, ou chaine vide si les donnees sont insuffisantes
     * (pas d'IBAN, montant hors bornes EPC).
     */
    public function dataUri(?string $iban, ?string $bic, string $beneficiaire, string $montant, string $reference): string
    {
        $payload = self::payload($iban, $bic, $beneficiaire, $montant, $reference);
        if (null === $payload) {
            return '';
        }
        if (isset($this->cache[$payload])) {
            return $this->cache[$payload];
        }

        $result = (new Builder())->build(
            writer: new PngWriter(),
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 300,
            margin: 4,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        );

        return $this->cache[$payload] = $result->getDataUri();
    }

    /**
     * Construit le payload EPC069-12 (SEPA Credit Transfer QR), version 002.
     * Champs separes par LF, dans l'ordre : Service Tag (BCD), Version (002),
     * Jeu de caracteres (1 = UTF-8), Identification (SCT), BIC, Nom du
     * beneficiaire (<=70), IBAN, Montant (EUR#.##), Purpose (vide), Reference
     * structuree (vide), Reference libre (<=140).
     *
     * Renvoie null si IBAN absent ou montant hors bornes EPC (0,01 a 999 999 999,99 €).
     */
    public static function payload(?string $iban, ?string $bic, string $beneficiaire, string $montant, string $reference): ?string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
        if ('' === $iban) {
            return null;
        }

        $valeur = (float) str_replace([' ', ','], ['', '.'], $montant);
        if ($valeur < 0.01 || $valeur > 999_999_999.99) {
            return null;
        }

        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            strtoupper(trim((string) $bic)),
            self::tronque($beneficiaire, 70),
            $iban,
            'EUR'.number_format($valeur, 2, '.', ''),
            '',
            '',
            self::tronque($reference, 140),
        ]);
    }

    private static function tronque(string $texte, int $max): string
    {
        $texte = trim((string) preg_replace('/\s+/', ' ', $texte));

        return mb_substr($texte, 0, $max);
    }
}
