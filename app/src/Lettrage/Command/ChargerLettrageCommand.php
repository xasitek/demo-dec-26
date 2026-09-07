<?php

declare(strict_types=1);

namespace App\Lettrage\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charge le monde du lettrage, et sa verite dans un schema SEPARE.
 *
 * Deux schemas, deux mondes. `lettrage` porte ce que le moteur voit ;
 * `lettrage_verite` porte la reponse, et seul le module de mesure l'ouvre,
 * apres execution. La separation est physique : ce n'est pas une convention de
 * nommage, c'est une frontiere que le code du moteur ne franchit pas.
 */
#[AsCommand(
    name: 'app:lettrage:charger-univers',
    description: 'Charge les ecritures, les lots et les lettrages a relire dans la base de demonstration.',
)]
final class ChargerLettrageCommand extends Command
{
    private const SCHEMA = <<<'SQL'
CREATE SCHEMA IF NOT EXISTS lettrage;
CREATE SCHEMA IF NOT EXISTS lettrage_verite;

DROP TABLE IF EXISTS lettrage.decision, lettrage.groupe_ligne, lettrage.indice,
  lettrage.relecture, lettrage.cascade_etape, lettrage.ecriture, lettrage.lot,
  lettrage.historique CASCADE;
DROP TABLE IF EXISTS lettrage_verite.lot, lettrage_verite.historique CASCADE;

CREATE TABLE lettrage.ecriture (
  id varchar(16) PRIMARY KEY,
  societe_id varchar(16), etablissement_id varchar(16), client_id varchar(16),
  compte varchar(12) NOT NULL, journal varchar(6), sens char(1) NOT NULL,
  montant numeric(14,2) NOT NULL, date_ecriture date NOT NULL,
  vin varchar(24), vin8 varchar(8), immatriculation varchar(16),
  ordre_reparation varchar(24), reference_piece varchar(40),
  lettrage varchar(16), lot_demo varchar(16), facture_id varchar(16),
  -- Code client porte par l'ecriture quand il differe du referentiel : c'est
  -- ainsi qu'un compte ferme se signale, par un prefixe.
  code_client_demo varchar(24)
);
CREATE INDEX idx_let_ecr_client ON lettrage.ecriture (client_id) WHERE lettrage IS NULL;
CREATE INDEX idx_let_ecr_lot ON lettrage.ecriture (lot_demo);
CREATE INDEX idx_let_ecr_lettrage ON lettrage.ecriture (lettrage);
CREATE INDEX idx_let_ecr_vin8 ON lettrage.ecriture (vin8);

CREATE TABLE lettrage.lot (
  id varchar(16) PRIMARY KEY, ancre_id varchar(16) NOT NULL,
  societe_id varchar(16), etablissement_id varchar(16), client_id varchar(16),
  nb_ecritures int NOT NULL, montant numeric(14,2) NOT NULL, cohorte varchar(20) NOT NULL
);
CREATE INDEX idx_let_lot_cohorte ON lettrage.lot (cohorte);

CREATE TABLE lettrage.historique (
  code_lettrage varchar(16) PRIMARY KEY,
  societe_id varchar(16), etablissement_id varchar(16), client_id varchar(16),
  nb_ecritures int NOT NULL,
  montant_debit numeric(14,2) NOT NULL, montant_credit numeric(14,2) NOT NULL,
  pose_le date NOT NULL
);

CREATE TABLE lettrage.decision (
  lot_id varchar(16) PRIMARY KEY,
  verdict varchar(16) NOT NULL, methode varchar(6), arret varchar(32),
  motif varchar(400), nb_indices int, nb_indices_forts int,
  solde numeric(14,2), montant numeric(14,2), nb_ecritures int,
  duree_ms numeric(10,3), iterations int, solutions int,
  calcule_le timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_let_dec_verdict ON lettrage.decision (verdict);

CREATE TABLE lettrage.indice (
  lot_id varchar(16) NOT NULL, rang int NOT NULL,
  cle varchar(24) NOT NULL, libelle varchar(120) NOT NULL,
  constat varchar(240), fort boolean NOT NULL
);
CREATE INDEX idx_let_ind_lot ON lettrage.indice (lot_id, rang);

CREATE TABLE lettrage.cascade_etape (
  methode varchar(6) PRIMARY KEY, rang int,
  lignes_entrantes bigint, lignes_consommees bigint, groupes bigint,
  montant numeric(16,2), duree_ms numeric(12,3)
);

CREATE TABLE lettrage.relecture (
  code_lettrage varchar(16) PRIMARY KEY,
  verdict varchar(24) NOT NULL, motif varchar(400),
  solde numeric(14,2), nb_ecritures int
);

CREATE TABLE lettrage_verite.lot (
  objet_id varchar(16) PRIMARY KEY, verdict varchar(24) NOT NULL,
  groupe_attendu text, methode_attendue varchar(6),
  code_scenario varchar(12) NOT NULL, note varchar(240)
);
CREATE TABLE lettrage_verite.historique (
  objet_id varchar(16) PRIMARY KEY, etat_reel varchar(24) NOT NULL,
  a_revoir boolean NOT NULL, ecritures text
);
SQL;

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title("Chargement de l'univers de lettrage");

        $racine = \dirname(__DIR__, 3);
        $monde = $racine.'/../public/donnees/monde';
        $verite = $racine.'/../public/donnees/verite';

        $this->cnx->executeStatement(self::SCHEMA);
        $io->text('Schemas et tables crees.');

        $rapport = [];

        $ecritures = $this->lire($monde, 'ops_ecriture');
        $rapport[] = $this->inserer('lettrage.ecriture',
            ['id', 'societe_id', 'etablissement_id', 'client_id', 'compte', 'journal', 'sens',
                'montant', 'date_ecriture', 'vin', 'vin8', 'immatriculation', 'ordre_reparation',
                'reference_piece', 'lettrage', 'lot_demo', 'facture_id', 'code_client_demo'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['societe_id'], $l['etablissement_id'], $l['client_id'],
                $l['compte'], $l['journal'], $l['sens'], $l['montant'], $l['date'],
                $l['vin'], null !== $l['vin'] ? substr((string) $l['vin'], -8) : null,
                $l['immatriculation'], $l['ordre_reparation'], $l['reference_piece'],
                $l['lettrage'], $l['lot_demo'] ?? null, $l['facture_id'],
                $l['code_client_demo'] ?? null,
            ], $ecritures));

        $lots = $this->lire($monde, 'ops_lettrage_lot');
        $rapport[] = $this->inserer('lettrage.lot',
            ['id', 'ancre_id', 'societe_id', 'etablissement_id', 'client_id', 'nb_ecritures', 'montant', 'cohorte'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['ancre_id'], $l['societe_id'], $l['etablissement_id'],
                $l['client_id'], $l['nb_ecritures'], $l['montant'], $l['cohorte'],
            ], $lots));

        $histo = $this->lire($monde, 'ops_lettrage_historique');
        $rapport[] = $this->inserer('lettrage.historique',
            ['code_lettrage', 'societe_id', 'etablissement_id', 'client_id', 'nb_ecritures',
                'montant_debit', 'montant_credit', 'pose_le'],
            array_map(static fn (array $l): array => [
                $l['code_lettrage'], $l['societe_id'], $l['etablissement_id'], $l['client_id'],
                $l['nb_ecritures'], $l['montant_debit'], $l['montant_credit'], $l['pose_le'],
            ], $histo));

        // La verite, dans son schema separe. C'est le seul endroit du chargement
        // qui l'ouvre, et aucun moteur ne lit ce schema.
        $vLots = $this->lire($verite, 'truth_lettrage');
        $rapport[] = $this->inserer('lettrage_verite.lot',
            ['objet_id', 'verdict', 'groupe_attendu', 'methode_attendue', 'code_scenario', 'note'],
            array_map(static fn (array $l): array => [
                $l['objet_id'], $l['verdict'], $l['groupe_attendu'],
                $l['methode_attendue'], $l['code_scenario'], mb_substr((string) $l['note'], 0, 240),
            ], $vLots));

        $vHisto = $this->lire($verite, 'truth_lettrage_historique');
        $rapport[] = $this->inserer('lettrage_verite.historique',
            ['objet_id', 'etat_reel', 'a_revoir', 'ecritures'],
            array_map(static fn (array $l): array => [
                $l['objet_id'], $l['etat_reel'], $l['a_revoir'] ? 1 : 0, $l['ecritures'],
            ], $vHisto));

        $io->table(['Table', 'Lignes', 'Millisecondes'], $rapport);
        $total = array_sum(array_map(static fn (array $r): int => (int) str_replace(' ', '', $r[1]), $rapport));
        $io->success(sprintf('%s lignes chargees. Univers synthetique, graine fixe, aucune donnee reelle.',
            number_format($total, 0, ',', ' ')));

        return Command::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function lire(string $dossier, string $table): array
    {
        $brut = json_decode((string) file_get_contents($dossier.'/'.$table.'.json'), true, 512, \JSON_THROW_ON_ERROR);
        $noms = array_map('strval', array_keys($brut['cols']));
        $lignes = [];
        for ($k = 0; $k < $brut['n']; ++$k) {
            $o = [];
            foreach ($noms as $nom) {
                $c = $brut['cols'][$nom];
                $o[$nom] = isset($c['d']) ? $c['d'][$c['i'][$k]] : $c['v'][$k];
            }
            $lignes[] = $o;
        }

        return $lignes;
    }

    /**
     * @param list<string>      $colonnes
     * @param list<list<mixed>> $lignes
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function inserer(string $table, array $colonnes, array $lignes): array
    {
        $t0 = microtime(true);
        $paquet = 500;
        $trous = '('.implode(',', array_fill(0, \count($colonnes), '?')).')';
        for ($i = 0; $i < \count($lignes); $i += $paquet) {
            $tranche = \array_slice($lignes, $i, $paquet);
            if ([] === $tranche) {
                break;
            }
            $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(',', $colonnes),
                implode(',', array_fill(0, \count($tranche), $trous)));
            $this->cnx->executeStatement($sql, array_merge(...array_map('array_values', $tranche)));
        }

        return [$table, number_format(\count($lignes), 0, ',', ' '), (string) (int) ((microtime(true) - $t0) * 1000)];
    }
}
