<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Twig;

use App\Remboursement\Twig\RemboursementExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les deux fonctions de comparaison de l'ecran de verification : fonctions pures, donc
 * appelees statiquement, sans instancier l'extension ni ses depots.
 */
final class RemboursementExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: bool, 3: bool}>
     */
    public static function casEcart(): iterable
    {
        yield 'immatriculation avec et sans tirets' => ['GD860PJ', 'GD-860-PJ', true, false];
        yield 'IBAN espace ou non' => ['FR76 3000 6109', 'FR7630006109', true, false];
        yield 'casse ignoree sur un nom' => ['le mat emmanuel', 'LE MAT EMMANUEL', false, false];
        yield 'espaces multiples reduits' => ['LE  MAT   EMMANUEL', 'LE MAT EMMANUEL', false, false];
        yield 'BIC a un caractere pres' => ['AGRIFRPP826', 'AGRIFRPP825', true, true];
        yield 'immatriculation vraiment differente' => ['GD-860-PJ', 'GD-861-PJ', true, true];
        yield 'valeur absente a droite' => ['GD860PJ', '', true, false];
        yield 'valeur absente a gauche' => [null, 'GD-860-PJ', true, false];
    }

    #[DataProvider('casEcart')]
    public function testEcartIgnoreLaMiseEnForme(?string $a, ?string $b, bool $identifiant, bool $attendu): void
    {
        self::assertSame($attendu, RemboursementExtension::ecart($a, $b, $identifiant));
    }

    public function testDiffSurligneLeSeulCaractereQuiChange(): void
    {
        $rendu = RemboursementExtension::diff('AGRIFRPP825', 'AGRIFRPP826', true);

        self::assertStringStartsWith('AGRIFRPP82', $rendu);
        self::assertStringContainsString('>5</span>', $rendu);
        self::assertStringNotContainsString('>AGRIFRPP825</span>', $rendu);
    }

    public function testDiffSurligneLeBlocCentralQuiChange(): void
    {
        $rendu = RemboursementExtension::diff('GD-861-PJ', 'GD-860-PJ', true);

        self::assertStringContainsString('>1</span>', $rendu);
        self::assertStringStartsWith('GD-86', $rendu);
        self::assertStringEndsWith('-PJ', $rendu);
    }

    public function testDiffNeSurligneRienQuandSeuleLaMiseEnFormeChange(): void
    {
        self::assertSame('GD-860-PJ', RemboursementExtension::diff('GD-860-PJ', 'GD860PJ', true));
        self::assertSame('LE MAT EMMANUEL', RemboursementExtension::diff('LE MAT EMMANUEL', 'le mat  emmanuel', false));
    }

    public function testDiffSurligneToutQuandToutDiffere(): void
    {
        $rendu = RemboursementExtension::diff('CA AUTO BANK', 'LE MAT EMMANUEL', false);

        self::assertStringContainsString('>CA AUTO BANK</span>', $rendu);
    }

    /** La sortie est marquee « safe » cote Twig : c'est ici que l'echappement doit avoir lieu. */
    public function testDiffEchappeLeContenu(): void
    {
        $rendu = RemboursementExtension::diff('<script>alert(1)</script>', 'AUTRE', false);

        self::assertStringNotContainsString('<script>', $rendu);
        self::assertStringContainsString('&lt;script&gt;', $rendu);
    }
}
