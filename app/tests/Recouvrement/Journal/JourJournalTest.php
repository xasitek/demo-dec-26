<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Journal;

use App\Recouvrement\Journal\JourJournal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class JourJournalTest extends TestCase
{
    /** "Aujourd'hui" de référence pour tous les tests (avec une heure, pour vérifier le recadrage à minuit). */
    private const AUJOURDHUI = '2026-07-10 15:30:00';

    private function aujourdhui(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AUJOURDHUI);
    }

    public function testDefautEstAujourdhuiRecadreAMinuit(): void
    {
        $jour = JourJournal::depuis('', $this->aujourdhui());

        self::assertTrue($jour->estAujourdhui());
        self::assertSame('2026-07-10', $jour->iso());
        self::assertSame('2026-07-10 00:00:00', $jour->debut()->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-11 00:00:00', $jour->fin()->format('Y-m-d H:i:s'));
        // Pas de jour suivant : on ne relance pas dans le futur.
        self::assertNull($jour->suivantIso());
        self::assertSame('2026-07-09', $jour->precedentIso());
    }

    public function testJourPasse(): void
    {
        $jour = JourJournal::depuis('2026-07-08', $this->aujourdhui());

        self::assertSame('2026-07-08', $jour->iso());
        self::assertFalse($jour->estAujourdhui());
        self::assertFalse($jour->estHier());
        self::assertSame('2026-07-07', $jour->precedentIso());
        self::assertSame('2026-07-09', $jour->suivantIso());
    }

    public function testHier(): void
    {
        $jour = JourJournal::depuis('2026-07-09', $this->aujourdhui());

        self::assertTrue($jour->estHier());
        self::assertFalse($jour->estAujourdhui());
        self::assertSame('2026-07-10', $jour->suivantIso());
    }

    public function testFuturEstBorneAAujourdhui(): void
    {
        $jour = JourJournal::depuis('2026-12-25', $this->aujourdhui());

        self::assertTrue($jour->estAujourdhui());
        self::assertSame('2026-07-10', $jour->iso());
        self::assertNull($jour->suivantIso());
    }

    public function testFormatInvalideRetombeSurAujourdhui(): void
    {
        foreach (['nawak', '10/07/2026', '2026-7-1', '', '2026-07'] as $mauvais) {
            $jour = JourJournal::depuis($mauvais, $this->aujourdhui());
            self::assertSame('2026-07-10', $jour->iso(), sprintf('Entrée "%s" devrait retomber sur aujourd\'hui', $mauvais));
        }
    }

    public function testDateImpossibleRetombeSurAujourdhui(): void
    {
        // Bon format mais date inexistante : createFromFormat déborde, on doit rejeter.
        $jour = JourJournal::depuis('2026-13-40', $this->aujourdhui());

        self::assertSame('2026-07-10', $jour->iso());
    }

    public function testLibelleFrancais(): void
    {
        $jour = JourJournal::depuis('2026-07-10', $this->aujourdhui());
        self::assertSame('vendredi 10 juillet 2026', $jour->libelle());
    }

    public function testLibelleAvecAccent(): void
    {
        // Date passée (avant le 10/07/2026) portant un accent dans le mois.
        $jour = JourJournal::depuis('2026-02-01', $this->aujourdhui());
        self::assertSame('dimanche 1 février 2026', $jour->libelle());
    }
}
