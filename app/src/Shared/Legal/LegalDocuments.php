<?php

declare(strict_types=1);

namespace App\Shared\Legal;

/**
 * Reference centrale des documents legaux et de leur version courante. Bumper la
 * version d'un document fait reapparaitre la modale de consentement pour tous.
 * Voir docs/SECURITY.md.
 */
final class LegalDocuments
{
    public const CGU = 'cgu';
    public const CONFIDENTIALITE = 'confidentialite';

    /**
     * Version courante de chaque document (date de derniere mise a jour).
     *
     * @var array<string, string>
     */
    public const VERSIONS = [
        self::CGU => '2026-06-19',
        self::CONFIDENTIALITE => '2026-06-19',
    ];

    /**
     * Documents dont l'acceptation / prise de connaissance est requise.
     *
     * @return list<string>
     */
    public static function requis(): array
    {
        return array_keys(self::VERSIONS);
    }
}
