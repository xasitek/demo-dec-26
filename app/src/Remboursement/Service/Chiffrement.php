<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

/**
 * Chiffrement symetrique au repos des donnees personnelles sensibles (IBAN/BIC
 * clients) — conformite RGPD. Utilise sodium (secretbox : XSalsa20-Poly1305),
 * cle derivee de REMBOURSEMENT_CHIFFREMENT_KEY (repli APP_SECRET).
 *
 * Volontairement STATIQUE et sans dependance : appele depuis les getters/setters
 * des entites (une entite Doctrine ne peut pas injecter de service). La colonne
 * stocke le chiffre (prefixe "v1:") ; une valeur non prefixee est rendue telle
 * quelle (tolerance a une donnee historique non chiffree). Aucune recherche SQL
 * sur ces colonnes (chiffrees) : l'anti-doublon passe par une cle dediee en clair.
 *
 * NB : a terme, envisager une VRAIE cle dediee (coffre / variable Render) et une
 * rotation ; ici on derive de l'env, suffisant pour le socle.
 */
final class Chiffrement
{
    private const PREFIXE = 'v1:';

    public static function chiffrer(?string $clair): ?string
    {
        if (null === $clair || '' === $clair) {
            return $clair;
        }
        $cle = self::cle();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = sodium_crypto_secretbox($clair, $nonce, $cle);

        return self::PREFIXE.base64_encode($nonce.$chiffre);
    }

    public static function dechiffrer(?string $stocke): ?string
    {
        if (null === $stocke || '' === $stocke) {
            return $stocke;
        }
        if (!str_starts_with($stocke, self::PREFIXE)) {
            return $stocke; // donnee non chiffree (compat) : rendue telle quelle
        }
        $donnees = base64_decode(substr($stocke, \strlen(self::PREFIXE)), true);
        if (false === $donnees || \strlen($donnees) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($donnees, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = substr($donnees, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clair = sodium_crypto_secretbox_open($chiffre, $nonce, self::cle());

        return false === $clair ? null : $clair;
    }

    /** Masque un IBAN pour l'affichage par defaut (RGPD) : FR76 **** **** 1234. */
    public static function masquer(?string $clair): string
    {
        $clair = null === $clair ? '' : trim($clair);
        if ('' === $clair) {
            return '';
        }
        $compact = str_replace(' ', '', $clair);
        if (\strlen($compact) <= 8) {
            return str_repeat('*', max(0, \strlen($compact)));
        }

        return substr($compact, 0, 4).' **** '.substr($compact, -4);
    }

    /**
     * Empreinte DETERMINISTE d'une valeur (index aveugle / blind index) : permet de
     * comparer/retrouver en base (ex. deux dossiers de meme IBAN) sans jamais y stocker
     * la valeur en clair. HMAC-SHA256 avec la meme cle que le chiffrement. La valeur doit
     * deja etre normalisee par l'appelant (majuscules, sans espaces).
     */
    public static function empreinte(string $valeurNormalisee): string
    {
        return hash_hmac('sha256', $valeurNormalisee, self::cle());
    }

    private static function cle(): string
    {
        $secret = $_ENV['REMBOURSEMENT_CHIFFREMENT_KEY']
            ?? $_SERVER['REMBOURSEMENT_CHIFFREMENT_KEY']
            ?? $_ENV['APP_SECRET']
            ?? $_SERVER['APP_SECRET']
            ?? 'cle-par-defaut-a-remplacer';

        return sodium_crypto_generichash((string) $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
