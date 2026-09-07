<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Regle;

use App\Recouvrement\Regle\CadenceRegle;
use PHPUnit\Framework\TestCase;

final class CadenceRegleTest extends TestCase
{
    public function testVnPremiereRelanceA30jPuisTous15j(): void
    {
        // VN : 30j initial, 15j d'intervalle, MED 90j.
        $c = new CadenceRegle(30, 15, 90, true);

        self::assertSame(0, $c->niveauPourRetard(29), 'pas de relance avant 30j');
        self::assertSame(1, $c->niveauPourRetard(30));
        self::assertSame(1, $c->niveauPourRetard(44));
        self::assertSame(2, $c->niveauPourRetard(45));
        self::assertSame(3, $c->niveauPourRetard(60));
        self::assertSame(5, $c->niveauPourRetard(90));
        self::assertSame(6, $c->niveauPourRetard(105));
    }

    public function testApvPremiereRelanceA15j(): void
    {
        // APV : 15j initial, 15j d'intervalle, MED 90j.
        $c = new CadenceRegle(15, 15, 90, true);

        self::assertSame(0, $c->niveauPourRetard(14));
        self::assertSame(1, $c->niveauPourRetard(15));
        self::assertSame(6, $c->niveauPourRetard(90));
    }

    public function testMiseEnDemeureA90j(): void
    {
        $c = new CadenceRegle(30, 15, 90, true);

        self::assertSame(5, $c->niveauMiseEnDemeure());
        self::assertFalse($c->estMiseEnDemeure(4));
        self::assertTrue($c->estMiseEnDemeure(5));
        self::assertTrue($c->estMiseEnDemeure(9));
    }

    public function testArretApresMiseEnDemeurePlafonneLeNiveau(): void
    {
        // continuerApresMed = false : on ne monte pas au-delà du niveau de MED.
        $c = new CadenceRegle(30, 15, 90, false);

        self::assertSame(5, $c->niveauPourRetard(90));
        self::assertSame(5, $c->niveauPourRetard(200), 'plafonné au niveau de mise en demeure');
    }

    public function testContinuationApresMiseEnDemeure(): void
    {
        $c = new CadenceRegle(30, 15, 90, true);

        self::assertSame(12, $c->niveauPourRetard(195), 'on continue tous les 15j au-delà de la MED');
    }

    public function testIntervalleZeroNeCassePas(): void
    {
        // Règle mal saisie (intervalle 0) : plancher à 1 jour, pas de division par zéro.
        $c = new CadenceRegle(30, 0, 90, true);

        self::assertSame(1, $c->niveauPourRetard(30));
        self::assertSame(16, $c->niveauPourRetard(45));
    }

    public function testMiseEnDemeureJamaisAuPremierNiveau(): void
    {
        // Seuil MED <= délai initial (règle mal réglée) : la MED tombe au niveau 2,
        // jamais au tout premier contact (démarrage doux).
        $c = new CadenceRegle(30, 15, 10, true);

        self::assertSame(2, $c->niveauMiseEnDemeure());
        self::assertFalse($c->estMiseEnDemeure(1));
        self::assertTrue($c->estMiseEnDemeure(2));
    }
}
