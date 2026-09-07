<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Service;

use App\Recouvrement\Service\SepaQrCode;
use PHPUnit\Framework\TestCase;

final class SepaQrCodeTest extends TestCase
{
    public function testPayloadRespecteLaNormeEpc(): void
    {
        $payload = SepaQrCode::payload(
            'FR76 3000 6000 0112 3456 7890 189',
            'BNPAFRPPXXX',
            'GROUPE SYNTHAUTO OSKNEM',
            '2234.00',
            'Ref C-ALPHABET',
        );

        self::assertSame(
            implode("\n", [
                'BCD',
                '002',
                '1',
                'SCT',
                'BNPAFRPPXXX',
                'GROUPE SYNTHAUTO OSKNEM',
                'FR7630006000011234567890189',
                'EUR2234.00',
                '',
                '',
                'Ref C-ALPHABET',
            ]),
            $payload,
        );
    }

    public function testMontantAvecVirguleEtEspacesNormalise(): void
    {
        $payload = SepaQrCode::payload('FR7630006000011234567890189', 'BNPAFRPPXXX', 'X', '1 234,56', 'r');
        self::assertNotNull($payload);
        self::assertStringContainsString("\nEUR1234.56\n", $payload);
    }

    public function testSansIbanRenvoieNull(): void
    {
        self::assertNull(SepaQrCode::payload('', 'BNPAFRPPXXX', 'X', '100.00', 'r'));
        self::assertNull(SepaQrCode::payload(null, 'BNPAFRPPXXX', 'X', '100.00', 'r'));
    }

    public function testMontantNulOuNegatifRenvoieNull(): void
    {
        self::assertNull(SepaQrCode::payload('FR7630006000011234567890189', '', 'X', '0', 'r'));
        self::assertNull(SepaQrCode::payload('FR7630006000011234567890189', '', 'X', '-50.00', 'r'));
    }

    public function testNomBeneficiaireTronqueA70(): void
    {
        $nom = str_repeat('A', 90);
        $payload = SepaQrCode::payload('FR7630006000011234567890189', 'BNPAFRPPXXX', $nom, '10.00', 'r');
        self::assertNotNull($payload);
        $lignes = explode("\n", $payload);
        // Ligne 6 (index 5) = nom du beneficiaire.
        self::assertSame(str_repeat('A', 70), $lignes[5]);
    }
}
