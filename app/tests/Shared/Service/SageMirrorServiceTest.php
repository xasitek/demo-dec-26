<?php

declare(strict_types=1);

namespace App\Tests\Shared\Service;

use App\Shared\Service\SageMirrorService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Couvre la construction de la cle mirror (composerCle), coeur du correctif
 * 2026-06-19 : "clé écriture" seule n'etant pas unique dans bal_eloficash, la
 * cle devient composite ("clé écriture" + "oidech").
 */
final class SageMirrorServiceTest extends TestCase
{
    private const SEPARATEUR = "\x1f";

    /**
     * Instancie le service sans passer par le constructeur (qui exige des
     * connexions Doctrine) : on ne teste que la logique pure de composerCle.
     *
     * @param array<string, mixed> $ligne
     * @param list<string>         $colonnes
     */
    private function composerCle(array $ligne, array $colonnes): string
    {
        $reflexion = new ReflectionClass(SageMirrorService::class);
        $service = $reflexion->newInstanceWithoutConstructor();
        $methode = $reflexion->getMethod('composerCle');
        $methode->setAccessible(true);

        /** @var string $cle */
        $cle = $methode->invoke($service, $ligne, $colonnes);

        return $cle;
    }

    public function testCleMonoColonneRendLaValeurTelleQuelle(): void
    {
        $cle = $this->composerCle(['numero' => '4242'], ['numero']);

        self::assertSame('4242', $cle);
    }

    public function testCleCompositeConcateneAvecLeSeparateur(): void
    {
        $cle = $this->composerCle(
            ['clé écriture' => '100', 'oidech' => '987'],
            ['clé écriture', 'oidech'],
        );

        self::assertSame('100'.self::SEPARATEUR.'987', $cle);
        // Une cle composite contient toujours le separateur : c'est ce qui
        // permet la purge des anciennes lignes mono-cle (position(chr(31)...)).
        self::assertStringContainsString(self::SEPARATEUR, $cle);
    }

    public function testMemeCleEcritureAvecOidechDifferentDonneDeuxClesDistinctes(): void
    {
        // Le coeur du correctif : sans oidech, ces deux lignes s'ecrasaient.
        $a = $this->composerCle(['clé écriture' => '100', 'oidech' => '1'], ['clé écriture', 'oidech']);
        $b = $this->composerCle(['clé écriture' => '100', 'oidech' => '2'], ['clé écriture', 'oidech']);

        self::assertNotSame($a, $b);
    }

    public function testLigneSansAucuneValeurDeCleEstIgnoree(): void
    {
        $cle = $this->composerCle(['clé écriture' => '', 'oidech' => null], ['clé écriture', 'oidech']);

        self::assertSame('', $cle);
    }

    public function testPartieManquanteNeFusionnePasAvecUneAutreCle(): void
    {
        // "100" + oidech absent ne doit pas devenir egal a la cle mono "100",
        // sinon collision avec une eventuelle ligne d'une autre table.
        $cleIncomplete = $this->composerCle(['clé écriture' => '100'], ['clé écriture', 'oidech']);

        self::assertSame('100'.self::SEPARATEUR, $cleIncomplete);
        self::assertNotSame('100', $cleIncomplete);
    }
}
