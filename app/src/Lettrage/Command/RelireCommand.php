<?php

declare(strict_types=1);

namespace App\Lettrage\Command;

use App\Lettrage\Moteur\Tolerances;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relit les lettrages DEJA POSES, et dit lesquels sont a revoir.
 *
 * Un moteur qui n'automatiserait que l'avenir laisserait derriere lui tout ce
 * que des mois de lettrage manuel ont pu poser de travers. La relecture est une
 * capacite a part entiere, et c'est probablement la plus utile a un
 * expert-comptable : elle porte sur des ecritures deja passees.
 *
 * Quatre defauts se detectent sans rien savoir d'autre que le groupe lui-meme :
 * un solde qui ne tombe pas, une cle sectorielle qui se contredit, une cle qui
 * ne prouve rien, un reglement compte deux fois.
 */
#[AsCommand(
    name: 'app:lettrage:relire',
    description: 'Relit les lettrages existants et propose ceux qui doivent etre revus.',
)]
final class RelireCommand extends Command
{
    /** References trop portees pour justifier un lettrage. */
    private const REFERENCES_GENERIQUES = ['ASOLDER', 'TRANSFERT', 'LIAISON', 'DIVERS', 'REGUL', 'ACOMPTE'];

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Relecture des lettrages existants');

        $codes = $this->cnx->fetchFirstColumn('SELECT code_lettrage FROM lettrage.historique ORDER BY code_lettrage');
        $io->text(sprintf('%s lettrages posés sur les six derniers mois.', number_format(\count($codes), 0, ',', ' ')));

        $this->cnx->executeStatement('TRUNCATE lettrage.relecture');
        $t0 = microtime(true);

        foreach ($codes as $code) {
            $lignes = $this->cnx->fetchAllAssociative(
                'SELECT id, sens, montant, date_ecriture, vin8, immatriculation, reference_piece, client_id
                   FROM lettrage.ecriture WHERE lettrage = ? ORDER BY sens DESC', [$code]);
            [$verdict, $motif] = $this->juger($lignes);
            $this->cnx->executeStatement(
                'INSERT INTO lettrage.relecture (code_lettrage, verdict, motif, solde, nb_ecritures)
                 VALUES (?,?,?,?,?)',
                [$code, $verdict, mb_substr($motif, 0, 400), round($this->solde($lignes), 2), \count($lignes)]);
        }

        $io->section('Ce que la relecture propose');
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT verdict, count(*) n FROM lettrage.relecture GROUP BY 1 ORDER BY 2 DESC');
        $io->table(['Verdict', 'Lettrages'], array_map(
            static fn (array $r): array => [$r['verdict'], number_format((int) $r['n'], 0, ',', ' ')], $lignes));

        // Confrontation a la verite : elle n'est ouverte qu'ICI, apres coup.
        $m = $this->cnx->fetchAssociative(
            "SELECT count(*) n,
                    count(*) FILTER (WHERE t.a_revoir AND r.verdict <> 'conforme') AS vrais_positifs,
                    count(*) FILTER (WHERE NOT t.a_revoir AND r.verdict <> 'conforme') AS faux_positifs,
                    count(*) FILTER (WHERE t.a_revoir AND r.verdict = 'conforme') AS manques,
                    count(*) FILTER (WHERE NOT t.a_revoir AND r.verdict = 'conforme') AS vrais_negatifs
               FROM lettrage.relecture r
               JOIN lettrage_verite.historique t ON t.objet_id = r.code_lettrage");

        if (\is_array($m)) {
            $aRevoir = (int) $m['vrais_positifs'] + (int) $m['manques'];
            $signales = (int) $m['vrais_positifs'] + (int) $m['faux_positifs'];
            $io->table(['Grandeur', 'Valeur'], [
                ['Lettrages relus', number_format((int) $m['n'], 0, ',', ' ')],
                ['Reellement a revoir', number_format($aRevoir, 0, ',', ' ')],
                ['Signales par la relecture', number_format($signales, 0, ',', ' ')],
                ['dont justes', number_format((int) $m['vrais_positifs'], 0, ',', ' ')],
                ['Signales a tort', number_format((int) $m['faux_positifs'], 0, ',', ' ')],
                ['Manques', number_format((int) $m['manques'], 0, ',', ' ')],
                ['Precision de la relecture', sprintf('%.2f %%', $signales > 0 ? (int) $m['vrais_positifs'] / $signales * 100 : 0)],
                ['Rappel de la relecture', sprintf('%.2f %%', $aRevoir > 0 ? (int) $m['vrais_positifs'] / $aRevoir * 100 : 0)],
            ]);

            $detail = $this->cnx->fetchAllAssociative(
                'SELECT t.etat_reel, r.verdict, count(*) n
                   FROM lettrage.relecture r
                   JOIN lettrage_verite.historique t ON t.objet_id = r.code_lettrage
                  GROUP BY 1, 2 ORDER BY 1, 3 DESC');
            $io->table(['État réel', 'Verdict de la relecture', 'Nombre'], array_map(
                static fn (array $r): array => [$r['etat_reel'], $r['verdict'], number_format((int) $r['n'], 0, ',', ' ')],
                $detail));
        }

        $io->success(sprintf('Relecture terminee en %.1f s.', microtime(true) - $t0));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return array{0: string, 1: string}
     */
    private function juger(array $lignes): array
    {
        if (\count($lignes) < 2) {
            return ['a_revoir_incomplet', "Le groupe ne comporte qu'une seule écriture : un lettrage suppose au moins deux jambes."];
        }

        // 1. Un reglement compte deux fois. On le cherche AVANT le solde :
        //    le doublon fait echouer le solde, et « le groupe ne solde pas »
        //    serait un diagnostic juste mais moins utile que « ce reglement est
        //    compte deux fois ». Le comptable n'a pas la meme correction a faire.
        $debits = array_filter($lignes, static fn (array $l): bool => 'D' === $l['sens']);
        $credits = array_filter($lignes, static fn (array $l): bool => 'C' === $l['sens']);
        if (\count($credits) > \count($debits) && 1 === \count($debits)) {
            $montants = array_map(static fn (array $l): float => (float) $l['montant'], $credits);
            $uniques = array_unique(array_map(static fn (float $x): string => number_format($x, 2, '.', ''), $montants));
            if (\count($uniques) < \count($montants)) {
                return ['a_revoir_doublon', sprintf(
                    'Une seule facture pour %d règlements, dont deux du même montant : le règlement est compté deux fois.',
                    \count($credits))];
            }
        }

        // 2. Une cle sectorielle forte qui se contredit.
        foreach (['vin8' => 'numéro de série', 'immatriculation' => 'immatriculation'] as $champ => $libelle) {
            $valeurs = [];
            foreach ($lignes as $l) {
                $v = $l[$champ] ?? null;
                if (null !== $v && '' !== $v) {
                    $valeurs[strtoupper((string) $v)] = true;
                }
            }
            if (\count($valeurs) > 1) {
                return ['a_revoir_contradiction', sprintf(
                    'Le groupe réunit %d %ss différents (%s). Le lettrage a été posé sur des dossiers distincts.',
                    \count($valeurs), $libelle, implode(' contre ', array_keys($valeurs)))];
            }
        }

        // 3. Le solde. C'est verifiable sans rien savoir du dossier.
        $solde = round($this->solde($lignes) * 100) / 100;
        $tol = Tolerances::pour(array_column($lignes, 'date_ecriture'), \count($lignes) > 2);
        if (abs($solde) > $tol['euros']) {
            return ['a_revoir_solde', sprintf(
                'Solde résiduel de %s €, au-delà de la tolérance de %s € applicable à %s. '
                .'Un lettrage qui ne solde pas laisse une créance ouverte dans un compte présenté comme apuré.',
                number_format(abs($solde), 2, ',', ' '),
                number_format($tol['euros'], 0, ',', ' '), $tol['palier'])];
        }

        // 4. Une cle qui ne prouve rien.
        $refs = array_filter(array_map(
            static fn (array $l): string => strtoupper(trim((string) ($l['reference_piece'] ?? ''))), $lignes));
        // `$refs` est deja debarrasse des chaines vides par array_filter ci-dessus.
        $porteuses = array_filter($refs, fn (string $r): bool => !\in_array($r, self::REFERENCES_GENERIQUES, true));
        $series = array_filter(array_map(static fn (array $l): string => (string) ($l['vin8'] ?? ''), $lignes));
        if ([] === $porteuses && [] === $series && [] !== $refs) {
            return ['a_revoir_cle', sprintf(
                'Le lettrage repose sur une référence générique (« %s ») et aucune clé sectorielle. '
                ."Elle ne désigne aucun dossier : le rapprochement n'est pas justifié.",
                (string) reset($refs))];
        }

        return ['conforme', 'Solde conforme, aucune clé contradictoire, aucun doublon détecté.'];
    }

    /** @param list<array<string, mixed>> $lignes */
    private function solde(array $lignes): float
    {
        $c = 0;
        foreach ($lignes as $l) {
            $c += ('D' === $l['sens'] ? 1 : -1) * (int) round((float) $l['montant'] * 100);
        }

        return $c / 100;
    }
}
