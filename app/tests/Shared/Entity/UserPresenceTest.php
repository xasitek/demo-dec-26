<?php

declare(strict_types=1);

namespace App\Tests\Shared\Entity;

use App\Shared\Entity\User;
use App\Shared\Entity\UserPresence;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class UserPresenceTest extends TestCase
{
    public function testNouvellePresenceEstEnLigne(): void
    {
        $presence = new UserPresence(new User());

        self::assertSame('online', $presence->statut());
    }

    public function testStatutInactifQuandPasDActiviteRecente(): void
    {
        $presence = new UserPresence(new User());
        $this->ecraser($presence, 'lastActivityAt', new DateTimeImmutable('-10 minutes'));

        self::assertSame('idle', $presence->statut());
    }

    public function testStatutHorsLigneQuandPlusDePing(): void
    {
        $presence = new UserPresence(new User());
        $this->ecraser($presence, 'lastPingAt', new DateTimeImmutable('-5 minutes'));

        self::assertSame('offline', $presence->statut());
    }

    public function testTouchActifMetAJourLActivite(): void
    {
        $presence = new UserPresence(new User());
        $this->ecraser($presence, 'lastActivityAt', new DateTimeImmutable('-10 minutes'));

        $presence->touch(true);

        self::assertSame('online', $presence->statut());
    }

    private function ecraser(object $objet, string $propriete, mixed $valeur): void
    {
        $ref = new ReflectionProperty($objet, $propriete);
        $ref->setValue($objet, $valeur);
    }
}
