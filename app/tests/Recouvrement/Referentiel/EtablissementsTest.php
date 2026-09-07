<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Referentiel;

use App\Recouvrement\Referentiel\Etablissements;
use App\Recouvrement\Service\RibEtablissementProvider;
use PHPUnit\Framework\TestCase;

final class EtablissementsTest extends TestCase
{
    /**
     * Les codes arrivent zero-paddes de v_impayes ('093') et en flottant des xlsx
     * ('371.0') : la normalisation doit ramener les deux au meme entier.
     */
    public function testNormalisationDesCodes(): void
    {
        self::assertSame(93, Etablissements::normaliserCode('093'));
        self::assertSame(93, Etablissements::normaliserCode('93'));
        self::assertSame(371, Etablissements::normaliserCode('371.0'));
        self::assertSame(21, Etablissements::normaliserCode(' 021 '));
        self::assertNull(Etablissements::normaliserCode(''));
        self::assertNull(Etablissements::normaliserCode(null));
        // 'SIE' existe en base (societe CAS) : aucun chiffre, donc aucun code exploitable.
        self::assertNull(Etablissements::normaliserCode('SIE'));
    }

    public function testLibelleTrouveQuelqueSoitLePadding(): void
    {
        self::assertSame('SYNTHAUTO TEGBRY 51 APV', Etablissements::libelle('093'));
        self::assertSame('SYNTHAUTO TEGBRY 51 APV', Etablissements::libelle('93'));
        self::assertSame('SYNTHAUTO RINCAV 54 CARR', Etablissements::libelle('371'));
        self::assertSame('SYNTHAUTO JUVVAL 57 CARR', Etablissements::libelle('104'));
    }

    public function testLibelleNullQuandCodeInconnuOuVide(): void
    {
        // 807 (SPEEDYSYNTH) est present en base mais absent du referentiel metier.
        self::assertNull(Etablissements::libelle('807'));
        self::assertNull(Etablissements::libelle(''));
        self::assertNull(Etablissements::libelle(null));
    }

    public function testAffichageCodeEtLibelle(): void
    {
        self::assertSame('093 — SYNTHAUTO TEGBRY 51 APV', Etablissements::avecCode('093'));
        self::assertSame('021 — SYNTHAUTO GILFEX 51 VN', Etablissements::avecCode('21'));
        // Code inconnu : on affiche le code brut plutot que de masquer l'information.
        self::assertSame('807', Etablissements::avecCode('807'));
        self::assertSame('SIE', Etablissements::avecCode('SIE'));
        self::assertSame('', Etablissements::avecCode(''));
        self::assertSame('', Etablissements::avecCode(null));
    }

    public function testCodeAfficheSurTroisChiffres(): void
    {
        self::assertSame('093', Etablissements::code('93'));
        self::assertSame('371', Etablissements::code('371.0'));
        self::assertSame('SIE', Etablissements::code('SIE'));
        self::assertSame('', Etablissements::code(null));
    }

    /** Un meme site ne doit pas apparaitre sous deux codes (erreur de saisie du referentiel). */
    public function testAucunLibelleEnDoublon(): void
    {
        $libelles = array_map('mb_strtolower', array_values(Etablissements::LIBELLES));

        self::assertSame([], array_keys(array_filter(array_count_values($libelles), static fn (int $n): bool => $n > 1)));
    }

    public function testAucunLibelleVide(): void
    {
        foreach (Etablissements::LIBELLES as $code => $libelle) {
            self::assertNotSame('', trim($libelle), \sprintf('Libelle vide pour le code %d.', $code));
        }
    }

    /** Le provider de RIB doit rester aligne sur la meme regle de normalisation. */
    public function testProviderRibDelegueLaNormalisation(): void
    {
        foreach (['093', '93', '371.0', 'SIE', ''] as $code) {
            self::assertSame(Etablissements::normaliserCode($code), RibEtablissementProvider::normaliserCode($code));
        }
    }
}
