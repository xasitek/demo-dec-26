<?php

declare(strict_types=1);

namespace App\Affectation\Command;

use App\Affectation\Moteur\Decision;
use App\Affectation\Moteur\MoteurAffectation;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Execute le moteur sur les virements, stocke ses decisions, puis les confronte
 * a la verite de reference.
 *
 * L'ordre compte, et il n'est pas negociable : le moteur travaille d'abord,
 * sans voir la reponse. La verite n'est ouverte qu'ENSUITE, par ce module de
 * mesure et par lui seul.
 *
 * Les poids et les seuils n'ont ete regles que sur CALIBRATION. VALIDATION a
 * servi a regarder ; BLIND_TEST n'a servi a rien d'autre qu'a mesurer.
 */
#[AsCommand(
    name: 'app:affectation:mesurer',
    description: 'Execute le moteur d\'affectation puis mesure sa performance contre la verite.',
)]
final class MesurerCommand extends Command
{
    /**
     * Les quatre populations, et ce que chacune a le droit de prouver.
     *
     *   CALIBRATION   la seule sur laquelle poids et seuils ont ete regles
     *   VALIDATION    regardee pendant la mise au point
     *   HOLDOUT_1     ancienne population aveugle ; ses erreurs ont ete
     *                 etudiees le 07/09/2026, elle ne prouve donc plus rien
     *   BLIND_TEST_2  premiere population aveugle post-correction ; un de ses
     *                 faux positifs a ete ouvert le 07/09/2026 et la fabrique
     *                 corrigee ensuite, elle est donc devenue un jeu de
     *                 validation et ne publie plus rien
     *   BLIND_TEST_3  troisieme population aveugle ; elle a revele un defaut
     *                 reel du moteur -- un numero de serie contradictoire
     *                 invisible a l'extracteur de references -- et devient donc
     *                 a son tour un jeu de validation
     *   BLIND_TEST_4  tiree d'une quatrieme graine APRES cette correction.
     *                 Mesuree une seule fois. C'est elle, et elle seule, qui
     *                 porte le resultat publie.
     *
     * @var list<string>
     */
    private const COHORTES = ['CALIBRATION', 'VALIDATION', 'HOLDOUT_1', 'BLIND_TEST_2', 'BLIND_TEST_3', 'BLIND_TEST_4'];

    public function __construct(
        private readonly Connection $cnx,
        private readonly MoteurAffectation $moteur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('par-cohorte', null, InputOption::VALUE_REQUIRED,
                'Nombre de virements a traiter par cohorte. 0 = tous.', '600')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED,
                'ENRICHED (contrat de donnees complet) ou RAW (date, montant, libelle seuls).', MoteurAffectation::MODE_ENRICHI)
            ->addOption('garder', null, InputOption::VALUE_NONE,
                'Conserve les decisions deja calculees et complete.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $parCohorte = (int) $input->getOption('par-cohorte');
        $mode = (string) $input->getOption('mode');
        $io->title(sprintf('Moteur d\'affectation — mode %s', $mode));

        if (!$input->getOption('garder')) {
            foreach (['affectation.preuve', 'affectation.candidat', 'affectation.decision'] as $t) {
                $this->cnx->executeStatement('TRUNCATE '.$t);
            }
        }

        // Le cas vedette est toujours traite, quelle que soit la taille du lot.
        $ids = ['TX-000001'];
        foreach (self::COHORTES as $cohorte) {
            $sql = 'SELECT id FROM affectation.virement WHERE cohorte = ? AND NOT vedette ORDER BY id';
            if ($parCohorte > 0) {
                $sql .= ' LIMIT '.$parCohorte;
            }
            $ids = array_merge($ids, $this->cnx->fetchFirstColumn($sql, [$cohorte]));
        }

        $io->text(sprintf('%s virements a traiter.', number_format(\count($ids), 0, ',', ' ')));
        $barre = $io->createProgressBar(\count($ids));
        $barre->start();

        $t0 = microtime(true);
        $durees = [];
        $traites = 0;

        foreach ($ids as $id) {
            $r = $this->moteur->analyser((string) $id, $mode);
            $this->enregistrer($r, $mode);
            $durees[] = $r['chrono']['total'];
            ++$traites;
            if (0 === $traites % 25) {
                $barre->advance(25);
            }
        }
        $barre->finish();
        $io->newLine(2);

        $secondes = microtime(true) - $t0;
        sort($durees);
        $mediane = $durees[intdiv(\count($durees), 2)] ?? 0.0;
        $p95 = $durees[(int) floor(\count($durees) * 0.95)] ?? 0.0;

        $io->section('Performance, mesuree sur cette machine');
        $io->definitionList(
            ['Virements traites' => number_format($traites, 0, ',', ' ')],
            ['Duree totale' => sprintf('%.1f s', $secondes)],
            ['Debit' => sprintf('%.0f virements par seconde', $traites / max(0.001, $secondes))],
            ['Duree mediane' => sprintf('%.1f ms', $mediane)],
            ['Duree au 95e centile' => sprintf('%.1f ms', $p95)],
        );

        // ------------------------------------------------------------ mesure
        $io->section('Resultats par cohorte, confrontes a la verite de reference');
        foreach (self::COHORTES as $cohorte) {
            $this->afficherCohorte($io, $cohorte, $mode);
        }

        $io->section('Matrice de confusion — BLIND_TEST_4');
        $this->afficherMatrice($io, 'BLIND_TEST_4');

        $io->success('Mesure terminee. Les poids et les seuils n\'ont ete regles que sur CALIBRATION.');

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $r */
    private function enregistrer(array $r, string $mode): void
    {
        $retenu = $r['retenu'];
        $this->cnx->executeStatement(
            'INSERT INTO affectation.decision
               (virement_id, mode, decision, score, score_suivant, marge, motif, client_propose,
                societes, factures, nb_factures, montant_explique, duree_ms, duree_enrichissement_ms,
                duree_candidats_ms, duree_combinaison_ms, duree_score_ms, nb_candidats, noeuds_explores)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (virement_id) DO UPDATE SET
               mode = excluded.mode, decision = excluded.decision, score = excluded.score,
               score_suivant = excluded.score_suivant, marge = excluded.marge, motif = excluded.motif,
               client_propose = excluded.client_propose,
               societes = excluded.societes, factures = excluded.factures,
               nb_factures = excluded.nb_factures, montant_explique = excluded.montant_explique,
               duree_ms = excluded.duree_ms, nb_candidats = excluded.nb_candidats,
               noeuds_explores = excluded.noeuds_explores, calcule_le = now()',
            [
                $r['virement']['id'], $mode, $r['decision'], $r['score'], $r['score_suivant'],
                $r['marge'], mb_substr($r['motif'], 0, 240),
                null !== $retenu ? $retenu['client']['id'] : null,
                null !== $retenu ? implode('|', $retenu['societes']) : null,
                null !== $retenu ? implode('|', array_column($retenu['factures'], 'id')) : null,
                null !== $retenu ? \count($retenu['factures']) : 0,
                null !== $retenu && [] !== $retenu['factures']
                    ? array_sum(array_map('floatval', array_column($retenu['factures'], 'montant'))) : null,
                round($r['chrono']['total'], 3),
                round($r['chrono']['enrichissement'], 3),
                round($r['chrono']['candidats'], 3),
                round($r['chrono']['combinaison'], 3),
                round($r['chrono']['score'], 3),
                \count($r['candidats']),
                $r['combinaison']['noeuds'],
            ]);

        $this->cnx->executeStatement('DELETE FROM affectation.preuve WHERE virement_id = ?', [$r['virement']['id']]);
        $rang = 0;
        foreach ($r['preuves'] as $p) {
            $this->cnx->executeStatement(
                'INSERT INTO affectation.preuve (virement_id, rang, signal, libelle, constat, poids, origine)
                 VALUES (?,?,?,?,?,?,?)',
                [$r['virement']['id'], ++$rang, $p['signal'], $p['libelle'],
                    mb_substr($p['constat'], 0, 300), $p['poids'], $p['origine']]);
        }

        $this->cnx->executeStatement('DELETE FROM affectation.candidat WHERE virement_id = ?', [$r['virement']['id']]);
        $rang = 0;
        foreach (\array_slice($r['candidats'], 0, 5) as $c) {
            ++$rang;
            $this->cnx->executeStatement(
                'INSERT INTO affectation.candidat (virement_id, rang, client_id, score, retenu, motif_ecart)
                 VALUES (?,?,?,?,?,?)',
                [$r['virement']['id'], $rang, $c['client']['id'], $c['score'], 1 === $rang ? 1 : 0,
                    implode(', ', $c['entrees'])]);
        }
    }

    /**
     * Les chiffres d'une population, confrontes a la verite.
     *
     * Un point de vocabulaire, parce qu'il porte tout le reste. Est JUSTE une
     * affectation qui designe le bon compte client ET qui portait sur un
     * virement que l'outil avait le droit d'affecter seul. Affecter le bon
     * compte sur un cas que la verite declare ambigu reste une faute : le
     * moteur a eu raison par chance, et la chance ne se defend pas devant un
     * jury.
     */
    private function afficherCohorte(SymfonyStyle $io, string $cohorte, string $mode): void
    {
        $l = $this->cnx->fetchAssociative(
            "SELECT
               count(*) AS n,
               count(*) FILTER (WHERE t.decision_attendue = 'auto') AS automatisables,
               count(*) FILTER (WHERE d.decision = 'automatique') AS auto,
               count(*) FILTER (WHERE d.decision = 'validation') AS validation,
               count(*) FILTER (WHERE d.decision = 'exception') AS exception,
               count(*) FILTER (WHERE d.decision = 'refus') AS refus,
               count(*) FILTER (WHERE d.client_propose IS NOT NULL) AS identifies,
               count(*) FILTER (WHERE d.decision = 'automatique'
                                  AND t.decision_attendue = 'auto'
                                  AND d.client_propose = t.vrai_client) AS auto_justes,
               count(*) FILTER (WHERE d.decision = 'automatique'
                                  AND (t.decision_attendue <> 'auto'
                                       OR t.vrai_client IS NULL
                                       OR d.client_propose <> t.vrai_client)) AS auto_faux,
               count(*) FILTER (WHERE d.decision = 'automatique'
                                  AND t.decision_attendue = 'auto'
                                  AND d.client_propose <> t.vrai_client) AS auto_mauvais_compte,
               count(*) FILTER (WHERE d.decision = 'automatique' AND t.decision_attendue <> 'auto') AS auto_sur_ambigu,
               coalesce(sum(v.montant) FILTER (WHERE d.decision = 'automatique'
                                  AND t.decision_attendue = 'auto'
                                  AND d.client_propose = t.vrai_client), 0) AS montant_juste,
               coalesce(sum(v.montant) FILTER (WHERE d.decision = 'automatique'
                                  AND (t.decision_attendue <> 'auto'
                                       OR t.vrai_client IS NULL
                                       OR d.client_propose <> t.vrai_client)), 0) AS montant_faux,
               coalesce(sum(v.montant) FILTER (WHERE d.decision <> 'automatique'), 0) AS montant_humain,
               coalesce(sum(v.montant) FILTER (WHERE d.decision <> 'automatique'
                                  AND t.decision_attendue = 'exception'), 0) AS montant_humain_du,
               count(*) FILTER (WHERE d.decision <> 'automatique' AND t.decision_attendue = 'exception') AS humain_du
             FROM affectation.decision d
             JOIN affectation.virement v ON v.id = d.virement_id
             JOIN affectation_verite.virement t ON t.objet_id = d.virement_id
            WHERE v.cohorte = ? AND d.mode = ?", [$cohorte, $mode]);

        if (false === $l || 0 === (int) $l['n']) {
            $io->text($cohorte.' : aucune decision.');

            return;
        }
        $n = (int) $l['n'];
        $auto = (int) $l['auto'];
        $automatisables = (int) $l['automatisables'];
        $pc = static fn (int|float $x): string => sprintf('%.1f %%', $n > 0 ? $x / $n * 100 : 0);
        $eur = static fn (mixed $x): string => number_format((float) $x / 1000000, 3, ',', ' ').' M€';

        $io->text(sprintf('<info>%s</info> — %s virements', $cohorte, number_format($n, 0, ',', ' ')));
        $io->table(
            ['Grandeur', 'Valeur', 'Part'],
            [
                ['Payeur identifie', number_format((int) $l['identifies'], 0, ',', ' '), $pc((int) $l['identifies'])],
                ['Affectation automatique', number_format($auto, 0, ',', ' '), $pc($auto)],
                ['Proposition a valider', number_format((int) $l['validation'], 0, ',', ' '), $pc((int) $l['validation'])],
                ['Exception assumee', number_format((int) $l['exception'], 0, ',', ' '), $pc((int) $l['exception'])],
                ['Aucune affectation', number_format((int) $l['refus'], 0, ',', ' '), $pc((int) $l['refus'])],
                ['--- ce que vaut ce qui est accepte seul ---', '', ''],
                ['PRECISION sur les affectations automatiques',
                    sprintf('%.2f %%', $auto > 0 ? (int) $l['auto_justes'] / $auto * 100 : 0), ''],
                ['RAPPEL sur les cas legitimement automatisables',
                    sprintf('%.2f %%', $automatisables > 0 ? (int) $l['auto_justes'] / $automatisables * 100 : 0),
                    number_format($automatisables, 0, ',', ' ').' cas'],
                ['Faux positifs', number_format((int) $l['auto_faux'], 0, ',', ' '), ''],
                ['  dont mauvais compte client', number_format((int) $l['auto_mauvais_compte'], 0, ',', ' '), ''],
                ['  dont cas ambigu tranche seul', number_format((int) $l['auto_sur_ambigu'], 0, ',', ' '), ''],
                ['Montant correctement affecte', $eur($l['montant_juste']), ''],
                ['MONTANT AFFECTE A TORT', $eur($l['montant_faux']), ''],
                ['--- ce que l\'outil laisse volontairement a l\'humain ---', '', ''],
                ['Montant soumis a un regard humain', $eur($l['montant_humain']), $pc($n - $auto)],
                ['  dont cas ou la verite exige un humain', $eur($l['montant_humain_du']),
                    number_format((int) $l['humain_du'], 0, ',', ' ').' cas'],
            ]);
    }

    private function afficherMatrice(SymfonyStyle $io, string $cohorte): void
    {
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT t.decision_attendue AS attendu, d.decision AS obtenu, count(*) AS n
               FROM affectation.decision d
               JOIN affectation.virement v ON v.id = d.virement_id
               JOIN affectation_verite.virement t ON t.objet_id = d.virement_id
              WHERE v.cohorte = ?
              GROUP BY 1, 2 ORDER BY 3 DESC', [$cohorte]);

        $total = array_sum(array_map(static fn (array $l): int => (int) $l['n'], $lignes));
        $justes = 0;
        $table = [];
        foreach ($lignes as $l) {
            // « auto » attendu est satisfait par une affectation automatique OU
            // par une proposition a valider : dans les deux cas le payeur est
            // trouve, et la seconde passe simplement par un humain.
            $bon = ('auto' === $l['attendu'] && \in_array($l['obtenu'], [Decision::AUTOMATIQUE, Decision::VALIDATION], true))
                || ('exception' === $l['attendu'] && \in_array($l['obtenu'], [Decision::EXCEPTION, Decision::REFUS], true));
            if ($bon) {
                $justes += (int) $l['n'];
            }
            $table[] = [$l['attendu'], Decision::libelle((string) $l['obtenu']),
                number_format((int) $l['n'], 0, ',', ' '), $bon ? 'juste' : 'ERREUR'];
        }
        $io->table(['Attendu', 'Obtenu', 'Nombre', 'Verdict'], $table);
        $io->text(sprintf('Exactitude sur %s : <info>%.2f %%</info> (%s / %s)',
            $cohorte, $total > 0 ? $justes / $total * 100 : 0,
            number_format($justes, 0, ',', ' '), number_format($total, 0, ',', ' ')));
    }
}
