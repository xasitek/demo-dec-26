<?php

declare(strict_types=1);

namespace App\Lettrage\Moteur;

/**
 * Recherche de sous-ensembles equilibres, en centimes.
 *
 * On travaille en entiers : additionner des flottants puis arrondir le total
 * decale le resultat d'un centime une fois sur quelques milliers, et un centime
 * suffit a faire echouer un equilibre juste.
 *
 * La recherche est BORNEE et elle le dit : nombre de lignes, iterations, budget
 * de temps. Une recherche qui s'arrete en le signalant vaut mieux qu'une
 * reponse partielle presentee comme complete.
 */
final class Combinaisons
{
    /**
     * Les sous-ensembles de `lignes` dont la somme vaut `cible`, a la tolerance pres.
     *
     * @param list<array{id: string, cents: int}> $lignes
     *
     * @return array{
     *   solutions: list<list<string>>, iterations: int, ms: float, tronquee: bool
     * }
     */
    public static function chercher(
        array $lignes,
        int $cible,
        int $toleranceCents = 0,
        int $maxLignes = Tolerances::COMBI_MAX_LIGNES,
        int $maxIterations = Tolerances::COMBI_MAX_ITERATIONS,
        int $budgetMs = Tolerances::COMBI_BUDGET_MS,
    ): array {
        $debut = microtime(true);
        $items = array_values(array_filter($lignes, static fn (array $l): bool => $l['cents'] > 0));
        usort($items, static fn (array $a, array $b): int => $b['cents'] <=> $a['cents']);

        $n = \count($items);
        $suffixe = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; --$i) {
            $suffixe[$i] = $suffixe[$i + 1] + $items[$i]['cents'];
        }

        /** @var list<list<string>> $solutions */
        $solutions = [];
        $iterations = 0;
        $tronquee = false;
        /** @var list<string> $courant */
        $courant = [];

        $explorer = function (int $depart, int $reste) use (
            &$explorer, &$solutions, &$iterations, &$tronquee, &$courant,
            $items, $n, $suffixe, $maxLignes, $maxIterations, $budgetMs, $debut, $toleranceCents
        ): void {
            ++$iterations;
            if (abs($reste) <= $toleranceCents && [] !== $courant) {
                $solutions[] = $courant;

                return;
            }
            if ($depart >= $n || \count($courant) >= $maxLignes) {
                return;
            }
            if ($suffixe[$depart] < $reste - $toleranceCents) {
                return;
            }
            if (\count($solutions) >= 6 || $iterations > $maxIterations
                || (microtime(true) - $debut) * 1000 > $budgetMs) {
                $tronquee = true;

                return;
            }
            for ($i = $depart; $i < $n; ++$i) {
                if ($items[$i]['cents'] > $reste + $toleranceCents) {
                    continue;
                }
                if ($i > $depart && $items[$i]['cents'] === $items[$i - 1]['cents']) {
                    continue;
                }
                $courant[] = $items[$i]['id'];
                $explorer($i + 1, $reste - $items[$i]['cents']);
                array_pop($courant);
                if ($tronquee) {
                    return;
                }
            }
        };

        if ($n > 0 && $cible > 0) {
            $explorer(0, $cible);
        }

        // La composition la plus simple d'abord : entre deux explications d'un
        // meme montant, celle qui mobilise le moins d'ecritures est la plus
        // probable, et c'est celle qu'un comptable proposerait.
        usort($solutions, static fn (array $a, array $b): int => \count($a) <=> \count($b));

        // Deux decouvertes du meme jeu d'ecritures sont une seule reponse.
        $vues = [];
        $distinctes = [];
        foreach ($solutions as $s) {
            $cle = $s;
            sort($cle);
            $k = implode('|', $cle);
            if (!isset($vues[$k])) {
                $vues[$k] = true;
                $distinctes[] = $s;
            }
        }

        return [
            'solutions' => $distinctes,
            'iterations' => $iterations,
            'ms' => (microtime(true) - $debut) * 1000,
            'tronquee' => $tronquee,
        ];
    }
}
