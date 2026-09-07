<?php

declare(strict_types=1);

namespace App\Lettrage\Command;

use App\Lettrage\Moteur\Cascade;
use App\Lettrage\Moteur\Catalogue;
use App\Lettrage\Moteur\MoteurLettrage;
use App\Lettrage\Moteur\Verdict;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Execute la cascade sur tout le stock d'ecritures non lettrees, puis enregistre
 * ce que chaque methode a consomme et ce que chaque lot est devenu.
 *
 * La cascade est reellement executee, compte client par compte client. Ce qui
 * est stocke n'est pas une estimation : c'est le compte des lignes que chaque
 * methode a effectivement prises, et le residu qui reste apres la derniere.
 */
#[AsCommand(
    name: 'app:lettrage:cascade',
    description: 'Execute la cascade de lettrage sur le stock et enregistre la consommation par methode.',
)]
final class CascadeCommand extends Command
{
    public function __construct(
        private readonly Connection $cnx,
        private readonly MoteurLettrage $moteur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('lots', null, InputOption::VALUE_REQUIRED,
            'Nombre de lots a decider par cohorte. 0 = tous.', '0');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Cascade de lettrage');

        $total = (int) $this->cnx->fetchOne('SELECT count(*) FROM lettrage.ecriture');
        $dejaLettrees = (int) $this->cnx->fetchOne('SELECT count(*) FROM lettrage.ecriture WHERE lettrage IS NOT NULL');
        $stock = $total - $dejaLettrees;
        $io->text(sprintf('%s écritures au journal, dont %s déjà lettrées : la cascade travaille sur %s.',
            number_format($total, 0, ',', ' '), number_format($dejaLettrees, 0, ',', ' '),
            number_format($stock, 0, ',', ' ')));

        // ------------------------------------------------------- la cascade
        $comptes = $this->cnx->fetchFirstColumn(
            'SELECT client_id FROM lettrage.ecriture
              WHERE lettrage IS NULL AND client_id IS NOT NULL
              GROUP BY client_id HAVING count(*) >= 2');

        /** @var array<string, array{rang: int|null, consommees: int, groupes: int, montant: float, ms: float}> $etapes */
        $etapes = [];
        foreach (Catalogue::cascade() as $m) {
            $etapes[(string) $m['code']] = [
                'rang' => $m['rang'], 'consommees' => 0, 'groupes' => 0, 'montant' => 0.0, 'ms' => 0.0,
            ];
        }

        $cascade = new Cascade();
        $barre = $io->createProgressBar(\count($comptes));
        $barre->start();
        $t0 = microtime(true);
        $residuTotal = 0;
        $traitees = 0;
        $n = 0;

        foreach ($comptes as $clientId) {
            $pool = $this->cnx->fetchAllAssociative(
                'SELECT e.id, e.sens, e.montant, e.date_ecriture, e.compte, e.journal,
                        e.societe_id, e.etablissement_id, e.client_id,
                        e.vin8, e.immatriculation, e.ordre_reparation, e.reference_piece,
                        c.nom AS client_nom,
                        coalesce(e.code_client_demo, c.code_balance) AS code_client
                   FROM lettrage.ecriture e
                   LEFT JOIN affectation.client c ON c.id = e.client_id
                  WHERE e.client_id = ? AND e.lettrage IS NULL
                  ORDER BY e.date_ecriture LIMIT 40', [$clientId]);

            $r = $cascade->executer($pool);
            $traitees += \count($pool);
            $residuTotal += \count($r['residu']);

            $parId = [];
            foreach ($pool as $l) {
                $parId[(string) $l['id']] = $l;
            }
            foreach ($r['groupes'] as $g) {
                $code = (string) $g['methode'];
                if (!isset($etapes[$code])) {
                    continue;
                }
                $e = $etapes[$code];
                $e['consommees'] += \count($g['lignes']);
                ++$e['groupes'];
                foreach ($g['lignes'] as $id) {
                    if ('D' === ($parId[$id]['sens'] ?? '')) {
                        $e['montant'] += (float) $parId[$id]['montant'];
                    }
                }
                $etapes[$code] = $e;
            }
            foreach ($r['chrono'] as $code => $ms) {
                $code = (string) $code;
                if (!isset($etapes[$code])) {
                    continue;
                }
                $e = $etapes[$code];
                $e['ms'] += (float) $ms;
                $etapes[$code] = $e;
            }

            if (0 === ++$n % 200) {
                $barre->advance(200);
            }
        }
        $barre->finish();
        $io->newLine(2);
        $secondes = microtime(true) - $t0;

        // Les lignes entrantes de chaque etape : le stock moins ce que les
        // precedentes ont consomme. C'est la definition meme de la cascade.
        $this->cnx->executeStatement('TRUNCATE lettrage.cascade_etape');
        $entrantes = $traitees;
        foreach ($etapes as $code => $e) {
            $this->cnx->executeStatement(
                'INSERT INTO lettrage.cascade_etape
                   (methode, rang, lignes_entrantes, lignes_consommees, groupes, montant, duree_ms)
                 VALUES (?,?,?,?,?,?,?)',
                [$code, $e['rang'], $entrantes, $e['consommees'], $e['groupes'],
                    round($e['montant'], 2), round($e['ms'], 3)]);
            $entrantes -= $e['consommees'];
        }

        $io->section('La cascade, étape par étape');
        $lignes = [];
        $entrantes = $traitees;
        foreach ($etapes as $code => $e) {
            $lignes[] = [$e['rang'], $code, number_format($entrantes, 0, ',', ' '),
                number_format($e['consommees'], 0, ',', ' '), number_format($e['groupes'], 0, ',', ' '),
                number_format($e['montant'], 0, ',', ' ').' €', sprintf('%.0f ms', $e['ms'])];
            $entrantes -= $e['consommees'];
        }
        $io->table(['Rang', 'Méthode', 'Entrantes', 'Consommées', 'Groupes', 'Montant', 'Durée'], $lignes);
        $io->text(sprintf('Résidu après la dernière méthode : <info>%s</info> écritures sur %s traitées, en %.1f s.',
            number_format($residuTotal, 0, ',', ' '), number_format($traitees, 0, ',', ' '), $secondes));

        // ------------------------------------------------ les lots, decides
        $io->section('Décision, lot par lot');
        $parCohorte = (int) $entree->getOption('lots');
        $ids = [];
        foreach (['CALIBRATION_O5', 'VALIDATION_O5', 'BLIND_O5', 'BLIND_O5_2', 'BLIND_O5_3'] as $cohorte) {
            $sql = 'SELECT id FROM lettrage.lot WHERE cohorte = ? ORDER BY id';
            if ($parCohorte > 0) {
                $sql .= ' LIMIT '.$parCohorte;
            }
            $ids = array_merge($ids, $this->cnx->fetchFirstColumn($sql, [$cohorte]));
        }

        $this->cnx->executeStatement('TRUNCATE lettrage.decision');
        $this->cnx->executeStatement('TRUNCATE lettrage.indice');

        $barre = $io->createProgressBar(\count($ids));
        $barre->start();
        $t0 = microtime(true);
        $durees = [];
        $k = 0;
        foreach ($ids as $lotId) {
            $a = $this->moteur->analyser((string) $lotId);
            $this->enregistrer($a);
            $durees[] = $a['chrono']['total'];
            if (0 === ++$k % 50) {
                $barre->advance(50);
            }
        }
        $barre->finish();
        $io->newLine(2);

        sort($durees);
        $io->definitionList(
            ['Lots decides' => number_format(\count($ids), 0, ',', ' ')],
            ['Duree totale' => sprintf('%.1f s', microtime(true) - $t0)],
            ['Duree mediane' => sprintf('%.1f ms', $durees[intdiv(\count($durees), 2)] ?? 0)],
            ['Duree au 95e centile' => sprintf('%.1f ms', $durees[(int) floor(\count($durees) * 0.95)] ?? 0)],
        );

        $repartition = $this->cnx->fetchAllAssociative(
            'SELECT verdict, count(*) n FROM lettrage.decision GROUP BY 1 ORDER BY 2 DESC');
        $io->table(['Verdict', 'Lots'], array_map(
            static fn (array $r): array => [Verdict::libelle((string) $r['verdict']), number_format((int) $r['n'], 0, ',', ' ')],
            $repartition));

        $io->success('Cascade executee. Aucune ligne consommee n\'a ete reprise par une methode suivante.');

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $a */
    private function enregistrer(array $a): void
    {
        $forts = \count(array_filter($a['indices'], static fn (array $i): bool => $i['fort']));
        $this->cnx->executeStatement(
            'INSERT INTO lettrage.decision
               (lot_id, verdict, methode, arret, motif, nb_indices, nb_indices_forts,
                solde, montant, nb_ecritures, duree_ms, iterations, solutions)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (lot_id) DO UPDATE SET
               verdict = excluded.verdict, methode = excluded.methode, arret = excluded.arret,
               motif = excluded.motif, nb_indices = excluded.nb_indices,
               nb_indices_forts = excluded.nb_indices_forts, solde = excluded.solde,
               duree_ms = excluded.duree_ms, calcule_le = now()',
            [
                $a['lot']['id'], $a['verdict'], $a['methode'], $a['arret'],
                mb_substr((string) $a['motif'], 0, 400), \count($a['indices']), $forts,
                round((float) $a['solde_avant'], 2), $a['lot']['montant'], $a['lot']['nb_ecritures'],
                round((float) $a['chrono']['total'], 3),
                (int) ($a['cascade']['combinaison']['iterations'] ?? 0),
                (int) ($a['cascade']['combinaison']['solutions'] ?? 0),
            ]);

        $rang = 0;
        foreach ($a['indices'] as $i) {
            $this->cnx->executeStatement(
                'INSERT INTO lettrage.indice (lot_id, rang, cle, libelle, constat, fort) VALUES (?,?,?,?,?,?)',
                [$a['lot']['id'], ++$rang, $i['cle'], $i['libelle'],
                    mb_substr((string) $i['constat'], 0, 240), $i['fort'] ? 1 : 0]);
        }
    }
}
