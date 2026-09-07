<?php

declare(strict_types=1);

namespace App\Recouvrement\Referentiel;

/**
 * Referentiel des etablissements SYNTHAUTO : code etablissement -> libelle commercial.
 *
 * LIBELLES ENTIEREMENT SYNTHETIQUES. Ce dictionnaire portait, dans l'application
 * d'origine, les enseignes reelles du groupe. La copie de demonstration les a
 * toutes remplacees par des libelles fabriques -- meme structure, meme nombre
 * d'entrees, memes codes, aucune enseigne, aucune ville, aucune marque reelle.
 *
 * Dictionnaire fourni par le metier (comme MoteurFiltre::LIBELLES_VALEURS), volontairement
 * NON stocke en base : la liste bouge au rythme des ouvertures/fermetures de sites, elle
 * tient en une constante, et un lookup en memoire evite une requete par ecran.
 *
 * La cle metier est le code etablissement (colonne `codeetab` de recouvrement.v_impayes,
 * zero-paddee : '093'). Attention, `code_entite` de la meme vue est le code SOCIETE
 * (code societe synthetique), pas un etablissement. Les codes etant zero-paddes en base
 * ('093' reste une cle chaine en PHP alors que '181' devient un entier), tout est
 * normalise en entier avant lookup.
 *
 * Un code absent d'ici s'affiche tel quel (aucune ecriture n'est masquee).
 */
final class Etablissements
{
    /**
     * Code etablissement (entier) -> libelle SYNTHETIQUE.
     * Trie par code croissant, la societe synthetique est rappelee en commentaire.
     *
     * @var array<int, string>
     */
    public const LIBELLES = [
        21 => 'SYNTHAUTO GILFEX 51 VN',                    // SOC01
        31 => 'SYNTHAUTO MURFEX 54 VO',                    // SOC02
        51 => 'SYNTHAUTO VALTEG 57 CARR',                  // SOC03
        61 => 'SYNTHAUTO RYNGIL 67 APV',                   // SOC04
        62 => 'SYNTHAUTO NEMJUV 68 VN',                    // SOC05
        63 => 'SYNTHAUTO WENVOR 51 VO',                    // SOC06
        71 => 'SYNTHAUTO PELDAN 54 CARR',                  // SOC07
        81 => 'SYNTHAUTO GILFERD 57 APV',                  // SOC08
        82 => 'SYNTHAUTO LIMZOR 67 VN',                    // SOC09
        83 => 'SYNTHAUTO PELVAL 68 VO',                    // SOC10
        84 => 'SYNTHAUTO ZORVAL 51 CARR',                  // SOC11
        85 => 'SYNTHAUTO KALRIN 54 APV',                   // SOC12
        90 => 'SYNTHAUTO LIMBRIX 57 VN',                   // SOC13
        91 => 'SYNTHAUTO GILLIM 67 VO',                    // SOC14
        92 => 'SYNTHAUTO VALTAL 68 CARR',                  // SOC15
        93 => 'SYNTHAUTO TEGBRY 51 APV',                   // SOC16
        94 => 'SYNTHAUTO DANBRY 54 VN',                    // SOC17
        95 => 'SYNTHAUTO BRYRYN 57 VO',                    // SOC18
        96 => 'SYNTHAUTO BRIXFERD 67 CARR',                // SOC01
        101 => 'SYNTHAUTO DANMES 68 APV',                  // SOC02
        102 => 'SYNTHAUTO MESDAN 51 VN',                   // SOC03
        103 => 'SYNTHAUTO BRIXRIN 54 VO',                  // SOC04
        104 => 'SYNTHAUTO JUVVAL 57 CARR',                 // SOC05
        121 => 'SYNTHAUTO RYNCAV 67 APV',                  // SOC06
        131 => 'SYNTHAUTO PLUDAN 68 VN',                   // SOC07
        141 => 'SYNTHAUTO FEXKUR 51 VO',                   // SOC08
        142 => 'SYNTHAUTO TEGFERD 54 CARR',                // SOC09
        143 => 'SYNTHAUTO GILKUR 57 APV',                  // SOC10
        152 => 'SYNTHAUTO BRYPLU 67 VN',                   // SOC11
        153 => 'SYNTHAUTO OSKJUV 68 VO',                   // SOC12
        154 => 'SYNTHAUTO DANKAL 51 CARR',                 // SOC13
        156 => 'SYNTHAUTO NEMTRI 54 APV',                  // SOC14
        161 => 'SYNTHAUTO TRIMES 57 VN',                   // SOC15
        162 => 'SYNTHAUTO FERDTEG 67 VO',                  // SOC16
        181 => 'SYNTHAUTO KURBRIX 68 CARR',                // SOC17
        182 => 'SYNTHAUTO PLUJUV 51 APV',                  // SOC18
        183 => 'SYNTHAUTO PELLIM 54 VN',                   // SOC01
        191 => 'SYNTHAUTO SABPEL 57 VO',                   // SOC02
        201 => 'SYNTHAUTO DANLIM 67 CARR',                 // SOC03
        202 => 'SYNTHAUTO LIMCAV 68 APV',                  // SOC04
        203 => 'SYNTHAUTO SOLCAV 51 VN',                   // SOC05
        204 => 'SYNTHAUTO BRIXNEM 54 VO',                  // SOC06
        205 => 'SYNTHAUTO CAVVAL 57 CARR',                 // SOC07
        206 => 'SYNTHAUTO OSKKAL 67 APV',                  // SOC08
        207 => 'SYNTHAUTO SABRIN 68 VN',                   // SOC09
        211 => 'SYNTHAUTO FERDDAN 51 VO',                  // SOC10
        221 => 'SYNTHAUTO FERDWEN 54 CARR',                // SOC11
        222 => 'SYNTHAUTO WENPEL 57 APV',                  // SOC12
        223 => 'SYNTHAUTO KURPLU 67 VN',                   // SOC13
        241 => 'SYNTHAUTO BRYTAL 68 VO',                   // SOC14
        243 => 'SYNTHAUTO BRIXRYN 51 CARR',                // SOC15
        251 => 'SYNTHAUTO DANNEM 54 APV',                  // SOC16
        291 => 'SYNTHAUTO DOLSAB 57 VN',                   // SOC17
        301 => 'SYNTHAUTO WENFEX 67 VO',                   // SOC18
        310 => 'SYNTHAUTO VORTAL 68 CARR',                 // SOC01
        313 => 'SYNTHAUTO KURVOR 51 APV',                  // SOC02
        320 => 'SYNTHAUTO PELKUR 54 VN',                   // SOC03
        330 => 'SYNTHAUTO FERDCAV 57 VO',                  // SOC04
        341 => 'SYNTHAUTO NEMKAL 67 CARR',                 // SOC05
        342 => 'SYNTHAUTO LIMRIN 68 APV',                  // SOC06
        343 => 'SYNTHAUTO SOLJUV 51 VN',                   // SOC07
        351 => 'SYNTHAUTO BRYLIM 54 VO',                   // SOC08
        352 => 'SYNTHAUTO SABWEN 57 CARR',                 // SOC09
        361 => 'SYNTHAUTO JUVMUR 67 APV',                  // SOC10
        362 => 'SYNTHAUTO TALBRIX 68 VN',                  // SOC11
        363 => 'SYNTHAUTO OSKLIM 51 VO',                   // SOC12
        371 => 'SYNTHAUTO RINCAV 54 CARR',                 // SOC13
        372 => 'SYNTHAUTO DOLLIM 57 APV',                  // SOC14
        373 => 'SYNTHAUTO LIMJUV 67 VN',                   // SOC15
        375 => 'SYNTHAUTO JUVSAB 68 VO',                   // SOC16
        381 => 'SYNTHAUTO MURRYN 51 CARR',                 // SOC17
        382 => 'SYNTHAUTO OSKTAL 54 APV',                  // SOC18
        383 => 'SYNTHAUTO FEXZOR 57 VN',                   // SOC01
        391 => 'SYNTHAUTO VORJUV 67 VO',                   // SOC02
        392 => 'SYNTHAUTO ZORWEN 68 CARR',                 // SOC03
        393 => 'SYNTHAUTO PLUCAV 51 APV',                  // SOC04
        402 => 'SYNTHAUTO BRIXZOR 54 VN',                  // SOC05
        411 => 'SYNTHAUTO VORMES 57 VO',                   // SOC06
        421 => 'SYNTHAUTO KURDAN 67 CARR',                 // SOC07
        431 => 'SYNTHAUTO WENKAL 68 APV',                  // SOC08
        441 => 'SYNTHAUTO SABTAL 51 VN',                   // SOC09
        451 => 'SYNTHAUTO PELRYN 54 VO',                   // SOC10
        461 => 'SYNTHAUTO WENWEN 57 CARR',                 // SOC11
        462 => 'SYNTHAUTO WENBRY 67 APV',                  // SOC12
        471 => 'SYNTHAUTO TALWEN 68 VN',                   // SOC13
        472 => 'SYNTHAUTO SOLPLU 51 VO',                   // SOC14
        473 => 'SYNTHAUTO VALPLU 54 CARR',                 // SOC15
        481 => 'SYNTHAUTO VORCAV 57 APV',                  // SOC16
        482 => 'SYNTHAUTO TALVAL 67 VN',                   // SOC17
        491 => 'SYNTHAUTO BRYBRIX 68 VO',                  // SOC18
        492 => 'SYNTHAUTO KURBRY 51 CARR',                 // SOC01
        493 => 'SYNTHAUTO MESTEG 54 APV',                  // SOC02
        700 => 'SYNTHAUTO GILOSK 57 VN',                   // SOC03
        704 => 'SYNTHAUTO PLUKAL 67 VO',                   // SOC04
        804 => 'SYNTHAUTO GILPLU 68 CARR',                 // SOC05
    ];

    /**
     * Libelle d'un code etablissement, ou null si le code est vide / inconnu du referentiel.
     */
    public static function libelle(?string $codeetab): ?string
    {
        $code = self::normaliserCode($codeetab);

        return null === $code ? null : (self::LIBELLES[$code] ?? null);
    }

    /**
     * Code + libelle pour l'affichage ('093 — SYNTHAUTO ... 57 VO'). Repli sur le seul
     * code quand le libelle est inconnu ('807'), chaine vide si pas de code du tout.
     */
    public static function avecCode(?string $codeetab): string
    {
        $brut = trim((string) $codeetab);
        if ('' === $brut) {
            return '';
        }

        $libelle = self::libelle($brut);

        return null === $libelle ? self::code($brut) : self::code($brut).' — '.$libelle;
    }

    /**
     * Code d'affichage normalise sur 3 chiffres ('93' -> '093'), inchange si non numerique.
     */
    public static function code(?string $codeetab): string
    {
        $brut = trim((string) $codeetab);
        $code = self::normaliserCode($brut);

        return null === $code ? $brut : \sprintf('%03d', $code);
    }

    /**
     * Normalise un code etablissement en entier ('093' -> 93, '371.0' -> 371, '' / null / 'SIE' -> null).
     */
    public static function normaliserCode(?string $codeetab): ?int
    {
        if (null === $codeetab) {
            return null;
        }
        $valeur = trim($codeetab);
        if ('' === $valeur) {
            return null;
        }
        // xlsx : les codes arrivent en flottant ('371.0') -> valeur numerique (371).
        // v_impayes : codes zero-paddes ('093') -> is_numeric aussi -> 93.
        if (is_numeric($valeur)) {
            return (int) (float) $valeur;
        }
        // Repli (code avec parasites) : ne garder que les chiffres.
        $chiffres = preg_replace('/\D/', '', $valeur);

        return null === $chiffres || '' === $chiffres ? null : (int) $chiffres;
    }
}
