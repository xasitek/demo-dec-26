<?php

declare(strict_types=1);

namespace App\Lettrage\Moteur;

use DateTimeImmutable;

/**
 * Les tolerances d'ecart, et la regle d'equilibre.
 *
 * Le principe metier qui porte tout l'outil : le meme ecart n'a pas le meme
 * sens selon l'anciennete de la creance. Douze euros sur une facture de vingt
 * jours sont une erreur qu'il faut comprendre -- un escompte non prevu, un
 * frais bancaire, une saisie fausse. Les memes douze euros sur une creance de
 * quatorze mois sont un residu qu'il serait plus couteux d'instruire que
 * d'apurer.
 *
 * Les bornes sont celles du moteur d'origine, reprises telles quelles.
 */
final class Tolerances
{
    /**
     * Bareme un pour un, par anciennete de la ligne la plus ancienne.
     *
     * @var list<array{jours: int|null, euros: float, libelle: string}>
     */
    public const AGE_1V1 = [
        ['jours' => 60, 'euros' => 5.0, 'libelle' => 'moins de 2 mois'],
        ['jours' => 90, 'euros' => 10.0, 'libelle' => '2 à 3 mois'],
        ['jours' => 180, 'euros' => 25.0, 'libelle' => '3 à 6 mois'],
        ['jours' => 365, 'euros' => 50.0, 'libelle' => '6 à 12 mois'],
        ['jours' => 730, 'euros' => 100.0, 'libelle' => '1 à 2 ans'],
        ['jours' => null, 'euros' => 500.0, 'libelle' => 'plus de 2 ans'],
    ];

    /**
     * Bareme des groupes, plus large : un groupe porte plus de matiere, donc
     * plus d'arrondis.
     *
     * @var list<array{jours: int|null, euros: float, libelle: string}>
     */
    public const AGE_NVN = [
        ['jours' => 60, 'euros' => 10.0, 'libelle' => 'moins de 2 mois'],
        ['jours' => 90, 'euros' => 25.0, 'libelle' => '2 à 3 mois'],
        ['jours' => 180, 'euros' => 50.0, 'libelle' => '3 à 6 mois'],
        ['jours' => 365, 'euros' => 100.0, 'libelle' => '6 à 12 mois'],
        ['jours' => 730, 'euros' => 200.0, 'libelle' => '1 à 2 ans'],
        ['jours' => null, 'euros' => 500.0, 'libelle' => 'plus de 2 ans'],
    ];

    /** Bareme progressif d'apurement d'un residu. */
    public const APUREMENT = [
        ['jours' => 90, 'euros' => 20.0],
        ['jours' => 180, 'euros' => 30.0],
        ['jours' => 365, 'euros' => 50.0],
        ['jours' => 730, 'euros' => 200.0],
        ['jours' => null, 'euros' => 500.0],
    ];

    /** Plafonds absolus, en euros et en proportion. */
    public const PLAFOND_1V1_EURO = 500.0;
    public const PLAFOND_1V1_RATIO = 0.10;
    public const PLAFOND_NVN_EURO = 1000.0;
    public const PLAFOND_NVN_RATIO = 0.15;

    /** Ecart de garantie au-dela duquel ce n'est plus un lettrage mais une anomalie. */
    public const GARANTIE_ANOMALIE = 500.0;

    /** Solde net accepte sur une position entre societes ou etablissements. */
    public const LIAISON = 5.0;

    /** Bornes de la recherche combinatoire. */
    public const COMBI_MAX_LIGNES = 10;
    public const COMBI_MAX_ITERATIONS = 20000;
    public const COMBI_BUDGET_MS = 150;

    /** Date de reference de la demonstration : le monde s'arrete la. */
    public const AUJOURD_HUI = '2026-09-01';

    /**
     * La tolerance applicable, et le palier qui la justifie.
     *
     * @param list<string> $dates dates des lignes du groupe, au format ISO
     *
     * @return array{euros: float, jours: int, palier: string}
     */
    public static function pour(array $dates, bool $groupe = false): array
    {
        $jours = self::anciennete($dates);
        $bareme = $groupe ? self::AGE_NVN : self::AGE_1V1;
        foreach ($bareme as $palier) {
            if (null === $palier['jours'] || $jours < $palier['jours']) {
                return ['euros' => $palier['euros'], 'jours' => $jours, 'palier' => $palier['libelle']];
            }
        }

        return ['euros' => 500.0, 'jours' => $jours, 'palier' => 'plus de 2 ans'];
    }

    /**
     * Anciennete en jours de la ligne la plus ancienne du groupe.
     *
     * @param array<array-key, mixed> $dates dates au format ISO
     */
    public static function anciennete(array $dates): int
    {
        if ([] === $dates) {
            return 0;
        }
        $plusAncienne = min($dates);
        $ref = new DateTimeImmutable(self::AUJOURD_HUI);
        $d = new DateTimeImmutable((string) $plusAncienne);

        return max(0, (int) $ref->diff($d)->days * ($d < $ref ? 1 : -1));
    }

    /**
     * Depuis combien de jours le compte ne bouge plus.
     *
     * La nuance est decisive et elle a coute deux lettrages faux. Pour apurer
     * un solde residuel, ce qui compte n'est pas l'age de la plus ancienne
     * ecriture du compte, c'est le temps ecoule depuis la DERNIERE. Un compte
     * qui a bougé il y a vingt jours est vivant : un ecart de douze euros y est
     * une anomalie qu'il faut comprendre. Le meme ecart sur un compte dormant
     * depuis quatorze mois est un residu qu'il coute plus cher d'instruire que
     * d'apurer.
     *
     * Prendre l'ecriture la plus ancienne donnait au compte vivant la tolerance
     * large de ses vieilles lignes, et le moteur apurait ce qu'il devait
     * signaler.
     *
     * @param array<array-key, mixed> $dates
     *
     * @return array{euros: float, jours: int, palier: string}
     */
    public static function dormance(array $dates, bool $groupe = false): array
    {
        if ([] === $dates) {
            return ['euros' => 5.0, 'jours' => 0, 'palier' => 'moins de 2 mois'];
        }
        $recente = max($dates);
        $ref = new DateTimeImmutable(self::AUJOURD_HUI);
        $d = new DateTimeImmutable((string) $recente);
        $jours = max(0, (int) $ref->diff($d)->days * ($d < $ref ? 1 : -1));

        foreach ($groupe ? self::AGE_NVN : self::AGE_1V1 as $palier) {
            if (null === $palier['jours'] || $jours < $palier['jours']) {
                return ['euros' => $palier['euros'], 'jours' => $jours, 'palier' => $palier['libelle']];
            }
        }

        return ['euros' => 500.0, 'jours' => $jours, 'palier' => 'plus de 2 ans'];
    }

    /**
     * La regle d'equilibre : les DEUX plafonds doivent tenir.
     *
     * En euros seulement, un ecart de quatre cents euros passerait sur une
     * facture de cinq cents. En proportion seulement, dix pour cent d'un
     * million passeraient. C'est la conjonction qui protege.
     */
    public static function equilibre(float $debit, float $credit, float $toleranceEuros, bool $groupe = false): bool
    {
        $ecart = abs(round(($debit - $credit) * 100) / 100);
        if ($ecart < 0.01) {
            return true;
        }
        $max = max($debit, $credit);
        if ($max <= 0.0) {
            return false;
        }
        $plafondEuro = min($toleranceEuros, $groupe ? self::PLAFOND_NVN_EURO : self::PLAFOND_1V1_EURO);
        $plafondRatio = $groupe ? self::PLAFOND_NVN_RATIO : self::PLAFOND_1V1_RATIO;

        return $ecart <= $plafondEuro && $ecart / $max <= $plafondRatio;
    }
}
