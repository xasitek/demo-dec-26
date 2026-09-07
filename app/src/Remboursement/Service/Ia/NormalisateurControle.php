<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/**
 * Normalisation des valeurs pour comparaison (cote PHP, testable) : la comparaison
 * "saisie brute vs extraction IA" se fait sur ces formes normalisees.
 */
final class NormalisateurControle
{
    public static function nom(?string $valeur): string
    {
        $v = preg_replace('/\s+/', ' ', (string) $valeur) ?? '';

        return trim(mb_strtoupper($v));
    }

    public static function alphaNum(?string $valeur): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $valeur));
    }

    public static function iban(?string $valeur): string
    {
        return self::alphaNum($valeur);
    }

    public static function bic(?string $valeur): string
    {
        return self::alphaNum($valeur);
    }

    public static function immatriculation(?string $valeur): string
    {
        return self::alphaNum($valeur);
    }

    /** Montant normalise en chaine decimale a 2 decimales, ou null si non numerique. */
    public static function montant(mixed $valeur): ?string
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }
        $texte = str_replace([' ', "\u{00a0}"], '', (string) $valeur);
        $texte = str_replace(',', '.', $texte);
        if (!is_numeric($texte)) {
            return null;
        }

        return number_format((float) $texte, 2, '.', '');
    }
}
