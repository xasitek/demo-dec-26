<?php

declare(strict_types=1);

namespace App\Affectation\Moteur;

/**
 * Les quatre issues possibles.
 *
 * Le refus et l'exception ne sont pas des echecs : ce sont les deux issues qui
 * protegent la comptabilite. Un moteur qui affecterait tout serait dangereux.
 */
final class Decision
{
    public const AUTOMATIQUE = 'automatique';
    public const VALIDATION = 'validation';
    public const EXCEPTION = 'exception';
    public const REFUS = 'refus';

    public static function libelle(string $d): string
    {
        return match ($d) {
            self::AUTOMATIQUE => 'Affectation automatique',
            self::VALIDATION => 'Proposition à valider',
            self::EXCEPTION => 'Intervention humaine requise',
            self::REFUS => 'Aucune affectation proposée',
            default => $d,
        };
    }

    /** Teinte de badge, dans la palette de la suite. */
    public static function teinte(string $d): string
    {
        return match ($d) {
            self::AUTOMATIQUE => 'paye',
            self::VALIDATION => 'directeur',
            self::EXCEPTION => 'comptable',
            self::REFUS => 'refuse',
            default => 'neutre',
        };
    }

    /** @return list<string> */
    public static function toutes(): array
    {
        return [self::AUTOMATIQUE, self::VALIDATION, self::EXCEPTION, self::REFUS];
    }
}
