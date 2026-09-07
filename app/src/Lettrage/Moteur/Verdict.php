<?php

declare(strict_types=1);

namespace App\Lettrage\Moteur;

/**
 * Ce que le moteur de lettrage peut decider d'un lot, et rien d'autre.
 *
 * La doctrine est celle validee pour l'outil 4, transposee au lettrage : la
 * precision prime sur la couverture. Un lot renvoye a l'humain est un bon
 * resultat. Un code de lettrage pose sur un groupe qui ne solde pas est une
 * ecriture fausse, et elle se propage.
 */
final class Verdict
{
    /** Le groupe est forme et lettre sans intervention. */
    public const AUTOMATIQUE = 'automatique';

    /** Le groupe est propose, un comptable tranche. */
    public const PROPOSITION = 'proposition';

    /** Une contradiction ou une ambiguite impose un regard humain. */
    public const HUMAIN = 'humain';

    /** Aucun rapprochement valide : on ne propose rien. */
    public const REFUS = 'refus';

    public static function libelle(string $v): string
    {
        return [
            self::AUTOMATIQUE => 'Lettrage automatique',
            self::PROPOSITION => 'Proposition de lettrage',
            self::HUMAIN => 'Intervention humaine requise',
            self::REFUS => 'Lettrage refusé',
        ][$v] ?? $v;
    }

    /** @return list<string> */
    public static function tous(): array
    {
        return [self::AUTOMATIQUE, self::PROPOSITION, self::HUMAIN, self::REFUS];
    }
}
