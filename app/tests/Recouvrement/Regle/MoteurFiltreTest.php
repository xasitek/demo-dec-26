<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Regle;

use App\Recouvrement\Regle\MoteurFiltre;
use PHPUnit\Framework\TestCase;

final class MoteurFiltreTest extends TestCase
{
    private MoteurFiltre $moteur;

    protected function setUp(): void
    {
        $this->moteur = new MoteurFiltre();
    }

    public function testFiltresVidesDonnentTrue(): void
    {
        self::assertSame(['TRUE', []], $this->moteur->versSql([]));
    }

    public function testEgalite(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'collectif', 'operateur' => 'eq', 'valeur' => '4111000'],
        ]);

        self::assertSame('v.collectif = :f0', $sql);
        self::assertSame(['f0' => '4111000'], $params);
    }

    public function testListeIn(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'collectif', 'operateur' => 'in', 'valeur' => ['4111000', '4161000']],
        ]);

        self::assertSame('v.collectif IN (:f0, :f1)', $sql);
        self::assertSame(['f0' => '4111000', 'f1' => '4161000'], $params);
    }

    public function testListeInVideDonneFalse(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'collectif', 'operateur' => 'in', 'valeur' => []],
        ]);

        self::assertSame('FALSE', $sql);
        self::assertSame([], $params);
    }

    public function testSuperieurNumerique(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'montant_initial', 'operateur' => 'gt', 'valeur' => 0],
        ]);

        self::assertSame('v.montant_initial > :f0', $sql);
        self::assertSame(['f0' => 0.0], $params);
    }

    public function testContient(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'libelle', 'operateur' => 'contient', 'valeur' => 'GARAGE'],
        ]);

        self::assertSame('v.libelle ILIKE :f0', $sql);
        self::assertSame(['f0' => '%GARAGE%'], $params);
    }

    public function testCombinaisonEt(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'collectif', 'operateur' => 'in', 'valeur' => ['4111000']],
            ['champ' => 'montant_initial', 'operateur' => 'gt', 'valeur' => 0],
        ]);

        self::assertSame('v.collectif IN (:f0) AND v.montant_initial > :f1', $sql);
        self::assertSame(['f0' => '4111000', 'f1' => 0.0], $params);
    }

    public function testChampInvalideEstIgnore(): void
    {
        // Champ hors liste blanche (tentative d'injection) : ignoré.
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'collectif); DROP TABLE x; --', 'operateur' => 'eq', 'valeur' => 'x'],
        ]);

        self::assertSame('TRUE', $sql);
        self::assertSame([], $params);
    }

    public function testOperateurInvalideEstIgnore(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'collectif', 'operateur' => 'like_hack', 'valeur' => 'x'],
        ]);

        self::assertSame('TRUE', $sql);
        self::assertSame([], $params);
    }

    public function testSuperieurSurChampTexteEstIgnore(): void
    {
        // > / < n'ont de sens que sur un champ numérique : sur un champ texte
        // (collectif) c'est rejeté à la normalisation ET par versSql (sinon
        // "text > numeric" planterait la requête).
        $filtres = [['champ' => 'collectif', 'operateur' => 'gt', 'valeur' => '4111000']];

        self::assertSame([], $this->moteur->normaliserFiltres($filtres));
        self::assertSame(['TRUE', []], $this->moteur->versSql($filtres));
    }

    public function testSuperieurSurChampNumeriqueReste(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'jours_retard', 'operateur' => 'gt', 'valeur' => 30],
        ]);

        self::assertSame('v.jours_retard > :f0', $sql);
        self::assertSame(['f0' => 30.0], $params);
    }

    public function testNotinExclutLaListeEtLaissePasserLesNull(): void
    {
        [$sql, $params] = $this->moteur->versSql([
            ['champ' => 'codeetab', 'operateur' => 'notin', 'valeur' => ['111', '112']],
        ]);

        self::assertSame('(v.codeetab IS NULL OR v.codeetab NOT IN (:f0, :f1))', $sql);
        self::assertSame(['f0' => '111', 'f1' => '112'], $params);
    }

    public function testNotinListeVideNExclutRien(): void
    {
        self::assertSame(['TRUE', []], $this->moteur->versSql([
            ['champ' => 'codeetab', 'operateur' => 'notin', 'valeur' => []],
        ]));
    }

    public function testNotinCorrespondLigne(): void
    {
        $filtres = [['champ' => 'codeetab', 'operateur' => 'notin', 'valeur' => ['111', '112']]];

        self::assertFalse($this->moteur->correspondLigne(['codeetab' => '111'], $filtres), 'etablissement cede exclu');
        self::assertTrue($this->moteur->correspondLigne(['codeetab' => '807'], $filtres), 'autre etablissement conserve');
        self::assertTrue($this->moteur->correspondLigne(['codeetab' => null], $filtres), 'null conserve');
    }
}
