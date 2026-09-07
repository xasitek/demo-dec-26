<?php

declare(strict_types=1);

namespace App\Tests\Shared\Entity;

use App\Shared\Entity\User;
use LogicException;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testGetRolesAjouteToujoursRoleUser(): void
    {
        $user = new User();
        $user->setRoles([]);

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesNeDupliquePasRoleUser(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_COMPTABLE', 'ROLE_USER']);

        $roles = $user->getRoles();
        self::assertContains('ROLE_COMPTABLE', $roles);
        self::assertSame(array_unique($roles), $roles);
    }

    public function testIsHabilite(): void
    {
        $user = new User();

        $user->setRoles([]);
        self::assertFalse($user->isHabilite());

        $user->setRoles(['ROLE_COMPTABLE']);
        self::assertTrue($user->isHabilite());
    }

    public function testGetUserIdentifierRetourneEmail(): void
    {
        $user = new User();
        $user->setEmail('jean.martin@demonstration.invalid');

        self::assertSame('jean.martin@demonstration.invalid', $user->getUserIdentifier());
    }

    public function testGetUserIdentifierRefuseUnEmailVide(): void
    {
        $user = new User();
        $user->setEmail('');

        $this->expectException(LogicException::class);
        $user->getUserIdentifier();
    }

    public function testGetFullName(): void
    {
        $user = new User();
        $user->setFirstName('Velnau')->setLastName('Dubois');

        self::assertSame('Velnau Dubois', $user->getFullName());
    }
}
