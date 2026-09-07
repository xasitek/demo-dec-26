<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

/**
 * Fabrique des coordonnees bancaires FICTIVES mais MATHEMATIQUEMENT VALIDES.
 *
 * Le module refuse un IBAN mal forme, et c'est un garde-fou qu'on ne contourne
 * pas : on le nourrit. Un IBAN de demonstration doit donc porter une vraie cle
 * MOD 97 et une vraie cle RIB, sinon la demonstration ne prouve rien -- elle
 * prouve seulement qu'on a desactive le controle.
 *
 * Le compte n'existe nulle part : la banque porte le code 99999, qui n'est
 * attribue a aucun etablissement bancaire francais, et aucun ordre ne part.
 * Ce qui est valide ici, c'est la FORME, pas l'existence.
 */
final class IbanFictif
{
    /** Code banque reserve a la demonstration. Aucune banque ne le porte. */
    private const BANQUE = '99999';

    /**
     * Un IBAN francais complet et valide, deterministe pour une graine donnee.
     *
     * Structure francaise : FR + 2 cles MOD97 + 5 banque + 5 guichet
     * + 11 compte + 2 cle RIB = 27 caracteres.
     */
    public static function pour(string $graine): string
    {
        $n = 0;
        foreach (str_split($graine) as $c) {
            $n = ($n * 31 + \ord($c)) % 1000000007;
        }

        $guichet = str_pad((string) ($n % 99999), 5, '0', \STR_PAD_LEFT);
        $compte = str_pad((string) (($n * 7919) % 99999999999), 11, '0', \STR_PAD_LEFT);
        $cleRib = self::cleRib(self::BANQUE, $guichet, $compte);

        $bban = self::BANQUE.$guichet.$compte.$cleRib;

        return 'FR'.self::clesIban($bban).$bban;
    }

    /** L'IBAN est-il mathematiquement valide ? MOD 97 doit rendre 1. */
    public static function valide(string $iban): bool
    {
        $i = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
        if (\strlen($i) < 15 || \strlen($i) > 34) {
            return false;
        }

        return 1 === self::mod97(substr($i, 4).substr($i, 0, 4));
    }

    /** Le BIC respecte-t-il la syntaxe ISO 9362 ? */
    public static function bicValide(string $bic): bool
    {
        return 1 === preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($bic)));
    }

    /**
     * La cle RIB francaise : 97 - ((89 x banque + 15 x guichet + 3 x compte) mod 97).
     *
     * Le compte est numerique ici, donc la table de conversion des lettres n'a
     * pas a intervenir.
     */
    private static function cleRib(string $banque, string $guichet, string $compte): string
    {
        $reste = (self::mod97Nombre($banque) * 89
            + self::mod97Nombre($guichet) * 15
            + self::mod97Nombre($compte) * 3) % 97;

        return str_pad((string) (97 - $reste), 2, '0', \STR_PAD_LEFT);
    }

    /** Les deux cles de controle de l'IBAN : 98 - (BBAN + 'FR00') mod 97. */
    private static function clesIban(string $bban): string
    {
        $cle = 98 - self::mod97($bban.'FR00');

        return str_pad((string) $cle, 2, '0', \STR_PAD_LEFT);
    }

    /** MOD 97 sur une chaine alphanumerique, lettres converties (A = 10). */
    private static function mod97(string $chaine): int
    {
        $reste = 0;
        foreach (str_split(strtoupper($chaine)) as $c) {
            $valeur = ctype_digit($c) ? $c : (string) (\ord($c) - 55);
            foreach (str_split($valeur) as $chiffre) {
                $reste = ($reste * 10 + (int) $chiffre) % 97;
            }
        }

        return $reste;
    }

    /** MOD 97 sur une chaine purement numerique, sans debordement. */
    private static function mod97Nombre(string $chiffres): int
    {
        $reste = 0;
        foreach (str_split($chiffres) as $c) {
            $reste = ($reste * 10 + (int) $c) % 97;
        }

        return $reste;
    }
}
