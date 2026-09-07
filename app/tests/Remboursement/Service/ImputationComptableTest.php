<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Service\ImputationComptable;
use PHPUnit\Framework\TestCase;

final class ImputationComptableTest extends TestCase
{
    private function refSansPrefixe(Dossier $d): string
    {
        return substr($d->getReference(), \strlen('REMB-'));
    }

    public function testLibelleRachatSecUtiliseImmat(): void
    {
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setImmatriculation('EA-123-BC');

        $imp = (new ImputationComptable())->pour($d);

        self::assertSame('RACHAT SEC // EA-123-BC - '.$this->refSansPrefixe($d), $imp['libelle']);
    }

    public function testLibelleTropPercuUtiliseControleIcar(): void
    {
        $d = new Dossier(DossierMotif::TROP_PERCU);
        $d->setControleIcar('55526');

        $imp = (new ImputationComptable())->pour($d);

        self::assertSame('TROP PERCU // 55526 - '.$this->refSansPrefixe($d), $imp['libelle']);
    }

    public function testLibelleTropPercuIgnoreLaSaisieSecretaire(): void
    {
        // ICAR non detecte par l'IA : le libelle ne reprend PAS la saisie secretaire.
        $d = new Dossier(DossierMotif::TROP_PERCU);
        $d->setCodeIcar('55526');

        $imp = (new ImputationComptable())->pour($d);

        self::assertSame('TROP PERCU //  - '.$this->refSansPrefixe($d), $imp['libelle']);
    }

    public function testLibellePrioriseLaValeurControlee(): void
    {
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setImmatriculation('OLD-000-XX');
        $d->setControleImmatriculation('AB-456-CD');

        $imp = (new ImputationComptable())->pour($d);

        self::assertStringContainsString('RACHAT SEC // AB-456-CD -', $imp['libelle']);
    }

    public function testCodeComptableEtRoleTiersFixes(): void
    {
        $imp = (new ImputationComptable())->pour(new Dossier(DossierMotif::TROP_PERCU));

        self::assertSame('4111000', $imp['codeComptable']);
        self::assertSame('COMPTANT', $imp['roleTiers']);
    }
}
