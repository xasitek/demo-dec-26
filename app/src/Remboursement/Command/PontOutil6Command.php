<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le pont de l'outil 6 vers l'outil 8, en couche ADDITIVE.
 *
 * D'OU VIENT LE BESOIN. L'outil 6 porte une file de credits de comptes clients
 * non lettres : de l'argent recu qui dort sur un compte 411. Certains de ces
 * credits sont des trop-percus, et un trop-percu se rend au client -- c'est
 * exactement le metier de l'outil 8. Sans pont, le comptable voit le credit
 * dans un outil et doit retrouver le dossier a la main dans l'autre.
 *
 * CE QUE LE PONT NE FAIT PAS. Il ne modifie rien du monde fige de l'outil 6 :
 * ni une ecriture, ni un lettrage, ni une cause d'ouverture. Il ecrit dans une
 * table qui n'existait pas, et l'outil 6 s'y joint en lecture.
 *
 * TROIS QUALITES DE LIEN, ET ON NE LES CONFOND JAMAIS.
 *
 *   - IDENTITE. Meme client ET meme montant au centime. Le credit et le
 *     dossier parlent du meme argent : le libelle dit « Dossier correspondant ».
 *
 *   - RELATION DECLAREE. Le monde synthetique a explicitement pose la relation.
 *     Elle vaut identite, et sa provenance le dit.
 *
 *   - ASSOCIATION. Meme client, montants differents. C'est une piste, pas une
 *     identite : le libelle dit « Dossier associe », et il ne dira jamais autre
 *     chose. Presenter une association comme une identite serait affirmer
 *     devant un jury un rapprochement qu'on ne peut pas prouver.
 */
#[AsCommand(
    name: 'app:remboursement:pont-outil-6',
    description: 'Relie les credits non lettres de l\'outil 6 aux dossiers de remboursement.',
)]
final class PontOutil6Command extends Command
{
    /** La date de creation de l'univers de demonstration. */
    private const UNIVERS_CREE_LE = '2026-09-07';

    private const SCHEMA = <<<'SQL'
    DROP TABLE IF EXISTS remboursement.pont_o6_demo CASCADE;

    -- Le pont outil 6 -> outil 8. Table ADDITIVE : rien du monde fige de
    -- l'outil 6 n'est touche, et l'outil 6 s'y joint en lecture seule.
    CREATE TABLE remboursement.pont_o6_demo (
      ecriture_id varchar(24) PRIMARY KEY,
      dossier_id integer NOT NULL,
      reference varchar(32) NOT NULL,
      client_id varchar(24) NOT NULL,
      montant_ecriture numeric(14,2) NOT NULL,
      montant_dossier numeric(14,2) NOT NULL,
      qualite varchar(24) NOT NULL,
      preuve varchar(120) NOT NULL,
      provenance varchar(48) NOT NULL,
      univers_cree_le date NOT NULL
    );
    CREATE INDEX idx_pont_o6_dossier ON remboursement.pont_o6_demo (dossier_id);
    CREATE INDEX idx_pont_o6_qualite ON remboursement.pont_o6_demo (qualite);
    SQL;

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Pont outil 6 → outil 8');
        $io->text('Couche additive : le monde fige de l\'outil 6 n\'est pas touche.');

        $this->cnx->executeStatement(self::SCHEMA);

        // Les credits de comptes clients qui dorment, exactement la file de
        // l'outil 6 : sens credit, compte 411, aucun lot, aucun lettrage.
        $credits = $this->cnx->fetchAllAssociative(
            "SELECT e.id, e.client_id, e.montant
               FROM lettrage.ecriture e
              WHERE e.sens = 'C' AND e.compte LIKE '411%' AND e.lot_demo IS NULL
                AND (e.lettrage IS NULL OR e.lettrage = '')
                AND e.client_id IS NOT NULL
              ORDER BY e.montant DESC");

        // Les dossiers de trop-percu, avec le client dont ils portent le code.
        // On lit le CODE ICAR du dossier, qui est le code balance du client :
        // c'est l'identite que le monde a posee, pas une devinette.
        $dossiers = $this->cnx->fetchAllAssociative(
            "SELECT d.id, d.reference, d.montant, d.code_icar, c.id AS client_id
               FROM remboursement.dossier d
               JOIN affectation.client c ON c.code_balance = d.code_icar
              WHERE d.motif = 'trop_percu' AND d.code_icar IS NOT NULL
              ORDER BY d.id");

        $parClient = [];
        foreach ($dossiers as $d) {
            $parClient[(string) $d['client_id']][] = $d;
        }

        $io->text(sprintf('%s credits non lettres, %s dossiers de trop-percu rattachables.',
            number_format(\count($credits), 0, ',', ' '),
            number_format(\count($dossiers), 0, ',', ' ')));

        // Attribution GLOUTONNE, avec un ordre deterministe : un dossier ne
        // sert qu'une fois, et le tri secondaire par identifiant rend le
        // resultat reproductible d'un rechargement a l'autre.
        $servis = [];
        $poses = 0;
        foreach ($credits as $credit) {
            $candidats = $parClient[(string) $credit['client_id']] ?? [];
            if ([] === $candidats) {
                continue;
            }

            $choisi = null;
            $qualite = null;
            // 1. l'identite : meme client, meme montant au centime.
            foreach ($candidats as $d) {
                if (isset($servis[(int) $d['id']])) {
                    continue;
                }
                if (abs((float) $d['montant'] - (float) $credit['montant']) < 0.005) {
                    $choisi = $d;
                    $qualite = 'identite';
                    break;
                }
            }
            // 2. a defaut, une association : meme client, montant different.
            if (null === $choisi) {
                foreach ($candidats as $d) {
                    if (isset($servis[(int) $d['id']])) {
                        continue;
                    }
                    $choisi = $d;
                    $qualite = 'association';
                    break;
                }
            }
            if (null === $choisi || null === $qualite) {
                continue;
            }

            $servis[(int) $choisi['id']] = true;
            $this->cnx->insert('remboursement.pont_o6_demo', [
                'ecriture_id' => (string) $credit['id'],
                'dossier_id' => (int) $choisi['id'],
                'reference' => (string) $choisi['reference'],
                'client_id' => (string) $credit['client_id'],
                'montant_ecriture' => (string) $credit['montant'],
                'montant_dossier' => (string) $choisi['montant'],
                'qualite' => $qualite,
                'preuve' => 'identite' === $qualite
                    ? 'meme code client ICAR et meme montant au centime'
                    : 'meme code client ICAR, montants differents',
                'provenance' => 'identite' === $qualite
                    ? 'identite portee par le monde'
                    : 'association de demonstration',
                'univers_cree_le' => self::UNIVERS_CREE_LE,
            ]);
            ++$poses;
        }

        // ------------------------------------------------ ce que le pont donne
        $io->section('Ce que le pont relie');
        $parQualite = $this->cnx->fetchAllAssociative(
            'SELECT qualite, count(*) n, round(sum(montant_ecriture)::numeric, 2) montant
               FROM remboursement.pont_o6_demo GROUP BY qualite ORDER BY n DESC');
        $io->table(['Qualite', 'Libelle a l\'ecran', 'Liens', 'Montant des credits'],
            array_map(static fn (array $l): array => [
                (string) $l['qualite'],
                'identite' === $l['qualite'] ? 'Dossier correspondant' : 'Dossier associe',
                (string) $l['n'],
                number_format((float) $l['montant'], 2, ',', ' ').' €',
            ], $parQualite));

        // ------------------------------------------------ controles d'assiette
        $io->section('Controles d\'assiette');
        $lignes = [];
        $fautes = [];

        $doubles = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM (SELECT dossier_id FROM remboursement.pont_o6_demo
              GROUP BY dossier_id HAVING count(*) > 1) x');
        $lignes[] = ['Un dossier ne sert qu\'une fois', 0 === $doubles ? 'OK' : 'ECHEC ('.$doubles.')'];
        if (0 !== $doubles) {
            $fautes[] = 'un dossier sert plusieurs credits';
        }

        $identitesFausses = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM remboursement.pont_o6_demo
              WHERE qualite = 'identite' AND abs(montant_ecriture - montant_dossier) >= 0.005");
        $lignes[] = ['Toute identite porte le meme montant', 0 === $identitesFausses ? 'OK' : 'ECHEC'];
        if (0 !== $identitesFausses) {
            $fautes[] = 'une identite ne porte pas le meme montant';
        }

        $creditsIntacts = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM lettrage.ecriture
              WHERE sens = 'C' AND compte LIKE '411%' AND lot_demo IS NULL
                AND (lettrage IS NULL OR lettrage = '')");
        $lignes[] = ['File de credits de l\'outil 6, inchangee', (string) $creditsIntacts];

        $io->table(['Controle', 'Resultat'], $lignes);

        $io->text(sprintf('%d liens poses sur %s credits, soit %.1f %% de la file.',
            $poses, number_format(\count($credits), 0, ',', ' '),
            [] === $credits ? 0 : $poses / \count($credits) * 100));
        $io->text('Une ASSOCIATION n\'est jamais presentee comme une identite : le libelle');
        $io->text('affiche « Dossier associe », et la preuve dit pourquoi.');

        if ([] !== $fautes) {
            $io->error(implode(' ; ', $fautes));

            return Command::FAILURE;
        }
        $io->success('Le pont est coherent. Aucune donnee figee n\'a ete touchee.');

        return Command::SUCCESS;
    }
}
