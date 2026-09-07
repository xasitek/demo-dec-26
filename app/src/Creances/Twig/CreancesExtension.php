<?php

declare(strict_types=1);

namespace App\Creances\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Extension Twig propre au module Recouvrement.
 *
 * Filtre `|json_decode` : decode une chaine JSON en tableau. Si la valeur
 * est deja un tableau (DBAL Postgres rend le JSONB comme tableau dans la
 * plupart des versions), elle est renvoyee telle quelle.
 *
 * Filtre `|montant_eur` : format monetaire francais (12 345,67 EUR).
 */
final class CreancesExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', $this->jsonDecode(...)),
            new TwigFilter('montant_eur', $this->montantEur(...)),
            new TwigFilter('tel_format', $this->telFormat(...)),
            new TwigFilter('base64_encode_url', $this->base64EncodeUrl(...)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonDecode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function montantEur(mixed $value, int $decimales = 2): string
    {
        if (!is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, $decimales, ',', ' ').' EUR';
    }

    /**
     * Formatte un numero de telephone francais : retire tous les caracteres
     * non-chiffres puis insere un espace tous les 2 chiffres ("0388980051"
     * → "03 88 98 00 51"). Renvoie la valeur d'origine si ce n'est pas une
     * chaine exploitable.
     */
    public function telFormat(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ('' === $digits) {
            return '';
        }

        return trim((string) chunk_split($digits, 2, ' '));
    }

    /**
     * Encode une chaine en base64-url (RFC 4648 §5) : remplace +/ par -_,
     * supprime le padding =. Lisible dans une URL sans % escapes.
     * Decodage cote PHP : base64_decode(strtr($v, '-_', '+/')).
     */
    public function base64EncodeUrl(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
