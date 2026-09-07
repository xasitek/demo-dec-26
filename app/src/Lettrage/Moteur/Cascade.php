<?php

declare(strict_types=1);

namespace App\Lettrage\Moteur;

use Generator;

/**
 * La cascade de lettrage.
 *
 * Le principe directeur est le CRESCENDO : on lettre du plus sur au plus
 * permissif. Chaque methode ne voit que les lignes que les precedentes n'ont
 * pas consommees, et **une ligne consommee n'est jamais reprise**. C'est ce qui
 * rend l'ordre decisif : deplacer une methode change qui recupere quelle ligne,
 * donc change le resultat.
 *
 * La classe ne lit JAMAIS le schema `lettrage_verite`. Elle travaille sur le
 * monde sans connaitre la reponse.
 */
final class Cascade
{
    /** Comptes techniques d'attente : ils ne prouvent rien a eux seuls. */
    private const COMPTES_ATTENTE = ['471000', '4679999'];

    /** References trop portees pour prouver quoi que ce soit. */
    private const REFERENCES_GENERIQUES = ['ASOLDER', 'TRANSFERT', 'LIAISON', 'DIVERS', 'REGUL', 'ACOMPTE'];

    /** Une cle portee par plus de tant de lignes est ecartee. */
    private const CLE_FREQUENCE_MAX = 10;

    /** Un groupe transitif plus large que cela est ecarte. */
    private const GROUPE_TAILLE_MAX = 15;

    /** Montants d'acompte connus du secteur. */
    private const ACOMPTES = [4900, 19900];

    /**
     * Execute la cascade sur un pool d'ecritures d'un meme compte client.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return array{
     *   groupes: list<array<string, mixed>>,
     *   residu: list<string>,
     *   consommation: array<string, int>,
     *   chrono: array<string, float>,
     *   combinaison: array<string, mixed>
     * }
     */
    public function executer(array $lignes, ?string $arreterApres = null): array
    {
        $pool = [];
        foreach ($lignes as $l) {
            $pool[(string) $l['id']] = $l + ['cents' => (int) round((float) $l['montant'] * 100)];
        }

        /** @var array<string, string> $consomme */
        $consomme = [];
        /** @var list<array<string, mixed>> $groupes */
        $groupes = [];
        /** @var array<string, int> $consommation */
        $consommation = [];
        /** @var array<string, float> $chrono */
        $chrono = [];
        $combi = ['iterations' => 0, 'ms' => 0.0, 'solutions' => 0, 'tronquee' => false];

        foreach (Catalogue::cascade() as $methode) {
            $code = $methode['code'];
            $t0 = microtime(true);
            $restant = array_diff_key($pool, $consomme);

            $trouves = match ($code) {
                'M13' => $this->m13($restant),
                'M01' => $this->m01($restant),
                'M00' => $this->m00($restant),
                'M02' => $this->m02($restant, $combi),
                'M03' => $this->parCle($restant, 'vin8', 'M03'),
                'M04' => $this->parCle($restant, 'immatriculation', 'M04'),
                'M05' => $this->parCle($restant, 'ordre_reparation', 'M05'),
                'M06' => $this->m06($restant, $combi),
                'M07' => $this->liaison($restant, 'M07', 'societe_id'),
                'M08' => $this->m08($restant),
                'M09' => $this->m09($restant),
                'M10', 'M12' => $this->soldesOpposes($restant, $code),
                'M17' => $this->m17($restant),
                'M18' => $this->m18($restant),
                'M19' => $this->liaison($restant, 'M19', 'etablissement_id'),
                'M20' => $this->m20($restant),
                'M21' => $this->m21($restant),
                'M22' => $this->m22($restant),
                'M23' => $this->m23($restant),
                'M24' => $this->m24($restant),
                'M26' => $this->m26($restant),
                'M11' => $this->m11($restant),
                default => [],
            };

            $chrono[$code] = (microtime(true) - $t0) * 1000;
            $consommation[$code] = 0;

            foreach ($trouves as $g) {
                // Une ligne deja consommee ne peut pas entrer dans un second
                // groupe : c'est la regle qui rend la cascade honnete.
                $collision = false;
                foreach ($g['lignes'] as $id) {
                    if (isset($consomme[$id])) {
                        $collision = true;
                        break;
                    }
                }
                if ($collision) {
                    continue;
                }
                foreach ($g['lignes'] as $id) {
                    $consomme[$id] = $code;
                }
                $groupes[] = $g + ['methode' => $code, 'rang' => $methode['rang']];
                $consommation[$code] += \count($g['lignes']);
            }

            if (null !== $arreterApres && $code === $arreterApres) {
                break;
            }
        }

        return [
            'groupes' => $groupes,
            'residu' => array_keys(array_diff_key($pool, $consomme)),
            'consommation' => $consommation,
            'chrono' => $chrono,
            'combinaison' => $combi,
        ];
    }

    // ================================================================ methodes

    /** M13 — meme tiers, deux comptes comptables differents. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m13(array $p): array
    {
        $groupes = [];
        foreach ($this->paires($p) as [$d, $c]) {
            if ($d['compte'] === $c['compte']) {
                continue;
            }
            // Deux comptes de CREANCE, pas une creance contre une banque.
            // Sans cette restriction, la methode ramassait tout couple
            // facture / reglement du meme client et privait de matiere les
            // methodes a cle forte qui la suivent -- exactement le defaut que
            // l'ordre de la cascade est cense eviter.
            if (!str_starts_with((string) $d['compte'], '411') || !str_starts_with((string) $c['compte'], '411')) {
                continue;
            }
            if (!$this->memeTiers($d, $c)) {
                continue;
            }
            // Une cle forte partagee est EXIGEE. Dans un pool d'un seul compte
            // client, « meme tiers » est vrai de toutes les paires : sans clé,
            // la methode appariait deux lignes voisines par leur seul montant,
            // c'est-a-dire au hasard des ecarts. Un lettrage tire au hasard est
            // faux la moitie du temps, meme quand le solde tombe.
            $cles = $this->clesCommunes($d, $c);
            if ([] === $cles) {
                continue;
            }
            $g = $this->formerPaire($d, $c, 'reclassement entre deux comptes de créance du même tiers, clés : '
                .implode(', ', array_keys($cles)));
            if (null !== $g) {
                $groupes[] = $g;
            }
        }

        return $groupes;
    }

    /** M01 — montant exact sur cle forte. Le coeur du dispositif. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m01(array $p): array
    {
        $groupes = [];
        foreach ($this->paires($p) as [$d, $c]) {
            $cles = $this->clesCommunes($d, $c);
            if ([] === $cles) {
                continue;
            }
            if ($this->contradiction($d, $c)) {
                continue;
            }
            $g = $this->formerPaire($d, $c, 'clés communes : '.implode(', ', array_keys($cles)));
            if (null !== $g) {
                $g['indices'] = $cles;
                $groupes[] = $g;
            }
        }

        return $groupes;
    }

    /** M00 — comptes d'attente anciens, solde negligeable. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m00(array $p): array
    {
        $attente = array_filter($p, fn (array $l): bool => \in_array($l['compte'], self::COMPTES_ATTENTE, true));
        if ([] === $attente) {
            return [];
        }
        $solde = $this->solde($attente);
        $age = Tolerances::dormance(array_column($attente, 'date_ecriture'))['jours'];
        if ($age < 180 || abs($solde) > 20.0) {
            return [];
        }

        return [[
            'lignes' => array_keys($attente),
            'motif' => sprintf("compte d'attente de %d jours, solde résiduel de %s €",
                $age, number_format(abs($solde), 2, ',', ' ')),
            'solde' => $solde,
            'apurement' => true,
        ]];
    }

    /** M02 — references partagees, groupes transitifs, puis sous-ensemble equilibre. */
    /**
     * @param array<string, mixed>                $combi
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m02(array $p, array &$combi): array
    {
        // Frequence des references : une cle trop portee ne prouve rien.
        $freq = [];
        foreach ($p as $l) {
            $r = $this->reference($l);
            if (null !== $r) {
                $freq[$r] = ($freq[$r] ?? 0) + 1;
            }
        }

        // Union de proche en proche sur les references retenues.
        $parent = [];
        $trouver = function (string $x) use (&$parent, &$trouver): string {
            return $parent[$x] === $x ? $x : $parent[$x] = $trouver($parent[$x]);
        };
        foreach (array_keys($p) as $id) {
            $parent[$id] = $id;
        }
        $parRef = [];
        foreach ($p as $id => $l) {
            $r = $this->reference($l);
            if (null === $r || ($freq[$r] ?? 0) > self::CLE_FREQUENCE_MAX) {
                continue;
            }
            $parRef[$r][] = (string) $id;
        }
        foreach ($parRef as $ids) {
            for ($i = 1, $n = \count($ids); $i < $n; ++$i) {
                $a = $trouver($ids[0]);
                $b = $trouver($ids[$i]);
                if ($a !== $b) {
                    $parent[$b] = $a;
                }
            }
        }

        $blocs = [];
        foreach (array_keys($p) as $id) {
            $blocs[$trouver((string) $id)][] = (string) $id;
        }

        $groupes = [];
        foreach ($blocs as $ids) {
            if (\count($ids) < 2 || \count($ids) > self::GROUPE_TAILLE_MAX) {
                continue;
            }
            $bloc = array_intersect_key($p, array_flip($ids));
            $g = $this->equilibrerGroupe($bloc, $combi, 'référence partagée, groupe transitif');
            if (null !== $g) {
                $groupes[] = $g;
            }
        }

        return $groupes;
    }

    /** M03, M04, M05 — une cle sectorielle unique. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function parCle(array $p, string $champ, string $code): array
    {
        $groupes = [];
        foreach ($this->paires($p) as [$d, $c]) {
            $v = $this->valeurCle($d, $champ);
            if (null === $v || $v !== $this->valeurCle($c, $champ)) {
                continue;
            }
            if ($this->contradiction($d, $c)) {
                continue;
            }
            $g = $this->formerPaire($d, $c, $this->libelleCle($champ).' commun');
            if (null !== $g) {
                $g['indices'] = [$champ => $v];
                $groupes[] = $g;
            }
        }

        // Puis le PLUSIEURS contre PLUSIEURS sur la meme cle. M01 ne traite que
        // le un contre un : ce qui reste, ce sont les dossiers ou plusieurs
        // factures et plusieurs reglements portent le meme vehicule. Sans cette
        // seconde passe, ces methodes ne trouveraient jamais rien apres M01, et
        // le catalogue afficherait trois methodes oisives sans raison.
        $parValeur = [];
        foreach ($p as $id => $l) {
            $v = $this->valeurCle($l, $champ);
            if (null !== $v) {
                $parValeur[$v][(string) $id] = $l;
            }
        }
        foreach ($parValeur as $v => $bloc) {
            if (\count($bloc) < 3) {
                continue;
            }
            $sens = array_unique(array_column($bloc, 'sens'));
            if (\count($sens) < 2) {
                continue;
            }
            $solde = $this->solde($bloc);
            $tol = Tolerances::pour(array_column($bloc, 'date_ecriture'), true);
            if (!Tolerances::equilibre(abs($solde), 0.0, $tol['euros'], true)) {
                continue;
            }
            $groupes[] = [
                'lignes' => array_keys($bloc),
                'motif' => sprintf('%d écritures partagent le même %s', \count($bloc), $this->libelleCle($champ)),
                'solde' => $solde,
                'tolerance' => $tol,
                'indices' => [$champ => (string) $v],
            ];
        }

        return $groupes;
    }

    /** M06 — sous-ensembles equilibres dans un meme compte client. */
    /**
     * @param array<string, mixed>                $combi
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m06(array $p, array &$combi): array
    {
        $debits = array_filter($p, static fn (array $l): bool => 'D' === $l['sens']);
        $credits = array_filter($p, static fn (array $l): bool => 'C' === $l['sens']);
        if ([] === $debits || [] === $credits) {
            return [];
        }

        $groupes = [];
        // Un credit global contre plusieurs debits, puis l'inverse.
        foreach ([[$credits, $debits], [$debits, $credits]] as [$pivots, $matiere]) {
            foreach ($pivots as $pivotId => $pivot) {
                if ($pivot['cents'] <= 0) {
                    continue;
                }
                $items = [];
                foreach ($matiere as $id => $l) {
                    $items[] = ['id' => (string) $id, 'cents' => $l['cents']];
                }
                // La recherche combinatoire est EXACTE, sans tolerance.
                //
                // C'est le point le plus important de la methode, et il a coute
                // un lettrage faux. Une tolerance sur une somme que la recherche
                // choisit elle-meme n'est plus une tolerance economique : c'est
                // une permission d'assembler. Avec cinq euros de jeu et dix
                // lignes candidates, il se trouve toujours un sous-ensemble qui
                // « tombe juste » -- ici un credit etranger au dossier de
                // 4 190,70 EUR contre trois factures faisant 4 189,56 EUR.
                //
                // Un ecart economique se tolere sur un rapprochement DETERMINE
                // par une cle : c'est M01. Il ne se tolere pas sur un groupe que
                // le moteur a compose lui-meme.
                $r = Combinaisons::chercher($items, $pivot['cents'], 0);
                $combi['iterations'] += $r['iterations'];
                $combi['ms'] += $r['ms'];
                $combi['solutions'] += \count($r['solutions']);
                $combi['tronquee'] = $combi['tronquee'] || $r['tronquee'];

                if ([] === $r['solutions']) {
                    continue;
                }
                $retenue = $r['solutions'][0];
                if (\count($retenue) < 2) {
                    continue;
                }
                $lot = array_intersect_key($p, array_flip(array_merge([$pivotId], $retenue)));

                // Garde-fou fort : si les deux cotes portent des numeros de
                // serie, leur concordance devient obligatoire.
                if ($this->contradictionGroupe($lot)) {
                    continue;
                }

                // Et la reciproque, qui manquait : si le sous-ensemble retenu
                // porte un numero de serie, le pivot doit porter LE MEME. Un
                // pivot muet en face de trois factures qui designent toutes le
                // meme vehicule n'est pas une preuve d'appartenance, c'est une
                // coincidence arithmetique. Le controle precedent laissait
                // passer ce cas, puisqu'un seul numero de serie distinct
                // figurait dans le groupe.
                $seriesSousEnsemble = [];
                foreach ($retenue as $idLigne) {
                    $v = $this->valeurCle($p[$idLigne], 'vin8');
                    if (null !== $v) {
                        $seriesSousEnsemble[$v] = true;
                    }
                }
                if ([] !== $seriesSousEnsemble
                    && $this->valeurCle($pivot, 'vin8') !== array_key_first($seriesSousEnsemble)) {
                    continue;
                }
                $groupes[] = [
                    'lignes' => array_keys($lot),
                    'motif' => sprintf('%d écritures composent exactement %s €',
                        \count($retenue), number_format((float) $pivot['montant'], 2, ',', ' ')),
                    'solde' => $this->solde($lot),
                    'solutions' => \count($r['solutions']),
                    'iterations' => $r['iterations'],
                    'ms' => $r['ms'],
                    'ambigu' => \count($r['solutions']) > 1,
                ];
            }
        }

        return $groupes;
    }

    /** M07, M19 — positions de liaison entre societes ou etablissements. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function liaison(array $p, string $code, string $champ): array
    {
        $liaison = array_filter($p, static fn (array $l): bool => \in_array($l['compte'], ['4118000', '4018000', '4679999'], true));
        if (\count($liaison) < 2) {
            return [];
        }
        $valeurs = array_unique(array_column($liaison, $champ));
        // M07 exige au moins deux societes ; M19 au moins deux etablissements.
        if (\count($valeurs) < 2) {
            return [];
        }
        $solde = $this->solde($liaison);
        if (abs($solde) > Tolerances::LIAISON) {
            return [];
        }

        return [[
            'lignes' => array_keys($liaison),
            'motif' => sprintf('solde net de %s € sur %d %s',
                number_format(abs($solde), 2, ',', ' '), \count($valeurs),
                'societe_id' === $champ ? 'sociétés' : 'établissements'),
            'solde' => $solde,
        ]];
    }

    /** M08 — apurement par bareme de retard, sinon appariement partiel le plus serre. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m08(array $p): array
    {
        if ([] === $p) {
            return [];
        }
        $solde = $this->solde($p);
        // L'apurement se juge sur la DORMANCE du compte, pas sur l'age de sa
        // plus vieille ligne : un compte qui bouge encore n'a pas droit a la
        // tolerance large de son passe.
        $tol = Tolerances::dormance(array_column($p, 'date_ecriture'));
        if (abs($solde) <= $tol['euros'] && abs($solde) > 0.0 && $this->apurementLegitime($p)) {
            return [[
                'lignes' => array_keys($p),
                'motif' => sprintf('solde de %s € apuré, tolérance %s € (%s)',
                    number_format(abs($solde), 2, ',', ' '),
                    number_format($tol['euros'], 0, ',', ' '), $tol['palier']),
                'solde' => $solde,
                'apurement' => true,
                'tolerance' => $tol,
            ]];
        }

        // Sinon : la paire la plus serree du meme compte comptable. Deux
        // garde-fous, tous deux appris a nos frais.
        //
        // 1. L'ecart se juge sur la ligne la plus RECENTE de la paire. Prendre
        //    la plus ancienne donnait a une facture de vingt jours la tolerance
        //    d'un vieux credit dormant du meme compte, et le moteur appariait
        //    a quatre-vingt-huit euros pres ce qu'il devait signaler a cinq.
        //
        // 2. La paire retenue doit etre NETTEMENT plus serree que la suivante.
        //    Sans cette marge, le moteur choisit entre deux contreparties
        //    presque aussi proches, c'est-a-dire au hasard, et un lettrage tire
        //    au hasard est faux la moitie du temps meme quand le solde tombe.
        $ecarts = [];
        foreach ($this->paires($p) as [$d, $c]) {
            if ($d['compte'] !== $c['compte']) {
                continue;
            }
            // La regle de doctrine s'applique ICI aussi. Ce repli ne passe pas
            // par formerPaire -- il construit son groupe lui-meme -- et c'est
            // par cette porte que la coincidence arithmetique est revenue.
            if (!$this->paireAdmissible($d, $c)) {
                continue;
            }
            $ecarts[] = ['ecart' => abs($d['cents'] - $c['cents']), 'd' => $d, 'c' => $c];
        }
        if ([] === $ecarts) {
            return [];
        }
        usort($ecarts, static fn (array $a, array $b): int => $a['ecart'] <=> $b['ecart']);
        $meilleure = $ecarts[0];

        $tolPaire = Tolerances::dormance([
            (string) $meilleure['d']['date_ecriture'], (string) $meilleure['c']['date_ecriture'],
        ]);
        if (!Tolerances::equilibre(
            (float) $meilleure['d']['montant'], (float) $meilleure['c']['montant'], $tolPaire['euros'])) {
            return [];
        }
        if (isset($ecarts[1]) && $ecarts[1]['ecart'] <= $meilleure['ecart'] * 3 + 100) {
            return [];
        }

        return [[
            'lignes' => [(string) $meilleure['d']['id'], (string) $meilleure['c']['id']],
            'motif' => sprintf(
                'paire la plus serrée du compte, écart %s €, tolérance %s € (compte actif depuis %d jours)',
                number_format($meilleure['ecart'] / 100, 2, ',', ' '),
                number_format($tolPaire['euros'], 0, ',', ' '), $tolPaire['jours']),
            'solde' => round(((float) $meilleure['d']['montant'] - (float) $meilleure['c']['montant']) * 100) / 100,
            'tolerance' => $tolPaire,
        ]];
    }

    /** M09 — acomptes dormants. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m09(array $p): array
    {
        if ([] === $p) {
            return [];
        }
        $soldeC = (int) round($this->solde($p) * 100);
        if (!\in_array(abs($soldeC), self::ACOMPTES, true)) {
            return [];
        }
        $age = Tolerances::dormance(array_column($p, 'date_ecriture'))['jours'];
        if ($age < 120) {
            return [];
        }

        return [[
            'lignes' => array_keys($p),
            'motif' => sprintf('solde égal à un acompte connu de %s €, dormant depuis %d jours',
                number_format(abs($soldeC) / 100, 2, ',', ' '), $age),
            'solde' => $soldeC / 100,
            'apurement' => true,
        ]];
    }

    /** M10, M12 — soldes opposes entre comptes comptables du meme client. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function soldesOpposes(array $p, string $code): array
    {
        $parCompte = [];
        foreach ($p as $id => $l) {
            $parCompte[(string) $l['compte']][(string) $id] = $l;
        }
        if (\count($parCompte) < 2) {
            return [];
        }
        $tolerance = 'M10' === $code ? 2.0 : 5.0;
        $comptes = array_keys($parCompte);
        $groupes = [];
        for ($i = 0; $i < \count($comptes); ++$i) {
            for ($j = $i + 1; $j < \count($comptes); ++$j) {
                $a = $parCompte[$comptes[$i]];
                $b = $parCompte[$comptes[$j]];
                $sa = $this->solde($a);
                $sb = $this->solde($b);
                if (abs($sa) < 0.01 || abs($sb) < 0.01) {
                    continue;
                }
                if (($sa > 0) === ($sb > 0)) {
                    continue;
                }
                if (abs($sa + $sb) > $tolerance) {
                    continue;
                }
                $lot = $a + $b;
                // Un renforcement par cle ou par prefixe de code est obligatoire.
                if (!$this->renforcement($a, $b)) {
                    continue;
                }
                $groupes[] = [
                    'lignes' => array_keys($lot),
                    'motif' => sprintf('deux comptes à soldes opposés, écart net %s €',
                        number_format(abs($sa + $sb), 2, ',', ' ')),
                    'solde' => $sa + $sb,
                ];
            }
        }

        return $groupes;
    }

    /** M17 — ecritures de plus de deux ans, ecart proportionnellement coherent. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m17(array $p): array
    {
        if ([] === $p) {
            return [];
        }
        $age = Tolerances::anciennete(array_column($p, 'date_ecriture'));
        if ($age <= 730) {
            return [];
        }
        $solde = $this->solde($p);
        $masse = array_sum(array_map(static fn (array $l): float => abs((float) $l['montant']), $p));
        if (abs($solde) < 0.01 || $masse <= 0.0) {
            return [];
        }
        if (abs($solde) > 1000.0 || abs($solde) / $masse > 0.20) {
            return [];
        }

        return [[
            'lignes' => array_keys($p),
            'motif' => sprintf('lignes de %d jours, solde résiduel %s € soit %.1f %% de la masse',
                $age, number_format(abs($solde), 2, ',', ' '), abs($solde) / $masse * 100),
            'solde' => $solde,
            'apurement' => true,
        ]];
    }

    /** M18 — comptes clients fermes. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m18(array $p): array
    {
        $ferme = array_filter($p, static fn (array $l): bool => str_starts_with((string) ($l['code_client'] ?? ''), 'ex-'));

        return [] === $ferme ? [] : [[
            'lignes' => array_keys($ferme),
            'motif' => 'compte client fermé, transfert intégral',
            'solde' => $this->solde($ferme),
            'apurement' => true,
        ]];
    }

    /** M20 — nom de client unique dans la societe. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m20(array $p): array
    {
        if (\count($p) < 2) {
            return [];
        }
        $noms = array_unique(array_filter(array_column($p, 'client_nom')));
        if (1 !== \count($noms)) {
            return [];
        }
        $solde = $this->solde($p);
        $tol = Tolerances::pour(array_column($p, 'date_ecriture'), true);
        if (!Tolerances::equilibre(abs($solde), 0.0, $tol['euros'], true)) {
            return [];
        }

        return [[
            'lignes' => array_keys($p),
            'motif' => sprintf('nom de client unique dans la société, solde %s €',
                number_format(abs($solde), 2, ',', ' ')),
            'solde' => $solde,
        ]];
    }

    /** M21 — un paiement orphelin, une seule facture candidate. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m21(array $p): array
    {
        $credits = array_filter($p, static fn (array $l): bool => 'C' === $l['sens']);
        $debits = array_filter($p, static fn (array $l): bool => 'D' === $l['sens']);
        if (1 !== \count($credits) || 1 !== \count($debits)) {
            return [];
        }
        $c = reset($credits);
        $d = reset($debits);
        $g = $this->formerPaire($d, $c, 'un seul paiement, une seule facture candidate');

        return null === $g ? [] : [$g];
    }

    /** M22 — solde client nul. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m22(array $p): array
    {
        if (\count($p) < 2) {
            return [];
        }
        if (abs($this->solde($p)) >= 0.01) {
            return [];
        }
        $attente = array_filter($p, fn (array $l): bool => \in_array($l['compte'], self::COMPTES_ATTENTE, true));
        if ([] !== $attente) {
            return [];
        }

        return [[
            'lignes' => array_keys($p),
            'motif' => 'solde net du compte client strictement nul',
            'solde' => 0.0,
        ]];
    }

    /** M23 — garanties constructeur. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m23(array $p): array
    {
        $garantie = array_filter($p, static fn (array $l): bool => '4116000' === $l['compte']);
        if (\count($garantie) < 2) {
            return [];
        }
        $groupes = [];
        foreach ($this->paires($garantie) as [$d, $c]) {
            $cle = $this->valeurCle($d, 'ordre_reparation') ?? $this->valeurCle($d, 'vin8');
            if (null === $cle) {
                continue;
            }
            $ecart = abs((float) $d['montant'] - (float) $c['montant']);
            // Au-dela du seuil, ce n'est plus un lettrage : c'est une anomalie.
            if ($ecart > Tolerances::GARANTIE_ANOMALIE) {
                continue;
            }
            $groupes[] = [
                'lignes' => [(string) $d['id'], (string) $c['id']],
                'motif' => sprintf('garantie appariée par clé véhicule, écart %s € traité en écriture d\'écart',
                    number_format($ecart, 2, ',', ' ')),
                'solde' => (float) $d['montant'] - (float) $c['montant'],
            ];
        }

        return $groupes;
    }

    /** M24 — fusion des faibles residus. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m24(array $p): array
    {
        if ([] === $p) {
            return [];
        }
        $attente = array_filter($p, fn (array $l): bool => \in_array($l['compte'], self::COMPTES_ATTENTE, true));
        if ([] !== $attente) {
            return [];
        }
        $solde = abs($this->solde($p));
        if ($solde < 0.01) {
            return [];
        }
        if (!$this->apurementLegitime($p)) {
            return [];
        }
        $age = Tolerances::dormance(array_column($p, 'date_ecriture'))['jours'];
        $plafond = 500.0;
        foreach (Tolerances::APUREMENT as $palier) {
            if (null === $palier['jours'] || $age < $palier['jours']) {
                $plafond = $palier['euros'];
                break;
            }
        }
        if ($solde > $plafond) {
            return [];
        }

        return [[
            'lignes' => array_keys($p),
            'motif' => sprintf('résidu de %s € fusionné, barème %s € à %d jours',
                number_format($solde, 2, ',', ' '), number_format($plafond, 0, ',', ' '), $age),
            'solde' => $this->solde($p),
            'apurement' => true,
        ]];
    }

    /** M26 — dossier comptant qui solde exactement. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m26(array $p): array
    {
        if (\count($p) < 2 || abs($this->solde($p)) >= 0.01) {
            return [];
        }

        return [[
            'lignes' => array_keys($p),
            'motif' => 'dossier comptant soldé exactement',
            'solde' => 0.0,
        ]];
    }

    /** M11 — reclassement financeur, en dernier et mono-ligne. */
    /**
     * @param array<string, array<string, mixed>> $p
     *
     * @return list<array<string, mixed>>
     */
    private function m11(array $p): array
    {
        $groupes = [];
        foreach ($p as $id => $l) {
            if ('4012000' !== $l['compte']) {
                continue;
            }
            if (Tolerances::anciennete([(string) $l['date_ecriture']]) < 10) {
                continue;
            }
            $groupes[] = [
                'lignes' => [(string) $id],
                'motif' => 'ligne de financement reclassée vers le compte fournisseur ; la jambe reste ouverte',
                'solde' => (float) $l['montant'],
                'mono' => true,
            ];
        }

        return $groupes;
    }

    /**
     * Un apurement global est-il legitime sur ce compte ?
     *
     * La regle, posee par l'auteur le 07/09/2026 : une tolerance d'anciennete
     * permet d'accepter un ECART ECONOMIQUE FAIBLE sur un rapprochement
     * determine. Elle ne doit jamais servir a choisir entre plusieurs
     * rapprochements plausibles. Tolerance plus ambiguite font un cas humain,
     * pas une permission d'apurer.
     *
     * Le defaut etait exactement celui-la : un compte portant deux dossiers
     * distincts -- une facture et son reglement a huit euros pres, une autre
     * facture et son reglement a douze euros pres -- presentait un solde NET de
     * quatre euros. L'apurement global soldait les quatre lignes d'un coup,
     * melangeait les deux dossiers, et le douze euros que la tolerance de vingt
     * jours refusait passait par la fenetre.
     *
     * Le test est simple et se suffit a lui-meme : si un sous-ensemble strict du
     * compte s'equilibre deja de son cote, alors le compte porte des dossiers
     * distincts. Ce n'est plus un residu a apurer, c'est un rapprochement a
     * faire -- et il appartient aux methodes a cle, ou a un humain.
     *
     * @param array<string, array<string, mixed>> $p
     */
    private function apurementLegitime(array $p): bool
    {
        if (\count($p) < 3) {
            return true;
        }
        foreach ($this->paires($p) as [$d, $c]) {
            $tol = Tolerances::dormance([
                (string) $d['date_ecriture'], (string) $c['date_ecriture'],
            ]);
            if (Tolerances::equilibre((float) $d['montant'], (float) $c['montant'], $tol['euros'])) {
                return false;
            }
        }

        return true;
    }

    // ================================================================ outillage

    /**
     * Toutes les paires debit / credit du pool.
     *
     * @param array<string, array<string, mixed>> $p
     *
     * @return Generator<int, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function paires(array $p): Generator
    {
        $debits = array_filter($p, static fn (array $l): bool => 'D' === $l['sens']);
        $credits = array_filter($p, static fn (array $l): bool => 'C' === $l['sens']);
        foreach ($debits as $d) {
            foreach ($credits as $c) {
                yield [$d, $c];
            }
        }
    }

    /**
     * Forme une paire si l'ecart tient dans le bareme de son anciennete.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed>|null
     */
    private function formerPaire(array $d, array $c, string $motif): ?array
    {
        // Regle de doctrine, commune a TOUTES les methodes qui apparient deux
        // lignes. Une paire n'est admissible que dans deux situations :
        //
        //   a) les deux lignes partagent au moins une cle sectorielle ;
        //   b) AUCUNE des deux ne porte de cle exploitable.
        //
        // Le cas interdit est celui du milieu : une facture qui designe un
        // vehicule, en face d'un reglement qui ne designe rien. Le montant
        // voisin n'y fait rien -- c'est une coincidence arithmetique, et elle
        // s'est produite. Un debit de 2 502,16 EUR portant un numero de serie a
        // ete apparie a un credit muet de 2 507,07 EUR, quatre euros et
        // quatre-vingt-onze centimes plus loin, alors que le vrai reglement du
        // dossier attendait ailleurs dans le meme compte.
        //
        // Dire « aucune cle des deux cotes » n'est pas une faiblesse : dans ce
        // cas, ce sont les autres conditions de la methode -- solde nul, dossier
        // comptant, compte d'attente dormant -- qui portent la decision, et
        // elles sont explicites.
        if (!$this->paireAdmissible($d, $c)) {
            return null;
        }

        $tol = Tolerances::pour([(string) $d['date_ecriture'], (string) $c['date_ecriture']]);
        if (!Tolerances::equilibre((float) $d['montant'], (float) $c['montant'], $tol['euros'])) {
            return null;
        }

        return [
            'lignes' => [(string) $d['id'], (string) $c['id']],
            'motif' => $motif,
            'solde' => round(((float) $d['montant'] - (float) $c['montant']) * 100) / 100,
            'tolerance' => $tol,
        ];
    }

    /**
     * Equilibre un bloc transitif par recherche de sous-ensemble.
     *
     * @param array<string, array<string, mixed>> $bloc
     * @param array<string, mixed>                $combi
     *
     * @return array<string, mixed>|null
     */
    private function equilibrerGroupe(array $bloc, array &$combi, string $motif): ?array
    {
        $solde = $this->solde($bloc);
        $tol = Tolerances::pour(array_column($bloc, 'date_ecriture'), true);
        if (abs($solde) <= $tol['euros']) {
            return [
                'lignes' => array_keys($bloc),
                'motif' => $motif,
                'solde' => $solde,
                'tolerance' => $tol,
            ];
        }

        return null;
    }

    /**
     * Le solde signe d'un lot : debits moins credits.
     *
     * @param array<array-key, array<string, mixed>> $lot
     */
    private function solde(array $lot): float
    {
        $c = 0;
        foreach ($lot as $l) {
            $c += ('D' === $l['sens'] ? 1 : -1) * (int) round((float) $l['montant'] * 100);
        }

        return $c / 100;
    }

    /**
     * Les cles fortes communes a deux lignes.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $c
     *
     * @return array<string, string>
     */
    private function clesCommunes(array $d, array $c): array
    {
        $cles = [];
        foreach (['reference_piece', 'vin8', 'immatriculation', 'ordre_reparation'] as $champ) {
            $v = $this->valeurCle($d, $champ);
            if (null !== $v && $v === $this->valeurCle($c, $champ)) {
                $cles[$champ] = $v;
            }
        }

        return $cles;
    }

    /**
     * Une paire de lignes est-elle admissible au rapprochement ?
     *
     * Deux situations, et deux seulement :
     *
     *   a) les deux lignes partagent au moins une cle sectorielle ;
     *   b) AUCUNE des deux ne porte de cle exploitable.
     *
     * Le cas interdit est celui du milieu : une facture qui designe un vehicule,
     * en face d'un reglement qui ne designe rien. Le montant voisin n'y fait
     * rien -- c'est une coincidence arithmetique, et elle s'est produite deux
     * fois. Un debit de 2 502,16 EUR portant un numero de serie a ete apparie a
     * un credit muet de 2 507,07 EUR, quatre euros et quatre-vingt-onze centimes
     * plus loin, alors que le vrai reglement du dossier attendait ailleurs dans
     * le meme compte.
     *
     * Dire « aucune cle des deux cotes » n'est pas une faiblesse : dans ce cas,
     * ce sont les autres conditions de la methode -- solde nul, dossier
     * comptant, compte d'attente dormant -- qui portent la decision, et elles
     * sont explicites.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $c
     */
    private function paireAdmissible(array $d, array $c): bool
    {
        if ([] !== $this->clesCommunes($d, $c)) {
            return true;
        }

        return [] === $this->clesPortees($d) && [] === $this->clesPortees($c);
    }

    /**
     * Les cles sectorielles exploitables portees par une ligne.
     *
     * @param array<string, mixed> $l
     *
     * @return array<string, string>
     */
    private function clesPortees(array $l): array
    {
        $cles = [];
        foreach (['reference_piece', 'vin8', 'immatriculation', 'ordre_reparation'] as $champ) {
            $v = $this->valeurCle($l, $champ);
            if (null !== $v) {
                $cles[$champ] = $v;
            }
        }

        return $cles;
    }

    /**
     * Une valeur de cle exploitable, ou rien.
     *
     * @param array<string, mixed> $l
     */
    private function valeurCle(array $l, string $champ): ?string
    {
        $v = $l[$champ] ?? null;
        if (null === $v || '' === $v) {
            return null;
        }
        $v = strtoupper(trim((string) $v));
        if ('reference_piece' === $champ && \in_array($v, self::REFERENCES_GENERIQUES, true)) {
            return null;
        }
        if ('ordre_reparation' === $champ && \in_array(ltrim($v, '0'), ['', '0'], true)) {
            return null;
        }
        if (\strlen($v) < 4) {
            return null;
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $l
     */
    private function reference(array $l): ?string
    {
        return $this->valeurCle($l, 'reference_piece');
    }

    private function libelleCle(string $champ): string
    {
        return [
            'vin8' => 'numéro de série',
            'immatriculation' => 'immatriculation',
            'ordre_reparation' => "numéro d'ordre de réparation",
            'reference_piece' => 'référence de pièce',
        ][$champ] ?? $champ;
    }

    /**
     * Contradiction sur une cle sectorielle forte.
     *
     * Deux numeros de serie RENSEIGNES et DIFFERENTS de part et d'autre : ce
     * n'est pas une absence de preuve, c'est une preuve du contraire. Aucune
     * ressemblance de montant ne la rattrape.
     */
    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $c
     */
    private function contradiction(array $d, array $c): bool
    {
        foreach (['vin8', 'immatriculation'] as $champ) {
            $a = $this->valeurCle($d, $champ);
            $b = $this->valeurCle($c, $champ);
            if (null !== $a && null !== $b && $a !== $b) {
                return true;
            }
        }

        return false;
    }

    /**
     * Garde-fou de groupe : si les deux cotes portent des series, elles doivent concorder.
     *
     * @param array<array-key, array<string, mixed>> $lot
     */
    private function contradictionGroupe(array $lot): bool
    {
        $series = [];
        foreach ($lot as $l) {
            $v = $this->valeurCle($l, 'vin8');
            if (null !== $v) {
                $series[$v] = true;
            }
        }

        return \count($series) > 1;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $c
     */
    private function memeTiers(array $d, array $c): bool
    {
        return ($d['client_id'] ?? null) === ($c['client_id'] ?? null);
    }

    /**
     * Un renforcement par cle ou par prefixe de code, exige par M10 et M12.
     *
     * @param array<string, array<string, mixed>> $a
     * @param array<string, array<string, mixed>> $b
     */
    private function renforcement(array $a, array $b): bool
    {
        foreach ($a as $la) {
            foreach ($b as $lb) {
                if ([] !== $this->clesCommunes($la, $lb) || [] !== $this->clesCommunes($lb, $la)) {
                    return true;
                }
            }
        }
        $codeA = substr((string) (reset($a)['code_client'] ?? ''), 0, 4);
        $codeB = substr((string) (reset($b)['code_client'] ?? ''), 0, 4);

        return '' !== $codeA && $codeA === $codeB;
    }
}
