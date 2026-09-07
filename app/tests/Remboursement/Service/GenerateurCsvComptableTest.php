<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Service\GenerateurCsvComptable;
use PHPUnit\Framework\TestCase;

/**
 * Le CSV d'OD est la sortie comptable du module : son en-tete et l'ordre de ses
 * colonnes sont imposes par l'import Eloficash. Ces tests fixent le contenu des deux
 * colonnes d'identite — `codeClientICAR` (position 4) et `NumImmat` (position 13) —
 * dont le remplissage depend du motif.
 */
final class GenerateurCsvComptableTest extends TestCase
{
    /** Position des colonnes controlees ici, d'apres l'en-tete impose par Eloficash. */
    private const COL_ICAR = 4;
    private const COL_IMMAT = 13;

    /** @return list<string> Les cellules de la ligne de DEBIT (premiere ligne apres l'en-tete). */
    private function ligneDebit(Dossier $dossier): array
    {
        $csv = (new GenerateurCsvComptable())->construire($dossier, ['societe' => 'HM', 'codeEtab' => '051']);

        return explode(';', explode("\n", $csv['csv'])[1]);
    }

    public function testRachatSecPorteLeCodeClientIcarEtLImmatriculation(): void
    {
        // Le code client fait partie de l'ecriture au rachat sec aussi : la colonne
        // etait laissee vide avant l'arbitrage du 2026-09-03.
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setImmatriculation('EA-123-BC');
        $d->setCodeIcar('805887');

        $ligne = $this->ligneDebit($d);

        self::assertSame('805887', $ligne[self::COL_ICAR]);
        self::assertSame('EA-123-BC', $ligne[self::COL_IMMAT]);
    }

    public function testLaValeurRetenueParLaComptablePrimeSurLaSaisie(): void
    {
        // Sentinelle « 0 » posee au depot, corrigee ensuite par la comptabilite : c'est
        // la valeur validee qui part dans l'ecriture.
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setImmatriculation('EA-123-BC');
        $d->setCodeIcar('0');
        $d->setControleIcar('805887');
        $d->enregistrerValidation(['code_icar' => '907001'], null);

        self::assertSame('907001', $this->ligneDebit($d)[self::COL_ICAR]);
    }

    public function testLaLectureDeLIaPrimeSurLaSaisieQuandRienNEstValide(): void
    {
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setImmatriculation('EA-123-BC');
        $d->setCodeIcar('0');
        $d->setControleIcar('805887');

        self::assertSame('805887', $this->ligneDebit($d)[self::COL_ICAR]);
    }

    public function testTropPercuPorteLIcarSansImmatriculation(): void
    {
        // Non-regression : le trop-percu n'a pas de vehicule, la colonne NumImmat
        // reste vide.
        $d = new Dossier(DossierMotif::TROP_PERCU);
        $d->setCodeIcar('55526');

        $ligne = $this->ligneDebit($d);

        self::assertSame('55526', $ligne[self::COL_ICAR]);
        self::assertSame('', $ligne[self::COL_IMMAT]);
    }
}
