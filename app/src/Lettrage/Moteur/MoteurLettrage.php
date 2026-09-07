<?php

declare(strict_types=1);

namespace App\Lettrage\Moteur;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Le moteur de lettrage : ce qu'un reglement solde exactement.
 *
 * L'outil 4 repond a « a qui appartient cet argent ». Celui-ci repond a la
 * question suivante, et c'est la seule qui produise une ecriture : « quelles
 * creances ce reglement solde-t-il ».
 *
 * La doctrine est celle validee pour l'affectation, transposee : la precision
 * prime sur la couverture. Quatre arrets passent AVANT tout seuil.
 *
 *   1. le groupe ne solde pas dans son bareme      -> refus
 *   2. une cle sectorielle forte se contredit      -> humain
 *   3. deux sous-ensembles equilibrent le total    -> humain
 *   4. aucune methode ne revendique le lot         -> refus
 *
 * Le moteur ne lit JAMAIS le schema `lettrage_verite`.
 */
final class MoteurLettrage
{
    /** Nombre d'indices concordants exige quand aucune cle ne suffit seule. */
    public const CONVERGENCE_MINIMALE = 3;

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Analyse un lot : la cascade est REJOUEE, et chronometree.
     *
     * @return array<string, mixed>
     */
    public function analyser(string $lotId): array
    {
        $t = microtime(true);
        $lot = $this->cnx->fetchAssociative('SELECT * FROM lettrage.lot WHERE id = ?', [$lotId]);
        if (false === $lot) {
            throw new InvalidArgumentException("Lot inconnu : {$lotId}");
        }

        $attendues = $this->cnx->fetchAllAssociative(
            'SELECT * FROM lettrage.ecriture WHERE lot_demo = ? ORDER BY sens DESC, date_ecriture', [$lotId]);

        // Le pool que le moteur examine : toutes les ecritures non lettrees du
        // meme compte client. Il ne sait pas lesquelles forment le lot.
        $pool = $this->cnx->fetchAllAssociative(
            'SELECT e.*, c.nom AS client_nom,
                    coalesce(e.code_client_demo, c.code_balance) AS code_client
               FROM lettrage.ecriture e
               LEFT JOIN affectation.client c ON c.id = e.client_id
              WHERE e.client_id = ? AND e.lettrage IS NULL
              ORDER BY e.date_ecriture LIMIT 40',
            [$lot['client_id']]);

        $cascade = (new Cascade())->executer($pool);

        // Quel groupe revendique l'ancre du lot ?
        $ancre = (string) $lot['ancre_id'];
        $retenu = null;
        foreach ($cascade['groupes'] as $g) {
            if (\in_array($ancre, $g['lignes'], true)) {
                $retenu = $g;
                break;
            }
        }

        $indices = $this->indices($attendues);

        // Le moteur verifie LUI-MEME que la composition retenue est unique,
        // quelle que soit la methode qui l'a formee.
        $alternatives = null === $retenu ? 1 : $this->compositionsPossibles($retenu, $pool);

        $verdict = $this->decider($retenu, $attendues, $indices, $alternatives);

        $parId = [];
        foreach ($pool as $l) {
            $parId[(string) $l['id']] = $l;
        }

        return [
            'lot' => $lot,
            'ecritures' => $attendues,
            'pool' => $pool,
            'cascade' => $cascade,
            'retenu' => $retenu,
            'groupe' => null === $retenu ? [] : array_values(array_intersect_key($parId, array_flip($retenu['lignes']))),
            'indices' => $indices,
            'verdict' => $verdict['verdict'],
            'motif' => $verdict['motif'],
            'arret' => $verdict['arret'],
            'methode' => $retenu['methode'] ?? null,
            'solde_avant' => $this->solde($attendues),
            'compositions_possibles' => $alternatives,
            'chrono' => ['total' => (microtime(true) - $t) * 1000] + $cascade['chrono'],
        ];
    }

    /**
     * Les indices concordants d'un lot, un par un.
     *
     * C'est le message central du moteur : aucune preuve ne suffit isolement,
     * et pourtant cinq indices qui convergent emportent la conviction. On les
     * enumere donc, plutot que de rendre un score opaque.
     *
     * @param list<array<string, mixed>> $ecritures
     *
     * @return list<array{cle: string, libelle: string, constat: string, fort: bool}>
     */
    public function indices(array $ecritures): array
    {
        $debits = array_filter($ecritures, static fn (array $l): bool => 'D' === $l['sens']);
        $credits = array_filter($ecritures, static fn (array $l): bool => 'C' === $l['sens']);
        if ([] === $debits || [] === $credits) {
            return [];
        }
        $d = reset($debits);
        $c = reset($credits);
        $indices = [];

        $poser = function (string $cle, string $libelle, string $constat, bool $fort) use (&$indices): void {
            $indices[] = ['cle' => $cle, 'libelle' => $libelle, 'constat' => $constat, 'fort' => $fort];
        };

        $egal = static function (?string $a, ?string $b): bool {
            return null !== $a && '' !== $a && $a === $b;
        };

        if ($egal($d['reference_piece'] ?? null, $c['reference_piece'] ?? null)) {
            $poser('reference', 'Référence de pièce identique', (string) $d['reference_piece'], true);
        }
        if ($egal($d['vin8'] ?? null, $c['vin8'] ?? null)) {
            $poser('vin', 'Numéro de série concordant', (string) $d['vin8'], true);
        }
        if ($egal($d['immatriculation'] ?? null, $c['immatriculation'] ?? null)) {
            $poser('immatriculation', 'Immatriculation concordante', (string) $d['immatriculation'], false);
        }
        if ($egal($d['ordre_reparation'] ?? null, $c['ordre_reparation'] ?? null)) {
            $poser('ordre', "Numéro d'ordre de réparation concordant", (string) $d['ordre_reparation'], false);
        }
        if (($d['etablissement_id'] ?? null) === ($c['etablissement_id'] ?? null)) {
            $poser('etablissement', 'Même établissement', (string) $d['etablissement_id'], false);
        }

        $debit = $this->somme($debits);
        $credit = $this->somme($credits);
        $ecart = round(abs($debit - $credit) * 100) / 100;
        $tol = Tolerances::pour(array_column($ecritures, 'date_ecriture'), \count($ecritures) > 2);
        if ($ecart < 0.01) {
            $poser('montant', 'Montant identique', number_format($debit, 2, ',', ' ').' €', true);
        } elseif ($ecart <= $tol['euros']) {
            $poser('montant', 'Écart dans la tolérance de son ancienneté',
                sprintf('%s € d\'écart, tolérance %s € (%s, %d jours)',
                    number_format($ecart, 2, ',', ' '), number_format($tol['euros'], 0, ',', ' '),
                    $tol['palier'], $tol['jours']), false);
        }

        $jours = abs((new DateTimeImmutable((string) $d['date_ecriture']))
            ->diff(new DateTimeImmutable((string) $c['date_ecriture']))->days);
        if ($jours <= 60) {
            $poser('dates', 'Dates cohérentes', sprintf('%d jours entre la facture et le règlement', $jours), false);
        }

        return $indices;
    }

    /**
     * Le verdict, et ce qui l'arrete.
     *
     * @param array<string, mixed>|null  $retenu
     * @param list<array<string, mixed>> $ecritures
     * @param list<array<string, mixed>> $indices
     *
     * @return array{verdict: string, motif: string, arret: string|null}
     */
    private function decider(?array $retenu, array $ecritures, array $indices, int $alternatives = 1): array
    {
        // ---- ARRET 1 : une cle sectorielle forte se contredit.
        $contradiction = $this->contradiction($ecritures);
        if (null !== $contradiction) {
            return [
                'verdict' => Verdict::HUMAIN,
                'motif' => $contradiction,
                'arret' => 'contradiction',
            ];
        }

        // ---- ARRET 2 : aucune methode ne revendique le lot.
        if (null === $retenu) {
            $solde = round($this->solde($ecritures) * 100) / 100;
            $tol = Tolerances::pour(array_column($ecritures, 'date_ecriture'), \count($ecritures) > 2);
            if (abs($solde) > $tol['euros']) {
                return [
                    'verdict' => Verdict::REFUS,
                    'motif' => sprintf(
                        'Le groupe laisserait un solde résiduel de %s €, au-delà de la tolérance de %s € '
                        .'applicable à %s. Aucun code de lettrage n\'est posé sur un groupe qui ne solde pas.',
                        number_format(abs($solde), 2, ',', ' '),
                        number_format($tol['euros'], 0, ',', ' '), $tol['palier']),
                    'arret' => 'solde',
                ];
            }

            return [
                'verdict' => Verdict::REFUS,
                'motif' => 'Aucune méthode de la cascade ne revendique ces écritures.',
                'arret' => 'aucune_methode',
            ];
        }

        // ---- ARRET 3 : plusieurs sous-ensembles equilibrent le meme total.
        if (true === ($retenu['ambigu'] ?? false) || $alternatives > 1) {
            return [
                'verdict' => Verdict::HUMAIN,
                'motif' => 'Plusieurs sous-ensembles d\'écritures équilibrent exactement ce montant : '
                    .'le compte serait juste au total et faux ligne à ligne. Le choix revient au comptable.',
                'arret' => 'composition_multiple',
            ];
        }

        // ---- ARRET 4 : le groupe forme ne solde pas dans son bareme.
        $solde = round((float) ($retenu['solde'] ?? 0.0) * 100) / 100;
        $tol = $retenu['tolerance'] ?? Tolerances::pour(array_column($ecritures, 'date_ecriture'));
        if (abs($solde) > (float) $tol['euros']) {
            return [
                'verdict' => Verdict::REFUS,
                'motif' => sprintf('Solde résiduel de %s €, tolérance %s €.',
                    number_format(abs($solde), 2, ',', ' '), number_format((float) $tol['euros'], 0, ',', ' ')),
                'arret' => 'solde',
            ];
        }

        // ---- Le lettrage automatique : une cle forte, ou une convergence.
        $forts = \count(array_filter($indices, static fn (array $i): bool => $i['fort']));
        if ($forts >= 2) {
            return [
                'verdict' => Verdict::AUTOMATIQUE,
                'motif' => sprintf('%d preuves fortes concordantes, solde conforme, aucune contradiction.', $forts),
                'arret' => null,
            ];
        }
        if (\count($indices) >= self::CONVERGENCE_MINIMALE) {
            return [
                'verdict' => Verdict::AUTOMATIQUE,
                'motif' => sprintf(
                    'Aucune preuve suffisante isolément. %d indices convergent : le rapprochement tient '
                    .'par leur réunion, pas par l\'un d\'entre eux.', \count($indices)),
                'arret' => null,
            ];
        }

        return [
            'verdict' => Verdict::PROPOSITION,
            'motif' => sprintf('%d indice%s seulement : la proposition est soumise au comptable.',
                \count($indices), \count($indices) > 1 ? 's' : ''),
            'arret' => null,
        ];
    }

    /**
     * Combien de jeux d'ecritures du compte composent le meme total ?
     *
     * On prend la plus grosse ligne du groupe retenu pour pivot, et on cherche
     * dans TOUT le vivier ouvert du compte -- pas seulement dans le groupe --
     * les sous-ensembles de sens oppose qui l'equilibrent. Deux reponses
     * distinctes ne sont pas une performance : ce sont deux ecritures possibles
     * et une seule juste.
     *
     * Le controle vivait dans M06 seule, si bien qu'un groupe forme par une
     * autre methode pouvait etre lettre alors qu'un second jeu d'ecritures
     * composait exactement le meme total.
     *
     * @param array<string, mixed>       $retenu
     * @param list<array<string, mixed>> $pool
     */
    private function compositionsPossibles(array $retenu, array $pool): int
    {
        $parId = [];
        foreach ($pool as $l) {
            $parId[(string) $l['id']] = $l;
        }
        $groupe = array_values(array_intersect_key($parId, array_flip($retenu['lignes'])));
        if (\count($groupe) < 2) {
            return 1;
        }
        usort($groupe, static fn (array $a, array $b): int => (float) $b['montant'] <=> (float) $a['montant']);
        $pivot = $groupe[0];

        $matiere = [];
        foreach ($pool as $l) {
            if ($l['sens'] === $pivot['sens'] || (string) $l['id'] === (string) $pivot['id']) {
                continue;
            }
            $matiere[] = ['id' => (string) $l['id'], 'cents' => (int) round((float) $l['montant'] * 100)];
        }
        if (\count($matiere) < 2) {
            return 1;
        }

        $tol = Tolerances::pour(array_column($groupe, 'date_ecriture'), \count($groupe) > 2);
        $r = Combinaisons::chercher(
            $matiere, (int) round((float) $pivot['montant'] * 100),
            (int) round(min($tol['euros'], 5.0) * 100));

        return max(1, \count($r['solutions']));
    }

    /**
     * Une cle forte renseignee des deux cotes et differente : preuve du contraire.
     *
     * @param list<array<string, mixed>> $ecritures
     */
    private function contradiction(array $ecritures): ?string
    {
        foreach (['vin8' => 'numéro de série', 'immatriculation' => 'immatriculation'] as $champ => $libelle) {
            $valeurs = [];
            foreach ($ecritures as $l) {
                $v = $l[$champ] ?? null;
                if (null !== $v && '' !== $v) {
                    $valeurs[strtoupper((string) $v)] = true;
                }
            }
            if (\count($valeurs) > 1) {
                return sprintf(
                    'Contradiction sur une clé sectorielle forte : %d %ss différents dans le même groupe (%s). '
                    ."Ce n'est pas une absence de preuve, c'est une preuve du contraire — aucune ressemblance "
                    .'de montant ne la rattrape.',
                    \count($valeurs), $libelle, implode(' contre ', array_keys($valeurs)));
            }
        }

        return null;
    }

    /**
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
     * @param array<array-key, array<string, mixed>> $lot
     */
    private function somme(array $lot): float
    {
        $c = 0;
        foreach ($lot as $l) {
            $c += (int) round((float) $l['montant'] * 100);
        }

        return $c / 100;
    }
}
