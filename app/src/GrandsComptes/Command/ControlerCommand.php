<?php

declare(strict_types=1);

namespace App\GrandsComptes\Command;

use App\GrandsComptes\Moteur\Controle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Passe le controle de conformite sur les dossiers, et le mesure.
 *
 * Deux mesures, parce que ce sont deux questions differentes :
 *
 *   le VERDICT, dossier par dossier : le controle conclut-il ce qu'il faut
 *   conclure ? C'est ce que le comptable lit, et c'est ce qui declenche ou non
 *   un retour au site ;
 *
 *   la DETECTION, anomalie par anomalie : chaque regle trouve-t-elle ce qu'elle
 *   doit trouver, sans rien inventer ? C'est ce qui rend le rejet defendable.
 *
 * Le KPI central est la PRECISION : aucune anomalie reprochee a tort, aucun
 * dossier declare non conforme a tort. Un rejet injustifie est porte par le
 * site et par la relation avec le loueur -- il coute plus cher qu'un dossier
 * laisse au controle humain.
 */
#[AsCommand(
    name: 'app:grands-comptes:controler',
    description: 'Passe le controle de conformite et le mesure contre la verite.',
)]
final class ControlerCommand extends Command
{
    public function __construct(
        private readonly Connection $cnx,
        private readonly Controle $controle,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cohorte', null, InputOption::VALUE_REQUIRED,
                'Ne controler qu\'une cohorte : CALIBRATION_O7, VALIDATION_O7, BLIND_O7.')
            ->addOption('sans-mesure', null, InputOption::VALUE_NONE,
                'Controler sans ouvrir la verite. Utile avant une mesure unique.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Controle de conformite des dossiers grands comptes');

        $cohorte = $entree->getOption('cohorte');
        $args = [];
        $ou = '';
        if (\is_string($cohorte) && '' !== $cohorte) {
            $ou = ' WHERE cohorte = ?';
            $args[] = $cohorte;
        }

        /** @var list<string> $ids */
        $ids = $this->cnx->fetchFirstColumn(
            'SELECT id FROM grands_comptes.dossier'.$ou.' ORDER BY id', $args);
        $io->text(sprintf('%s dossiers a controler.', number_format(\count($ids), 0, ',', ' ')));

        $this->cnx->executeStatement(
            'DELETE FROM grands_comptes.controle WHERE dossier_id IN (SELECT id FROM grands_comptes.dossier'.$ou.')',
            $args);
        $this->cnx->executeStatement(
            'DELETE FROM grands_comptes.controle_anomalie WHERE dossier_id IN (SELECT id FROM grands_comptes.dossier'.$ou.')',
            $args);

        $t0 = microtime(true);
        $verdicts = [];
        $lignes = [];
        $lignesAno = [];

        foreach ($ids as $id) {
            $r = $this->controle->analyser($id);
            $verdicts[$r['verdict']] = ($verdicts[$r['verdict']] ?? 0) + 1;

            $lignes[] = [$id, $r['verdict'], \count($r['anomalies']), $r['nb_bloquantes'],
                $r['pieces_attendues'], $r['pieces_presentes'], $r['arret'],
                round($r['duree_ms'], 3)];
            foreach ($r['anomalies'] as $a) {
                $lignesAno[] = [$id, $a['code'], $a['type_piece'], $a['gravite'], $a['suite'],
                    mb_substr((string) $a['attendu'], 0, 80), mb_substr((string) $a['trouve'], 0, 80),
                    mb_substr((string) $a['motif'], 0, 300)];
            }
        }

        $this->inserer('grands_comptes.controle',
            ['dossier_id', 'verdict', 'nb_anomalies', 'nb_bloquantes', 'pieces_attendues',
                'pieces_presentes', 'arret', 'duree_ms'], $lignes);
        $this->inserer('grands_comptes.controle_anomalie',
            ['dossier_id', 'code_anomalie', 'type_piece', 'gravite', 'suite', 'attendu', 'trouve', 'motif'],
            $lignesAno);

        $ms = (microtime(true) - $t0) * 1000;
        $io->table(['Verdict', 'Dossiers'], array_map(
            static fn (string $v, int $n): array => [$v, number_format($n, 0, ',', ' ')],
            array_keys($verdicts), array_values($verdicts)));
        $io->text(sprintf('%s anomalies produites. %s ms au total, %s ms par dossier.',
            number_format(\count($lignesAno), 0, ',', ' '),
            number_format($ms, 0, ',', ' '),
            number_format($ms / max(\count($ids), 1), 3, ',', ' ')));

        if ($entree->getOption('sans-mesure')) {
            $io->warning('Mesure non demandee : la verite n\'a pas ete ouverte.');

            return Command::SUCCESS;
        }

        $this->mesurer($io, \is_string($cohorte) ? $cohorte : null);

        return Command::SUCCESS;
    }

    /** La mesure, contre la verite. */
    private function mesurer(SymfonyStyle $io, ?string $cohorte): void
    {
        $io->section('Mesure du VERDICT, dossier par dossier');

        $ou = null !== $cohorte ? ' AND d.cohorte = :c' : '';
        $args = null !== $cohorte ? ['c' => $cohorte] : [];

        /** @var list<array<string, mixed>> $matrice */
        $matrice = $this->cnx->fetchAllAssociative(
            "SELECT v.verdict_attendu, c.verdict, count(*) n
               FROM grands_comptes.controle c
               JOIN grands_comptes.dossier d ON d.id = c.dossier_id
               JOIN grands_comptes_verite.dossier v ON v.objet_id = c.dossier_id
              WHERE 1 = 1$ou
              GROUP BY 1, 2 ORDER BY 1, 2", $args);

        $table = [];
        $justes = 0;
        $total = 0;
        $fauxNonConformes = 0;
        foreach ($matrice as $l) {
            $n = (int) $l['n'];
            $total += $n;
            if ($l['verdict_attendu'] === $l['verdict']) {
                $justes += $n;
            }
            // La faute qui coute : declarer non conforme un dossier qui ne
            // l'est pas. C'est un site relance a tort, et un loueur qui attend.
            if ('non_conforme' === $l['verdict'] && 'non_conforme' !== $l['verdict_attendu']) {
                $fauxNonConformes += $n;
            }
            $table[] = [(string) $l['verdict_attendu'], (string) $l['verdict'],
                number_format($n, 0, ',', ' '),
                $l['verdict_attendu'] === $l['verdict'] ? 'juste' : 'ECART'];
        }
        $io->table(['Verdict attendu', 'Verdict rendu', 'Dossiers', ''], $table);
        $io->text(sprintf('Verdicts justes : %s sur %s, soit %s %%.',
            number_format($justes, 0, ',', ' '), number_format($total, 0, ',', ' '),
            number_format($total > 0 ? $justes / $total * 100 : 0, 2, ',', ' ')));
        $io->text(sprintf('Dossiers declares NON CONFORMES a tort : %s.',
            number_format($fauxNonConformes, 0, ',', ' ')));

        $io->section('Mesure de la DETECTION, anomalie par anomalie');

        $detail = $this->cnx->fetchAllAssociative(
            "WITH attendues AS (
               SELECT v.objet_id, v.code_anomalie FROM grands_comptes_verite.anomalie v
                 JOIN grands_comptes.dossier d ON d.id = v.objet_id WHERE 1 = 1$ou
             ), trouvees AS (
               SELECT c.dossier_id AS objet_id, c.code_anomalie FROM grands_comptes.controle_anomalie c
                 JOIN grands_comptes.dossier d ON d.id = c.dossier_id WHERE 1 = 1$ou
             )
             SELECT coalesce(a.code_anomalie, t.code_anomalie) code,
                    count(*) FILTER (WHERE a.objet_id IS NOT NULL AND t.objet_id IS NOT NULL) vrais_positifs,
                    count(*) FILTER (WHERE a.objet_id IS NULL) faux_positifs,
                    count(*) FILTER (WHERE t.objet_id IS NULL) manques
               FROM attendues a
               FULL OUTER JOIN trouvees t
                 ON t.objet_id = a.objet_id AND t.code_anomalie = a.code_anomalie
              GROUP BY 1 ORDER BY 1", $args);

        $table = [];
        $vp = 0;
        $fp = 0;
        $mq = 0;
        foreach ($detail as $l) {
            $vp += (int) $l['vrais_positifs'];
            $fp += (int) $l['faux_positifs'];
            $mq += (int) $l['manques'];
            $table[] = [(string) $l['code'], (int) $l['vrais_positifs'],
                (int) $l['faux_positifs'], (int) $l['manques'],
                0 === (int) $l['faux_positifs'] && 0 === (int) $l['manques'] ? 'exact' : 'a voir'];
        }
        $io->table(['Code', 'Vrais positifs', 'Faux positifs', 'Manques', ''], $table);

        $precision = ($vp + $fp) > 0 ? $vp / ($vp + $fp) * 100 : 100.0;
        $rappel = ($vp + $mq) > 0 ? $vp / ($vp + $mq) * 100 : 100.0;
        $io->text(sprintf('Precision : %s %% (%s vrais positifs, %s faux positifs).',
            number_format($precision, 2, ',', ' '), number_format($vp, 0, ',', ' '),
            number_format($fp, 0, ',', ' ')));
        $io->text(sprintf('Rappel : %s %% (%s anomalies manquees).',
            number_format($rappel, 2, ',', ' '), number_format($mq, 0, ',', ' ')));

        if (0 === $fp && 0 === $fauxNonConformes) {
            $io->success('Aucune anomalie reprochee a tort, aucun dossier declare non conforme a tort.');
        } else {
            $io->warning(sprintf('%s faux positifs et %s dossiers non conformes a tort : a instruire.',
                number_format($fp, 0, ',', ' '), number_format($fauxNonConformes, 0, ',', ' ')));
        }
    }

    /**
     * @param list<string>      $colonnes
     * @param list<list<mixed>> $lignes
     */
    private function inserer(string $table, array $colonnes, array $lignes): void
    {
        if ([] === $lignes) {
            return;
        }
        $paquet = 400;
        $liste = '('.implode(', ', $colonnes).')';
        for ($i = 0; $i < \count($lignes); $i += $paquet) {
            $tranche = \array_slice($lignes, $i, $paquet);
            $valeurs = [];
            $args = [];
            foreach ($tranche as $ligne) {
                $valeurs[] = '('.implode(', ', array_fill(0, \count($colonnes), '?')).')';
                foreach ($ligne as $v) {
                    $args[] = $v;
                }
            }
            $this->cnx->executeStatement(
                'INSERT INTO '.$table.' '.$liste.' VALUES '.implode(', ', $valeurs), $args);
        }
    }
}
