<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

/**
 * Normalise les saisies libres de la secretaire vers une forme CANONIQUE stockee
 * en base, pour que le controle IA, l'imputation, le compteur de doublons et le
 * futur matching tombent juste quel que soit le format tape.
 */
final class NormalisationSaisie
{
    /**
     * Montant saisi en format humain -> decimale canonique "1234.56".
     *
     * Tolere le symbole € et les lettres, les espaces (normal U+0020, insecable
     * U+00A0, fine insecable U+202F) et la tabulation, ainsi que les separateurs
     * de milliers et de decimale en ',' ou '.'. Quand les deux separateurs
     * coexistent, le plus a DROITE est la decimale et l'autre les milliers. Un
     * separateur unique suivi de 3 chiffres est traite comme milliers (convention
     * montant : "1.234" = 1234). Retourne null si aucun chiffre exploitable.
     */
    public function montant(?string $brut): ?string
    {
        if (null === $brut) {
            return null;
        }

        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', "\t", '€'], '', trim($brut));
        $s = preg_replace('/[^0-9,.\-]/', '', $s) ?? '';
        if ('' === $s || 1 !== preg_match('/\d/', $s)) {
            return null;
        }

        $negatif = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        $virgule = strrpos($s, ',');
        $point = strrpos($s, '.');

        if (false !== $virgule && false !== $point) {
            // Les deux presents : le plus a droite = decimale, l'autre = milliers.
            $decimale = $virgule > $point ? ',' : '.';
            $milliers = ',' === $decimale ? '.' : ',';
            $s = str_replace($milliers, '', $s);
            $s = str_replace($decimale, '.', $s);
        } elseif (false !== $virgule) {
            $s = self::separateurUnique($s, ',');
        } elseif (false !== $point) {
            $s = self::separateurUnique($s, '.');
        }

        $s = rtrim($s, '.'); // separateur decimale sans chiffre derriere ("1234,")
        if (!is_numeric($s)) {
            return null;
        }

        $valeur = (float) $s;
        if ($negatif) {
            $valeur = -$valeur;
        }

        return number_format($valeur, 2, '.', '');
    }

    /**
     * Immatriculation canonique (MAJUSCULES, alphanumerique seul), alignee sur la
     * cle anti-doublon. "AB-123-CD", "ab 123 cd", "M-AB 1234" -> "AB123CD",
     * "MAB1234". Marche FR / allemand / etranger. Retourne null si vide.
     */
    public function immatriculation(?string $brut): ?string
    {
        if (null === $brut) {
            return null;
        }

        $v = CleDoublon::normaliserImmatriculation($brut);

        return '' === $v ? null : $v;
    }

    /**
     * Separateur unique present : decide s'il est decimal ou milliers. Plusieurs
     * occurrences => milliers groupes (1.234.567). Une occurrence suivie de 3
     * chiffres => milliers (convention montant). Sinon => decimale.
     */
    private static function separateurUnique(string $s, string $sep): string
    {
        if (substr_count($s, $sep) > 1) {
            return str_replace($sep, '', $s);
        }

        $position = strpos($s, $sep);
        $apres = strlen($s) - (int) $position - 1;
        if (3 === $apres) {
            return str_replace($sep, '', $s);
        }

        return str_replace($sep, '.', $s);
    }
}
