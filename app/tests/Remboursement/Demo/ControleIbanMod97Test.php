<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Demo;

use App\Remboursement\Demo\ControleIbanMod97;
use App\Remboursement\Service\IbanFictif;
use PHPUnit\Framework\TestCase;

/**
 * R04 — la cle de controle de l'IBAN, renforcement de la copie de demonstration.
 *
 * CE QUE CES TESTS ETABLISSENT, ET LA FRONTIERE QU'ILS TRACENT. Le module
 * herite refuse ce qui est HORS STRUCTURE ; R04 refuse ce dont la CLE est
 * fausse. Les deux ne se recouvrent pas, et confondre les deux ferait croire
 * que le module verifiait deja la cle. Chaque cas dit donc non seulement le
 * verdict, mais QUI le rend.
 */
final class ControleIbanMod97Test extends TestCase
{
    /** Un IBAN synthetique valide passe, et R04 n'a rien a lui reprocher. */
    public function testIbanValideAccepte(): void
    {
        $iban = IbanFictif::pour('beneficiaire-de-reference');

        self::assertTrue(ControleIbanMod97::structureRecevable($iban));
        self::assertTrue(ControleIbanMod97::cleJuste($iban));
        self::assertNull(ControleIbanMod97::reproche($iban));
    }

    /**
     * Une structure incorrecte reste l'affaire du module herite.
     *
     * R04 se TAIT : c'est C42 ou C44 qui refuse, a la generation. Si R04
     * parlait ici, le registre deviendrait faux.
     */
    public function testStructureIncorrecteResteAuControleHerite(): void
    {
        foreach (['FR7X99999123456789012345678', 'FR76', '76FR9999912345678901', ''] as $iban) {
            self::assertFalse(ControleIbanMod97::structureRecevable($iban),
                sprintf('« %s » ne devrait pas passer la structure.', $iban));
            self::assertNull(ControleIbanMod97::reproche($iban),
                sprintf('R04 ne doit rien reprocher a « %s » : ce n\'est pas son sujet.', $iban));
        }
    }

    /** Structure correcte, cle fausse : c'est exactement le trou que R04 ferme. */
    public function testCleFausseRefuseeParR04(): void
    {
        $valide = IbanFictif::pour('cle-a-fausser');
        $fausse = substr($valide, 0, -1).(string) ((((int) substr($valide, -1)) + 1) % 10);

        self::assertNotSame($valide, $fausse);
        self::assertTrue(ControleIbanMod97::structureRecevable($fausse),
            'La structure doit rester recevable : sinon le test ne prouve rien sur la cle.');
        self::assertFalse(ControleIbanMod97::cleJuste($fausse));
        self::assertSame('IBAN invalide — clé de contrôle incorrecte',
            ControleIbanMod97::reproche($fausse));
    }

    /**
     * Un IBAN ecrit par groupes de quatre, en minuscules, reste le meme numero.
     *
     * On normalise avant de juger. L'inverse reprocherait a une secretaire sa
     * facon d'ecrire un numero, ce qui n'est pas un controle.
     */
    public function testEspacesEtMinusculesNormalisesAvantJugement(): void
    {
        $iban = IbanFictif::pour('normalisation');
        $espace = trim(chunk_split($iban, 4, ' '));
        $minuscules = strtolower($espace);

        self::assertSame($iban, ControleIbanMod97::normaliser($espace));
        self::assertSame($iban, ControleIbanMod97::normaliser($minuscules));
        self::assertNull(ControleIbanMod97::reproche($espace));
        self::assertNull(ControleIbanMod97::reproche($minuscules));

        // Et la normalisation ne blanchit rien : une cle fausse reste fausse,
        // meme joliment presentee.
        $fausse = substr($iban, 0, -1).(string) ((((int) substr($iban, -1)) + 1) % 10);
        self::assertSame('IBAN invalide — clé de contrôle incorrecte',
            ControleIbanMod97::reproche(trim(chunk_split($fausse, 4, ' '))));
    }

    /**
     * Les IBAN synthetiques du monde passent tous, des deux cotes du virement.
     *
     * C'est la contre-epreuve de R04 : un renforcement qui refuserait les IBAN
     * legitimes de la demonstration serait un faux blocage, pas un controle.
     */
    public function testIbanSynthetiquesDebiteurEtBeneficiaireAcceptes(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            $debiteur = IbanFictif::pour('etablissement-'.$i);
            $beneficiaire = IbanFictif::pour('client-'.$i);

            self::assertNull(ControleIbanMod97::reproche($debiteur),
                sprintf('IBAN debiteur synthetique refuse a tort : %s', $debiteur));
            self::assertNull(ControleIbanMod97::reproche($beneficiaire),
                sprintf('IBAN beneficiaire synthetique refuse a tort : %s', $beneficiaire));
            self::assertSame(27, \strlen($debiteur));
        }
    }

    /** Le code du renforcement ne se confond pas avec un controle herite. */
    public function testLeCodeEstUnRenforcementPasUnControleHerite(): void
    {
        self::assertSame('R04', ControleIbanMod97::CODE);
        self::assertStringStartsWith('R', ControleIbanMod97::CODE);
    }
}
