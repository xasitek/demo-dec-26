<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Service\NormalisationSaisie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormalisationSaisieTest extends TestCase
{
    #[DataProvider('montants')]
    public function testMontant(string $saisie, ?string $attendu): void
    {
        self::assertSame($attendu, (new NormalisationSaisie())->montant($saisie));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function montants(): iterable
    {
        yield 'espace milliers + virgule + euro' => ['1 234,56 €', '1234.56'];
        yield 'espace insecable milliers' => ["1\u{00A0}234,56", '1234.56'];
        yield 'point milliers + virgule decimale' => ['1.234,56', '1234.56'];
        yield 'virgule milliers + point decimale' => ['1,234.56', '1234.56'];
        yield 'point decimale simple' => ['1234.56', '1234.56'];
        yield 'virgule decimale simple' => ['1234,56', '1234.56'];
        yield 'entier simple' => ['500', '500.00'];
        yield 'entier avec euro colle' => ['500€', '500.00'];
        yield 'un chiffre apres virgule' => ['12,5', '12.50'];
        yield 'point milliers seul (3 chiffres)' => ['1.234', '1234.00'];
        yield 'virgule milliers seule (3 chiffres)' => ['1,234', '1234.00'];
        yield 'gros montant groupe' => ['1 234 567,89', '1234567.89'];
        yield 'virgule finale sans decimale' => ['1234,', '1234.00'];
        yield 'zero' => ['0', '0.00'];
        yield 'vide' => ['', null];
        yield 'texte sans chiffre' => ['abc', null];
    }

    #[DataProvider('immats')]
    public function testImmatriculation(?string $saisie, ?string $attendu): void
    {
        self::assertSame($attendu, (new NormalisationSaisie())->immatriculation($saisie));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function immats(): iterable
    {
        yield 'francaise avec tirets' => ['AB-123-CD', 'AB123CD'];
        yield 'francaise minuscule espaces' => ['ab 123 cd', 'AB123CD'];
        yield 'sans separateur' => ['ab123cd', 'AB123CD'];
        yield 'allemande' => ['M-AB 1234', 'MAB1234'];
        yield 'espaces autour' => ['  AB123CD  ', 'AB123CD'];
        yield 'null' => [null, null];
        yield 'vide (espaces seuls)' => ['   ', null];
    }
}
