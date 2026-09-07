<?php

declare(strict_types=1);

namespace App\Affectation\Moteur;

/**
 * Recherche reelle de sous-ensembles de factures dont la somme fait le montant.
 *
 * C'est le point metier essentiel de l'outil : un virement global regle
 * plusieurs factures, parfois de plusieurs societes, et aucun lettrage par
 * montant unitaire ne le retrouvera jamais.
 *
 * La recherche est exhaustive dans les limites qu'elle se donne : parcours en
 * profondeur sur les factures triees par montant decroissant, avec elagage par
 * somme restante, borne sur le nombre de factures et budget de temps. Elle
 * retourne TOUTES les solutions trouvees, parce que l'ambiguite est une
 * information : deux combinaisons plausibles interdisent de trancher seul.
 */
final class Combinaisons
{
    /**
     * @param list<array{id: string, montant: float}> $factures
     *
     * @return array{
     *     solutions: list<list<string>>,
     *     noeuds: int,
     *     ms: float,
     *     tronquee: bool
     * }
     */
    public static function chercher(array $factures, float $cible, int $maxFactures = Bareme::COMBINAISON_MAX_FACTURES, int $budgetMs = Bareme::COMBINAISON_BUDGET_MS): array
    {
        $debut = microtime(true);

        // On travaille en centimes : les flottants ne se somment pas juste.
        $cibleC = (int) round($cible * 100);
        $items = [];
        foreach ($factures as $f) {
            $c = (int) round($f['montant'] * 100);
            if ($c > 0 && $c <= $cibleC) {
                $items[] = ['id' => $f['id'], 'c' => $c];
            }
        }
        usort($items, static fn (array $a, array $b): int => $b['c'] <=> $a['c']);

        $n = \count($items);
        // Sommes suffixes : elles permettent d'elaguer des qu'il ne reste pas
        // assez de matiere pour atteindre la cible.
        $suffixe = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; --$i) {
            $suffixe[$i] = $suffixe[$i + 1] + $items[$i]['c'];
        }

        $solutions = [];
        $noeuds = 0;
        $tronquee = false;
        $courant = [];

        $explorer = function (int $depart, int $reste) use (&$explorer, &$solutions, &$noeuds, &$tronquee, &$courant, $items, $n, $suffixe, $maxFactures, $budgetMs, $debut): void {
            ++$noeuds;
            if (0 === $reste) {
                $solutions[] = $courant;

                return;
            }
            if ($depart >= $n || \count($courant) >= $maxFactures || $suffixe[$depart] < $reste) {
                return;
            }
            if (\count($solutions) >= 8 || (microtime(true) - $debut) * 1000 > $budgetMs) {
                $tronquee = true;

                return;
            }
            for ($i = $depart; $i < $n; ++$i) {
                if ($items[$i]['c'] > $reste) {
                    continue;
                }
                // Sauter les montants identiques consecutifs deja essayes a ce
                // rang : sinon on redecouvre la meme combinaison n fois.
                if ($i > $depart && $items[$i]['c'] === $items[$i - 1]['c']) {
                    continue;
                }
                $courant[] = $items[$i]['id'];
                $explorer($i + 1, $reste - $items[$i]['c']);
                array_pop($courant);
                if ($tronquee) {
                    return;
                }
            }
        };

        if ($n > 0 && $cibleC > 0) {
            $explorer(0, $cibleC);
        }

        // La composition la plus simple d'abord. Entre deux explications d'un
        // meme montant, celle qui mobilise le moins de factures est la plus
        // probable, et c'est celle qu'un comptable proposerait.
        usort($solutions, static fn (array $a, array $b): int => \count($a) <=> \count($b));

        return [
            'solutions' => $solutions,
            'noeuds' => $noeuds,
            'ms' => (microtime(true) - $debut) * 1000,
            'tronquee' => $tronquee,
        ];
    }
}
