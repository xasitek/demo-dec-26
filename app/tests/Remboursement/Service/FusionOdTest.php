<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Service\FusionOd;
use PHPUnit\Framework\TestCase;

final class FusionOdTest extends TestCase
{
    public function testFusionneEnteteUneFoisPuisToutesLesLignes(): void
    {
        $a = "SOCIETE;compte;montant\nSYNTH;4111;100";
        $b = "SOCIETE;compte;montant\nSYNTH;5120;100\nSYNTH;4111;50";

        self::assertSame(
            "SOCIETE;compte;montant\nSYNTH;4111;100\nSYNTH;5120;100\nSYNTH;4111;50\n",
            FusionOd::fusionner([$a, $b]),
        );
    }

    public function testIgnoreLesContenusVides(): void
    {
        self::assertSame('', FusionOd::fusionner([]));
        self::assertSame('', FusionOd::fusionner(['', '   ']));
    }
}
