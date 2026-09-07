<?php

declare(strict_types=1);

namespace App\Affectation\Moteur;

/**
 * Normalisation des cles metier.
 *
 * Portage fidele des helpers du moteur d'identification developpe sur la
 * mission (references SRC-O4-N01 a N05). Les mots vides, la troncature du
 * numero de serie a ses huit derniers caracteres, la similarite de Jaro-Winkler
 * et le nettoyage des references sont reproduits a l'identique : ce sont eux
 * qui font qu'un libelle bancaire devient comparable a une donnee comptable.
 */
final class Normalisation
{
    /**
     * Mots vides des libelles bancaires. Ils ne portent aucune information sur
     * l'identite du payeur, et les laisser fausse toute comparaison de nom.
     */
    public const MOTS_VIDES = [
        'VIR', 'SAS', 'SARL', 'SA', 'SCI', 'EURL', 'ETS', 'CHQ', 'M.', 'MR', 'MME',
        'MLLE', 'GARAGE', 'CARROSSERIE', 'AUTOMOBILE', 'AUTO', 'AUTOMOBILES', 'STE',
        'SOCIETE', 'REGLEMENT', 'COMPTANT', 'TIERS', 'AVOIR', 'FACTURE', 'FACT',
        'FAC', 'AV', 'VIRT', 'TRF', 'TSFT', 'ATT', 'REF', 'ORIGINE', 'REGLT',
        'SEPA', 'CT', 'RECU', 'VIREMENT',
    ];

    /** Longueur minimale d'une reference pour etre exploitable. */
    public const REF_LONGUEUR_MIN = 4;

    /** Similarite de nom en dessous de laquelle on ne conclut rien. */
    public const NOM_SIMILARITE_MIN = 0.70;

    public static function reference(?string $r): string
    {
        if (null === $r || '' === $r) {
            return '';
        }
        $s = preg_replace('/[\s\-\/\\\\.]+/', '', $r) ?? '';

        return strtoupper(preg_replace('/\.0$/', '', $s) ?? '');
    }

    public static function immatriculation(?string $i): string
    {
        if (null === $i || '' === $i) {
            return '';
        }

        return strtoupper(preg_replace('/[\-\s.]+/', '', $i) ?? '');
    }

    /** Le nom, debarrasse de ses mots vides et de sa ponctuation. */
    public static function nom(?string $n): string
    {
        if (null === $n || '' === $n) {
            return '';
        }
        $s = strtoupper($n);
        $s = preg_replace('/\/\d*$/', '', $s) ?? $s;
        $motif = '/\b(?:'.implode('|', array_map('preg_quote', self::MOTS_VIDES)).')\b/';
        $s = preg_replace($motif, '', $s) ?? $s;

        return preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
    }

    /** Les huit derniers caracteres du numero de serie : la partie discriminante. */
    public static function serie8(?string $v): string
    {
        $brut = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $v)) ?? '';

        return \strlen($brut) >= 8 ? substr($brut, -8) : '';
    }

    /**
     * Similarite de Jaro-Winkler, portage fidele.
     *
     * Elle est employee sur les noms, jamais sur les montants ni sur les
     * references : une reference approchante n'est pas une reference.
     */
    public static function jaroWinkler(string $s1, string $s2): float
    {
        if ($s1 === $s2) {
            return 1.0;
        }
        if ('' === $s1 || '' === $s2) {
            return 0.0;
        }
        $l1 = \strlen($s1);
        $l2 = \strlen($s2);
        $mx = max(0, intdiv(max($l1, $l2), 2) - 1);
        $m1 = array_fill(0, $l1, false);
        $m2 = array_fill(0, $l2, false);
        $correspondances = 0;

        for ($i = 0; $i < $l1; ++$i) {
            $lo = max(0, $i - $mx);
            $hi = min($i + $mx + 1, $l2);
            for ($j = $lo; $j < $hi; ++$j) {
                if ($m2[$j] || $s1[$i] !== $s2[$j]) {
                    continue;
                }
                $m1[$i] = $m2[$j] = true;
                ++$correspondances;
                break;
            }
        }
        if (0 === $correspondances) {
            return 0.0;
        }
        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $l1; ++$i) {
            if (!$m1[$i]) {
                continue;
            }
            while (!$m2[$k]) {
                ++$k;
            }
            if ($s1[$i] !== $s2[$k]) {
                ++$transpositions;
            }
            ++$k;
        }
        $jaro = ($correspondances / $l1 + $correspondances / $l2
            + ($correspondances - $transpositions / 2) / $correspondances) / 3;

        $prefixe = 0;
        for ($p = 0; $p < min(4, min($l1, $l2)); ++$p) {
            if ($s1[$p] === $s2[$p]) {
                ++$prefixe;
            } else {
                break;
            }
        }

        return $jaro + $prefixe * 0.1 * (1 - $jaro);
    }

    /**
     * Les fragments de numero de serie cites dans un texte.
     *
     * Ils ne peuvent PAS etre tires de la liste des references : celle-ci
     * ecarte les jetons purement alphabetiques, ce qui est juste pour une
     * reference de facture -- un mot comme VIREMENT n'en est pas une -- et faux
     * pour un numero de serie. L'alphabet ISO 3779 exclut I, O et Q, si bien
     * qu'un fragment de huit caracteres peut n'etre compose que de lettres.
     *
     * Le defaut a coute deux affectations automatiques fausses : un numero de
     * serie appartenant a un autre compte client etait cite au libelle, et le
     * moteur ne le voyait pas parce qu'il ne portait aucun chiffre.
     *
     * @return list<string>
     */
    public static function seriesDansTexte(string $texte): array
    {
        preg_match_all('/\b[ABCDEFGHJKLMNPRSTUVWXYZ0-9]{8}\b/', strtoupper($texte), $trouve);

        return array_values(array_unique(array_filter(
            $trouve[0],
            static fn (string $s): bool => !\in_array($s, self::MOTS_VIDES, true)
        )));
    }

    /**
     * Les references candidates contenues dans un texte libre.
     *
     * Ancrage anti-faux-positif repris du registre de cles : une sequence n'est
     * retenue que si elle est assez longue pour etre distinctive.
     *
     * @return list<string>
     */
    public static function referencesDansTexte(string $texte): array
    {
        preg_match_all('/\b([A-Z0-9]{5,20})\b/', strtoupper($texte), $trouve);

        return array_values(array_unique(array_filter(
            $trouve[1],
            static fn (string $r): bool => \strlen($r) >= self::REF_LONGUEUR_MIN && !ctype_alpha($r)
        )));
    }

    /** Masque un IBAN : on ne montre jamais un compte en clair. */
    public static function masquerIban(string $iban): string
    {
        $s = preg_replace('/\s+/', '', $iban) ?? $iban;

        return \strlen($s) > 8
            ? substr($s, 0, 4).' •••• •••• '.substr($s, -4)
            : '••••';
    }
}
