<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Service\AppariementLettrage;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Bareme d'appariement ligne bancaire non lettree <-> dossier paye. Le score n'a de
 * valeur que si la HIERARCHIE des signaux tient : c'est ce qu'on verifie ici, plus que
 * des valeurs absolues (qui peuvent etre reajustees).
 */
final class AppariementLettrageTest extends TestCase
{
    private AppariementLettrage $appariement;

    protected function setUp(): void
    {
        $this->appariement = new AppariementLettrage();
    }

    public function testRachatSecAvecImmatriculationIdentiqueEstUneCorrespondanceForte(): void
    {
        $dossier = $this->dossier(DossierMotif::RACHAT_SEC, [
            'immat' => 'FH-658-YE',
            'montant' => '16001.37',
            'etab' => '291',
            'nom' => 'CGI FINANCEMENT',
            'paye' => '2026-07-27',
        ]);

        $resultat = $this->appariement->scorer($this->ligne([
            'type' => 'RBC',
            'libelle' => 'RBC // CC25524290  FH-658-YE',
            'numimmat' => 'FH-658-YE',
            'montant' => '16001.37',
            'codeetab' => '291',
            'nom' => 'CGI FINANCEMENT',
            'date_piece' => '2026-07-27',
        ]), $dossier);

        self::assertSame(100, $resultat['score'], 'Tous les signaux concordent : score plafonne.');
        self::assertContains('Immatriculation identique : FH-658-YE', $resultat['raisons']);
        self::assertContains('Même jour : 27/07/2026', $resultat['raisons']);
    }

    public function testMotifIncoherentPenaliseMemeQuandLeResteConcorde(): void
    {
        // Ligne trop-percu face a un dossier de rachat sec : les deux ne se melangent
        // jamais, la penalite doit se voir malgre un montant et un nom identiques.
        // Volontairement PAS d'etablissement ni de date : sinon les deux scores
        // plafonneraient a 100 et l'ecart serait invisible.
        $commun = ['montant' => '495.64', 'nom' => 'DUPONT MARCEL'];
        $ligne = $this->ligne([
            'type' => 'TP',
            'libelle' => 'TP // TROP PERCU',
            'montant' => '495.64',
            'nom' => 'DUPONT MARCEL',
        ]);

        $coherent = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, $commun));
        $incoherent = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::RACHAT_SEC, $commun));

        self::assertGreaterThan($incoherent['score'] + 40, $coherent['score']);
        self::assertNotContains('Motif rachat sec', $incoherent['raisons']);
    }

    public function testPourUnTropPercuLeCodeIcarPeseePlusQueLImmatriculation(): void
    {
        // Sur un trop-percu, la cle metier est le code ICAR (cf. arbitrage PO) : c'est
        // lui qui doit porter le signal, pas la plaque.
        // Sans etablissement ni date, pour que le score sans ICAR reste sous le plafond
        // et que la contribution de l'ICAR soit mesurable.
        $base = ['montant' => '3763.23', 'nom' => 'MARTIN'];
        $ligne = $this->ligne([
            'type' => 'TP',
            'libelle' => 'TP // TROP PERCU',
            'compte' => 'COMPTANT-804139',
            'montant' => '3763.23',
            'nom' => 'MARTIN',
        ]);

        $avecIcar = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, $base + ['icar' => 'COMPTANT-804139']));
        $sansIcar = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, $base + ['icar' => 'COMPTANT-999999']));

        self::assertGreaterThan($sansIcar['score'], $avecIcar['score']);
        self::assertContains('Code ICAR identique : COMPTANT-804139', $avecIcar['raisons']);
    }

    public function testImmatriculationRetrouveeDansLeLibelleQuandLaLigneNeLaPorteAilleurs(): void
    {
        // Cas courant : la plaque n'est ni dans numimmat ni dans numvin, seulement
        // dans le libelle comptable, et avec une ponctuation differente.
        $resultat = $this->appariement->scorer($this->ligne([
            'type' => 'RBC',
            'libelle' => 'RBC // 101M8799213  GP939XX',
        ]), $this->dossier(DossierMotif::RACHAT_SEC, ['immat' => 'GP-939-XX']));

        self::assertContains('Immatriculation détectée dans le libellé', $resultat['raisons']);
    }

    public function testFormatsHeterogenesDeCodeEtablissementSontRapproches(): void
    {
        // Sage melange « 023 », « 23 » et « CG23 » : la comparaison doit tenir.
        foreach (['23', '023', 'CG23'] as $codeLigne) {
            $resultat = $this->appariement->scorer(
                $this->ligne(['type' => 'TP', 'codeetab' => $codeLigne]),
                $this->dossier(DossierMotif::TROP_PERCU, ['etab' => '023']),
            );
            self::assertContains('Établissement '.$codeLigne, $resultat['raisons'], 'Code '.$codeLigne);
        }
    }

    public function testMontantExactProcheEtEloigneSontHierarchises(): void
    {
        $ligne = $this->ligne(['type' => 'TP', 'montant' => '1000.00']);

        $exact = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, ['montant' => '1000.00']));
        $proche = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, ['montant' => '1001.50']));
        $cinqPourCent = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, ['montant' => '1030.00']));
        $loin = $this->appariement->scorer($ligne, $this->dossier(DossierMotif::TROP_PERCU, ['montant' => '4500.00']));

        self::assertGreaterThan($proche['score'], $exact['score']);
        self::assertGreaterThan($cinqPourCent['score'], $proche['score']);
        self::assertGreaterThan($loin['score'], $cinqPourCent['score']);
    }

    public function testMontantSigneNegatifEstCompareEnValeurAbsolue(): void
    {
        // Le sens de l'ecriture depend du journal : seul le montant compte.
        $resultat = $this->appariement->scorer(
            $this->ligne(['type' => 'TP', 'montant' => '-1000.00']),
            $this->dossier(DossierMotif::TROP_PERCU, ['montant' => '1000.00']),
        );

        self::assertContains('Montant exact : 1 000,00 €', $resultat['raisons']);
    }

    public function testEcartDeDateSuperieurAUnMoisPenalise(): void
    {
        $proche = $this->appariement->scorer(
            $this->ligne(['type' => 'TP', 'date_piece' => '2026-08-31']),
            $this->dossier(DossierMotif::TROP_PERCU, ['paye' => '2026-08-30']),
        );
        $lointain = $this->appariement->scorer(
            $this->ligne(['type' => 'TP', 'date_piece' => '2026-08-31']),
            $this->dossier(DossierMotif::TROP_PERCU, ['paye' => '2026-01-15']),
        );

        self::assertGreaterThan($lointain['score'], $proche['score']);
        self::assertContains('Date à ±1 jour', $proche['raisons']);
    }

    public function testNomInverseOuMalOrthographieResteRapproche(): void
    {
        // L'IA de controle reordonne souvent « Prenom Nom » : le rapprochement par
        // n-grammes doit encore accrocher.
        $resultat = $this->appariement->scorer(
            $this->ligne(['type' => 'TP', 'nom' => 'HATZENBERGER CEDRIC']),
            $this->dossier(DossierMotif::TROP_PERCU, ['nom' => 'Cedric Hatzenberger']),
        );

        self::assertContains('Nom partiellement similaire', $resultat['raisons']);
    }

    public function testScoreResteDansLesBornes(): void
    {
        // Tout divergent : le score plancher est 0, jamais negatif.
        $resultat = $this->appariement->scorer(
            $this->ligne(['type' => 'RBC', 'montant' => '9999.00', 'codeetab' => '999', 'nom' => 'ZZZZZZ', 'date_piece' => '2020-01-01']),
            $this->dossier(DossierMotif::TROP_PERCU, ['montant' => '1.00', 'etab' => '001', 'nom' => 'AAAAAA', 'paye' => '2026-08-31']),
        );

        self::assertSame(0, $resultat['score']);
    }

    public function testMeilleurRetientLeDossierLePlusProbable(): void
    {
        $ligne = $this->ligne([
            'type' => 'RBC',
            'libelle' => 'RBC // 101M8799213  GP-939-XX',
            'numimmat' => 'GP-939-XX',
            'montant' => '25189.12',
        ]);

        $mauvais = $this->dossier(DossierMotif::RACHAT_SEC, ['immat' => 'AA-111-BB', 'montant' => '999.00']);
        $bon = $this->dossier(DossierMotif::RACHAT_SEC, ['immat' => 'GP-939-XX', 'montant' => '25189.12']);

        $meilleur = $this->appariement->meilleur($ligne, [$mauvais, $bon, $mauvais]);

        self::assertNotNull($meilleur);
        self::assertSame($bon, $meilleur['dossier']);
    }

    public function testMeilleurRenvoieNullSansAucunDossier(): void
    {
        self::assertNull($this->appariement->meilleur($this->ligne(['type' => 'TP']), []));
    }

    /**
     * Ligne de `remboursement.v_lettrage`, colonnes vides par defaut.
     *
     * @param array<string, mixed> $champs
     *
     * @return array<string, mixed>
     */
    private function ligne(array $champs): array
    {
        return $champs + [
            'type' => 'TP',
            'libelle' => '',
            'numimmat' => null,
            'numvin' => null,
            'montant' => '0',
            'codeetab' => '',
            'compte' => '',
            'nom' => '',
            'date_piece' => null,
        ];
    }

    /**
     * @param array{immat?: string, icar?: string, montant?: string, etab?: string, nom?: string, paye?: string} $champs
     */
    private function dossier(DossierMotif $motif, array $champs): Dossier
    {
        $dossier = new Dossier($motif, 'test@demonstration.invalid');
        $dossier->setNomClient($champs['nom'] ?? '');
        $dossier->setMontant($champs['montant'] ?? '0');
        $dossier->setEtablissementCode($champs['etab'] ?? null);
        $dossier->setImmatriculation($champs['immat'] ?? null);
        $dossier->setCodeIcar($champs['icar'] ?? null);
        if (isset($champs['paye'])) {
            $dossier->setPayeLe(new DateTimeImmutable($champs['paye']));
        }

        return $dossier;
    }
}
