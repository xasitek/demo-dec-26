<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Référentiel central des rôles attribuables et de leurs libellés.
 * Voir docs/SECURITY.md.
 */
final class Roles
{
    /**
     * Rôles métier, attribuables par tout administrateur.
     *
     * @var array<string, string>
     */
    public const METIER = [
        'ROLE_SECRETAIRE' => 'Secrétaire',
        'ROLE_COMPTABLE' => 'Comptable',
        'ROLE_AUDITEUR' => 'Auditeur',
        'ROLE_MANAGER' => 'Manager / Direction',
    ];

    /**
     * Rôles élevés, réservés au super administrateur.
     *
     * @var array<string, string>
     */
    public const ELEVES = [
        'ROLE_ADMIN' => 'Administrateur',
        'ROLE_SUPER_ADMIN' => 'Super administrateur',
    ];

    public static function label(string $role): string
    {
        return self::METIER[$role] ?? self::ELEVES[$role] ?? $role;
    }

    /**
     * @return array<string, string>
     */
    public static function attribuables(bool $superAdmin): array
    {
        return $superAdmin ? array_merge(self::METIER, self::ELEVES) : self::METIER;
    }

    /**
     * Ne conserve que les rôles que l'acteur a le droit d'attribuer.
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public static function filtrer(array $roles, bool $superAdmin): array
    {
        $autorises = self::attribuables($superAdmin);

        return array_values(array_unique(array_filter(
            $roles,
            static fn (string $r): bool => \array_key_exists($r, $autorises),
        )));
    }

    /**
     * Indique si un utilisateur possède un rôle élevé (ADMIN/SUPER_ADMIN).
     *
     * @param list<string> $roles
     */
    public static function aRoleEleve(array $roles): bool
    {
        return [] !== array_intersect($roles, array_keys(self::ELEVES));
    }
}
