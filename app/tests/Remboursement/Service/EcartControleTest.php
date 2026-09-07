<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Service\EcartControle;
use PHPUnit\Framework\TestCase;

final class EcartControleTest extends TestCase
{
    public function testPasDEcartQuandValeursIdentiquesMalgreFormat(): void
    {
        // Espaces / casse sur l'IBAN, virgule vs point + zeros sur le montant : pas un ecart.
        self::assertFalse(EcartControle::ibanDiverge('FR76 3000 4000 0100 0001 2345 678', 'fr7630004000010000012345678', null));
        self::assertFalse(EcartControle::montantDiverge('1000.00', '1000,00', null));
        self::assertFalse(EcartControle::montantDiverge('1000', null, '1000.00'));
    }

    public function testEcartQuandUneEtapeModifie(): void
    {
        // La validation comptable change l'IBAN / le montant : ecart signale.
        self::assertTrue(EcartControle::ibanDiverge('FR7630004000010000012345678', 'FR7630004000010000012345678', 'BE68539007547034'));
        self::assertTrue(EcartControle::montantDiverge('1000.00', '1000.00', '950.00'));
    }

    public function testPasDEcartQuandUneSeuleEtapeRenseignee(): void
    {
        self::assertFalse(EcartControle::ibanDiverge('FR7630004000010000012345678', null, null));
        self::assertFalse(EcartControle::montantDiverge(null, null, null));
    }
}
