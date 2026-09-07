<?php

declare(strict_types=1);

namespace App\Tests\Shared\Security;

use App\Shared\Security\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testFiltrerNeGardeQueLesRolesMetierPourUnNonSuperAdmin(): void
    {
        $resultat = Roles::filtrer(['ROLE_COMPTABLE', 'ROLE_ADMIN', 'ROLE_BIDON'], false);

        self::assertSame(['ROLE_COMPTABLE'], $resultat);
    }

    public function testFiltrerAutoriseLesRolesElevesPourUnSuperAdmin(): void
    {
        $resultat = Roles::filtrer(['ROLE_COMPTABLE', 'ROLE_ADMIN'], true);

        self::assertContains('ROLE_ADMIN', $resultat);
        self::assertContains('ROLE_COMPTABLE', $resultat);
    }

    public function testFiltrerIgnoreLesRolesInconnus(): void
    {
        self::assertSame([], Roles::filtrer(['ROLE_INCONNU'], true));
    }

    public function testARoleEleve(): void
    {
        self::assertTrue(Roles::aRoleEleve(['ROLE_COMPTABLE', 'ROLE_ADMIN']));
        self::assertFalse(Roles::aRoleEleve(['ROLE_COMPTABLE', 'ROLE_USER']));
    }

    public function testLabelConnuEtInconnu(): void
    {
        self::assertSame('Comptable', Roles::label('ROLE_COMPTABLE'));
        self::assertSame('Super administrateur', Roles::label('ROLE_SUPER_ADMIN'));
        self::assertSame('ROLE_INCONNU', Roles::label('ROLE_INCONNU'));
    }

    public function testAttribuablesSelonPrivilege(): void
    {
        self::assertArrayNotHasKey('ROLE_ADMIN', Roles::attribuables(false));
        self::assertArrayHasKey('ROLE_ADMIN', Roles::attribuables(true));
        self::assertArrayHasKey('ROLE_COMPTABLE', Roles::attribuables(false));
    }
}
