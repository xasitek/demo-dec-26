<?php

declare(strict_types=1);

namespace App\Lettrage\Command;

use App\Lettrage\Moteur\Verdict;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Confronte les decisions de lettrage a la verite de reference.
 *
 * L'ordre n'est pas negociable, et c'est le meme que pour l'outil 4 : la
 * cascade a travaille d'abord, sans voir la reponse. La verite n'est ouverte
 * qu'ICI, apres coup, par ce module et par lui seul.
 *
 * Trois populations. `CALIBRATION_O5` est la seule sur laquelle les seuils ont
 * ete regles. `VALIDATION_O5` a servi a regarder. `BLIND_O5` est tiree d'une
 * graine qui lui est propre et porte le resultat publie.
 *
 * Le KPI central n'est pas le taux d'automatisation : c'est le montant lettre a
 * tort, et le nombre d'ecritures lettrees a tort. Zero est la seule valeur
 * acceptable, parce qu'une ecriture fausse se propage a l'encours, au DSO, aux
 * relances et aux comptes annuels.
 */
#[AsCommand(
    name: 'app:lettrage:mesurer',
    description: 'Mesure la performance du lettrage contre la verite de reference.',
)]
final class MesurerLettrageCommand extends Command
{
    private const COHORTES = ['CALIBRATION_O5', 'VALIDATION_O5', 'BLIND_O5', 'BLIND_O5_2', 'BLIND_O5_3'];

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Lettrage — mesure contre la verite de reference');

        foreach (self::COHORTES as $cohorte) {
            $this->afficher($io, $cohorte);
        }

        $io->section('Matrice de confusion — BLIND_O5_3');
        $lignes = $this->cnx->fetchAllAssociative(
            "SELECT t.verdict AS attendu, d.verdict AS obtenu, count(*) n
               FROM lettrage.decision d
               JOIN lettrage.lot l ON l.id = d.lot_id
               JOIN lettrage_verite.lot t ON t.objet_id = d.lot_id
              WHERE l.cohorte = 'BLIND_O5_3'
              GROUP BY 1, 2 ORDER BY 3 DESC");

        $total = 0;
        $justes = 0;
        $table = [];
        foreach ($lignes as $l) {
            $n = (int) $l['n'];
            $total += $n;
            // Est JUSTE : un vrai rapprochement lettre ou propose ; un cas qui
            // doit rester humain et qui n'est pas lettre seul ; un cas sans
            // rapprochement valide et qui est refuse.
            $bon = match ((string) $l['attendu']) {
                'TRUE_MATCH' => \in_array($l['obtenu'], [Verdict::AUTOMATIQUE, Verdict::PROPOSITION], true),
                'DOIT_RESTER_HUMAIN' => \in_array($l['obtenu'], [Verdict::HUMAIN, Verdict::PROPOSITION, Verdict::REFUS], true),
                'AUCUN_MATCH_VALIDE' => \in_array($l['obtenu'], [Verdict::REFUS, Verdict::HUMAIN], true),
                default => false,
            };
            if ($bon) {
                $justes += $n;
            }
            $table[] = [$l['attendu'], Verdict::libelle((string) $l['obtenu']),
                number_format($n, 0, ',', ' '), $bon ? 'juste' : 'ERREUR'];
        }
        $io->table(['Attendu', 'Obtenu', 'Nombre', 'Verdict'], $table);
        $io->text(sprintf('Exactitude sur BLIND_O5_3 : <info>%.2f %%</info> (%s / %s)',
            $total > 0 ? $justes / $total * 100 : 0,
            number_format($justes, 0, ',', ' '), number_format($total, 0, ',', ' ')));

        // ------------------------------------------- le garde-fou de solde
        $io->section('Garde-fou absolu : aucun groupe a solde non conforme accepte');
        $horsSolde = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM lettrage.decision WHERE verdict = 'automatique' AND abs(solde) > 500");
        $io->text(sprintf('Groupes lettres automatiquement dont le solde depasse le plafond absolu : <info>%d</info>.',
            $horsSolde));
        if (0 !== $horsSolde) {
            $io->error('Un lettrage automatique a produit un groupe hors bareme. Ce chiffre doit valoir zero.');

            return Command::FAILURE;
        }

        // ------------------------------------------- la relecture
        $relecture = $this->cnx->fetchAssociative(
            "SELECT count(*) FILTER (WHERE verdict <> 'conforme') AS proposes,
                    count(*) AS relus FROM lettrage.relecture");
        if (\is_array($relecture)) {
            $io->section('Relecture des lettrages existants');
            $io->text(sprintf('%s lettrages relus, <info>%s</info> proposes au delettrage.',
                number_format((int) $relecture['relus'], 0, ',', ' '),
                number_format((int) $relecture['proposes'], 0, ',', ' ')));
        }

        $io->success('Mesure terminee. Les seuils n\'ont ete regles que sur CALIBRATION_O5.');

        return Command::SUCCESS;
    }

    private function afficher(SymfonyStyle $io, string $cohorte): void
    {
        $l = $this->cnx->fetchAssociative(
            "SELECT
               count(*) AS lots,
               sum(d.nb_ecritures) AS ecritures,
               count(*) FILTER (WHERE t.verdict = 'TRUE_MATCH') AS lettrables,
               count(*) FILTER (WHERE d.verdict = 'automatique') AS auto,
               sum(d.nb_ecritures) FILTER (WHERE d.verdict = 'automatique') AS ecritures_auto,
               count(*) FILTER (WHERE d.verdict = 'proposition') AS proposition,
               count(*) FILTER (WHERE d.verdict = 'humain') AS humain,
               count(*) FILTER (WHERE d.verdict = 'refus') AS refus,
               count(*) FILTER (WHERE d.verdict = 'automatique' AND t.verdict = 'TRUE_MATCH') AS auto_justes,
               count(*) FILTER (WHERE d.verdict = 'automatique' AND t.verdict <> 'TRUE_MATCH') AS auto_faux,
               sum(d.nb_ecritures) FILTER (WHERE d.verdict = 'automatique' AND t.verdict <> 'TRUE_MATCH') AS ecritures_fausses,
               count(*) FILTER (WHERE d.verdict <> 'automatique' AND t.verdict = 'TRUE_MATCH') AS manques,
               coalesce(sum(l.montant) FILTER (WHERE d.verdict = 'automatique' AND t.verdict = 'TRUE_MATCH'), 0) AS montant_juste,
               coalesce(sum(l.montant) FILTER (WHERE d.verdict = 'automatique' AND t.verdict <> 'TRUE_MATCH'), 0) AS montant_faux,
               coalesce(sum(l.montant) FILTER (WHERE d.verdict <> 'automatique'), 0) AS montant_humain,
               round(avg(d.duree_ms)::numeric, 2) AS duree
             FROM lettrage.decision d
             JOIN lettrage.lot l ON l.id = d.lot_id
             JOIN lettrage_verite.lot t ON t.objet_id = d.lot_id
            WHERE l.cohorte = ?", [$cohorte]);

        if (!\is_array($l) || 0 === (int) $l['lots']) {
            $io->text($cohorte.' : aucune decision.');

            return;
        }

        $lots = (int) $l['lots'];
        $auto = (int) $l['auto'];
        $lettrables = (int) $l['lettrables'];
        $pc = static fn (int|float $x): string => sprintf('%.1f %%', $lots > 0 ? $x / $lots * 100 : 0);
        $eur = static fn (mixed $x): string => number_format((float) $x / 1000000, 3, ',', ' ').' M€';

        $io->text(sprintf('<info>%s</info> — %s lots, %s écritures',
            $cohorte, number_format($lots, 0, ',', ' '),
            number_format((int) $l['ecritures'], 0, ',', ' ')));
        $io->table(['Grandeur', 'Valeur', 'Part'], [
            ['Écritures analysées', number_format((int) $l['ecritures'], 0, ',', ' '), ''],
            ['Lots lettrés automatiquement', number_format($auto, 0, ',', ' '), $pc($auto)],
            ['Écritures lettrées automatiquement', number_format((int) $l['ecritures_auto'], 0, ',', ' '), ''],
            ['Propositions soumises au comptable', number_format((int) $l['proposition'], 0, ',', ' '), $pc((int) $l['proposition'])],
            ['Intervention humaine imposée', number_format((int) $l['humain'], 0, ',', ' '), $pc((int) $l['humain'])],
            ['Lettrages refusés', number_format((int) $l['refus'], 0, ',', ' '), $pc((int) $l['refus'])],
            ['--- ce que vaut ce qui est lettre seul ---', '', ''],
            ['PRECISION sur les lettrages automatiques',
                sprintf('%.2f %%', $auto > 0 ? (int) $l['auto_justes'] / $auto * 100 : 0), ''],
            ['RAPPEL sur les lots reellement lettrables',
                sprintf('%.2f %%', $lettrables > 0 ? (int) $l['auto_justes'] / $lettrables * 100 : 0),
                number_format($lettrables, 0, ',', ' ').' lots'],
            ['Faux positifs', number_format((int) $l['auto_faux'], 0, ',', ' '), ''],
            ['ECRITURES LETTREES A TORT', number_format((int) $l['ecritures_fausses'], 0, ',', ' '), ''],
            ['Faux negatifs (lettrables non lettres seuls)', number_format((int) $l['manques'], 0, ',', ' '), ''],
            ['Montant correctement lettre', $eur($l['montant_juste']), ''],
            ['MONTANT LETTRE A TORT', $eur($l['montant_faux']), ''],
            ['--- ce qui reste a l\'humain ---', '', ''],
            ['Lots laisses a l\'humain', number_format($lots - $auto, 0, ',', ' '), $pc($lots - $auto)],
            ['Montant laisse a l\'humain', $eur($l['montant_humain']), ''],
            ['Duree moyenne par lot', $l['duree'].' ms', ''],
        ]);
    }
}
