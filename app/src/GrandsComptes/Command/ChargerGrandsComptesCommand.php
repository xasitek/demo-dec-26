<?php

declare(strict_types=1);

namespace App\GrandsComptes\Command;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charge l'outil 7 : les dossiers grands comptes, leurs pieces, et la grille
 * documentaire de chaque loueur.
 *
 * Deux schemas, physiquement separes, comme pour les outils 4 et 5 :
 *   `grands_comptes`         ce que le moteur peut lire ;
 *   `grands_comptes_verite`  ce qu'il ne doit jamais lire.
 *
 * Cette commande ne touche a AUCUNE table des outils 4, 5 et 6. Les dossiers
 * charges sont exactement les 3 400 que le monde porte deja, et vers lesquels
 * la file « pieces manquantes » du cockpit pointe.
 */
#[AsCommand(
    name: 'app:grands-comptes:charger',
    description: 'Charge les dossiers grands comptes, leurs pieces et les grilles des loueurs.',
)]
final class ChargerGrandsComptesCommand extends Command
{
    private const SCHEMA = <<<'SQL'
CREATE SCHEMA IF NOT EXISTS grands_comptes;
CREATE SCHEMA IF NOT EXISTS grands_comptes_verite;

DROP TABLE IF EXISTS grands_comptes.piece, grands_comptes.dossier,
  grands_comptes.exigence, grands_comptes.loueur, grands_comptes.type_piece,
  grands_comptes.anomalie_ref, grands_comptes.scenario CASCADE;
DROP TABLE IF EXISTS grands_comptes_verite.dossier, grands_comptes_verite.anomalie CASCADE;

-- Le referentiel documentaire ------------------------------------------------

CREATE TABLE grands_comptes.type_piece (
  code varchar(4) PRIMARY KEY, libelle varchar(60) NOT NULL,
  role varchar(200) NOT NULL, rang int NOT NULL
);

CREATE TABLE grands_comptes.loueur (
  loueur_id varchar(16) PRIMARY KEY, nom varchar(60) NOT NULL,
  delai_paiement int NOT NULL,
  accepte_pv_electronique boolean NOT NULL,
  exige_tampon boolean NOT NULL,
  tolerance_centimes int NOT NULL,
  nb_obligatoires int NOT NULL
);

-- La grille : c'est ELLE qui commande. Aucune piece n'est obligatoire
-- universellement, et reclamer a un site une piece que son payeur ne demande
-- pas est precisement la faute que l'outil corrige.
CREATE TABLE grands_comptes.exigence (
  loueur_id varchar(16) NOT NULL, type_piece varchar(4) NOT NULL,
  exigence varchar(20) NOT NULL,
  PRIMARY KEY (loueur_id, type_piece)
);

CREATE TABLE grands_comptes.anomalie_ref (
  code varchar(28) PRIMARY KEY, type_piece varchar(4) NOT NULL,
  gravite varchar(12) NOT NULL, libelle varchar(120) NOT NULL,
  suite varchar(24) NOT NULL, rang int NOT NULL
);

CREATE TABLE grands_comptes.scenario (
  code varchar(12) PRIMARY KEY, poids numeric(6,4) NOT NULL,
  libelle varchar(120) NOT NULL, anomalies varchar(200) NOT NULL,
  nb_anomalies int NOT NULL
);

-- Les dossiers et leurs pieces ----------------------------------------------

CREATE TABLE grands_comptes.dossier (
  id varchar(16) PRIMARY KEY,
  loueur_id varchar(16) NOT NULL,
  facture_id varchar(20), vehicule_id varchar(16),
  immatriculation varchar(16), vin varchar(24), vin8 varchar(8),
  energie varchar(16), modele varchar(80),
  etablissement_id varchar(16), societe_id varchar(16),
  montant_facture numeric(14,2) NOT NULL,
  numero_facture varchar(30),
  date_livraison date NOT NULL,
  secretaire_id varchar(16),
  cohorte varchar(20) NOT NULL,
  code_scenario varchar(12) NOT NULL,
  statut varchar(16) NOT NULL,
  premier_reglement boolean NOT NULL
);
CREATE INDEX idx_gc_dos_loueur ON grands_comptes.dossier (loueur_id);
CREATE INDEX idx_gc_dos_etab ON grands_comptes.dossier (etablissement_id);
CREATE INDEX idx_gc_dos_cohorte ON grands_comptes.dossier (cohorte);
CREATE INDEX idx_gc_dos_statut ON grands_comptes.dossier (statut);
CREATE INDEX idx_gc_dos_secretaire ON grands_comptes.dossier (secretaire_id);
CREATE INDEX idx_gc_dos_immat ON grands_comptes.dossier (immatriculation);

-- Une piece porte DEUX jeux de valeurs : ce que la secretaire a declare au
-- depot, et ce que le controle lit sur le document. Les confronter est tout
-- l'objet de l'ecran de controle -- et c'est ce que le triptyque affiche.
CREATE TABLE grands_comptes.piece (
  id varchar(16) PRIMARY KEY,
  dossier_id varchar(16) NOT NULL,
  type_piece varchar(4) NOT NULL,
  exigence varchar(20) NOT NULL,
  presente boolean NOT NULL,
  lisible boolean NOT NULL,
  numero_declare varchar(40), montant_declare numeric(14,2), date_declaree date,
  numero_lu varchar(40), montant_lu numeric(14,2), date_lue date,
  numero_commande_lu varchar(40),
  immatriculation_lue varchar(16),
  signe boolean, tampon boolean,
  ligne_batterie boolean, prix_batterie numeric(12,2),
  prix_batterie_ht numeric(12,2), prix_batterie_ttc numeric(12,2),
  mention_devise boolean,
  adresse_facturation varchar(12)
);
CREATE INDEX idx_gc_piece_dossier ON grands_comptes.piece (dossier_id);
CREATE INDEX idx_gc_piece_type ON grands_comptes.piece (type_piece);

-- La verite. Le moteur ne la lit JAMAIS : elle sert a le mesurer. ------------

CREATE TABLE grands_comptes_verite.dossier (
  objet_id varchar(16) PRIMARY KEY,
  verdict_attendu varchar(16) NOT NULL,
  nb_anomalies int NOT NULL,
  anomalies_attendues varchar(300) NOT NULL,
  code_scenario varchar(12) NOT NULL,
  cohorte varchar(20) NOT NULL,
  exigences_du_loueur varchar(60) NOT NULL
);

CREATE TABLE grands_comptes_verite.anomalie (
  objet_id varchar(16) NOT NULL,
  code_anomalie varchar(28) NOT NULL,
  type_piece varchar(4) NOT NULL,
  gravite varchar(12) NOT NULL,
  suite varchar(24) NOT NULL,
  etablie boolean NOT NULL,
  PRIMARY KEY (objet_id, code_anomalie)
);

-- Le controle : ce que le moteur a decide, et ce qu'un humain en a fait. -----

CREATE TABLE IF NOT EXISTS grands_comptes.controle (
  dossier_id varchar(16) PRIMARY KEY,
  verdict varchar(16) NOT NULL,
  nb_anomalies int NOT NULL,
  nb_bloquantes int NOT NULL,
  pieces_attendues int NOT NULL,
  pieces_presentes int NOT NULL,
  arret varchar(40),
  duree_ms numeric(10,3),
  calcule_le timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_gc_ctrl_verdict ON grands_comptes.controle (verdict);

CREATE TABLE IF NOT EXISTS grands_comptes.controle_anomalie (
  dossier_id varchar(16) NOT NULL,
  code_anomalie varchar(28) NOT NULL,
  type_piece varchar(4) NOT NULL,
  gravite varchar(12) NOT NULL,
  suite varchar(24) NOT NULL,
  attendu varchar(80), trouve varchar(80),
  motif varchar(300) NOT NULL,
  PRIMARY KEY (dossier_id, code_anomalie)
);

-- La trace des actes humains. Hors du DROP : elle porte le travail fait dans
-- la demonstration, et un rechargement des donnees ne doit pas l'effacer.
CREATE TABLE IF NOT EXISTS grands_comptes.acte (
  id bigserial PRIMARY KEY,
  dossier_id varchar(16) NOT NULL,
  type varchar(24) NOT NULL,
  piece_id varchar(16),
  code_anomalie varchar(28),
  commentaire varchar(400),
  auteur varchar(80) NOT NULL,
  role varchar(24),
  fait_le timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_gc_acte_dossier ON grands_comptes.acte (dossier_id, fait_le DESC);
CREATE INDEX IF NOT EXISTS idx_gc_acte_auteur ON grands_comptes.acte (auteur, fait_le DESC);
SQL;

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Chargement des dossiers grands comptes');

        $monde = \dirname(__DIR__, 3).'/../public/donnees/monde';
        $verite = \dirname(__DIR__, 3).'/../public/donnees/verite';

        $this->cnx->executeStatement(self::SCHEMA);
        $io->text('Schemas et tables crees.');

        $rapport = [];

        $rapport[] = $this->charger('grands_comptes.type_piece',
            ['code', 'libelle', 'role', 'rang'], $this->lire($monde, 'ref_type_piece'));

        $rapport[] = $this->charger('grands_comptes.loueur',
            ['loueur_id', 'nom', 'delai_paiement', 'accepte_pv_electronique', 'exige_tampon',
                'tolerance_centimes', 'nb_obligatoires'], $this->lire($monde, 'ref_loueur_grille'));

        $rapport[] = $this->charger('grands_comptes.exigence',
            ['loueur_id', 'type_piece', 'exigence'], $this->lire($monde, 'ref_exigence_piece'));

        $rapport[] = $this->charger('grands_comptes.anomalie_ref',
            ['code', 'type_piece', 'gravite', 'libelle', 'suite', 'rang'],
            $this->lire($monde, 'ref_anomalie'));

        $rapport[] = $this->charger('grands_comptes.scenario',
            ['code', 'poids', 'libelle', 'anomalies', 'nb_anomalies'],
            $this->lire($monde, 'ref_scenario_gc'));

        $rapport[] = $this->charger('grands_comptes.dossier',
            ['id', 'loueur_id', 'facture_id', 'vehicule_id', 'immatriculation', 'vin', 'vin8',
                'energie', 'modele', 'etablissement_id', 'societe_id', 'montant_facture',
                'numero_facture', 'date_livraison', 'secretaire_id', 'cohorte', 'code_scenario',
                'statut', 'premier_reglement'],
            $this->lire($monde, 'ops_dossier_gc'));

        $rapport[] = $this->charger('grands_comptes.piece',
            ['id', 'dossier_id', 'type_piece', 'exigence', 'presente', 'lisible',
                'numero_declare', 'montant_declare', 'date_declaree',
                'numero_lu', 'montant_lu', 'date_lue', 'numero_commande_lu',
                'immatriculation_lue', 'signe', 'tampon', 'ligne_batterie', 'prix_batterie',
                'prix_batterie_ht', 'prix_batterie_ttc', 'mention_devise', 'adresse_facturation'],
            $this->lire($monde, 'ops_piece_gc'));

        $io->table(['Table', 'Lignes', 'Millisecondes'], $rapport);

        $io->section('Verite (schema separe, jamais lu par le moteur)');
        $rapportV = [];
        $rapportV[] = $this->charger('grands_comptes_verite.dossier',
            ['objet_id', 'verdict_attendu', 'nb_anomalies', 'anomalies_attendues',
                'code_scenario', 'cohorte', 'exigences_du_loueur'],
            $this->lire($verite, 'truth_dossier_gc'));
        $rapportV[] = $this->charger('grands_comptes_verite.anomalie',
            ['objet_id', 'code_anomalie', 'type_piece', 'gravite', 'suite', 'etablie'],
            $this->lire($verite, 'truth_anomalie_gc'));
        $io->table(['Table', 'Lignes', 'Millisecondes'], $rapportV);

        // ---- Le controle d'assiette : les dossiers charges doivent etre
        // exactement ceux du monde, et ceux vers lesquels le cockpit pointe.
        $io->section("Controle d'assiette");
        $dossiers = (int) $this->cnx->fetchOne('SELECT count(*) FROM grands_comptes.dossier');
        $partages = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM grands_comptes.dossier d
               JOIN pilotage.cause_ouverture c ON c.facture_id = d.facture_id
              WHERE c.cause = 'piece_manquante'");
        $grilles = (int) $this->cnx->fetchOne(
            'SELECT count(DISTINCT signature) FROM (
               SELECT loueur_id, string_agg(type_piece || \'=\' || exigence, \',\' ORDER BY type_piece) signature
                 FROM grands_comptes.exigence GROUP BY loueur_id) x');

        $io->table(['Controle', 'Valeur'], [
            ['Dossiers charges', number_format($dossiers, 0, ',', ' ')],
            ['Dont lies a une creance bloquee par une piece (outil 6)', number_format($partages, 0, ',', ' ')],
            ['Grilles documentaires distinctes sur 8 loueurs', (string) $grilles],
        ]);

        if ($grilles < 6) {
            $io->error(sprintf('Seulement %d grilles distinctes : la difference de grille ne se verrait pas.', $grilles));

            return Command::FAILURE;
        }

        $io->success('Dossiers grands comptes charges. Les outils 4, 5 et 6 sont intacts.');

        return Command::SUCCESS;
    }

    /**
     * Insere une table en lots.
     *
     * @param list<string>               $colonnes
     * @param list<array<string, mixed>> $lignes
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function charger(string $table, array $colonnes, array $lignes): array
    {
        $t0 = microtime(true);
        $this->cnx->executeStatement('TRUNCATE '.$table);

        $paquet = 400;
        $liste = '('.implode(', ', $colonnes).')';
        for ($i = 0; $i < \count($lignes); $i += $paquet) {
            $tranche = \array_slice($lignes, $i, $paquet);
            $valeurs = [];
            $args = [];
            foreach ($tranche as $ligne) {
                $trous = [];
                foreach ($colonnes as $c) {
                    $trous[] = '?';
                    $v = $ligne[$c] ?? null;
                    $args[] = \is_bool($v) ? ($v ? 'true' : 'false') : $v;
                }
                $valeurs[] = '('.implode(', ', $trous).')';
            }
            $this->cnx->executeStatement(
                'INSERT INTO '.$table.' '.$liste.' VALUES '.implode(', ', $valeurs), $args);
        }

        return [$table, number_format(\count($lignes), 0, ',', ' '),
            number_format((microtime(true) - $t0) * 1000, 0, ',', ' ')];
    }

    /**
     * Lit une table colonnaire du monde ou de la verite.
     *
     * @return list<array<string, mixed>>
     */
    private function lire(string $dossier, string $table): array
    {
        $brut = file_get_contents($dossier.'/'.$table.'.json');
        if (false === $brut) {
            throw new RuntimeException('Table introuvable : '.$table);
        }
        /** @var array{n: int, cols: array<string, array{v?: list<mixed>, d?: list<mixed>, i?: list<int>}>} $t */
        $t = json_decode($brut, true, 512, \JSON_THROW_ON_ERROR);

        $colonnes = [];
        foreach ($t['cols'] as $nom => $c) {
            if (isset($c['d'], $c['i'])) {
                $colonnes[$nom] = array_map(static fn (int $k): mixed => $c['d'][$k], $c['i']);
            } else {
                $colonnes[$nom] = $c['v'] ?? [];
            }
        }

        $lignes = [];
        for ($i = 0; $i < $t['n']; ++$i) {
            $ligne = [];
            foreach ($colonnes as $nom => $valeurs) {
                $ligne[$nom] = $valeurs[$i] ?? null;
            }
            $lignes[] = $ligne;
        }

        return $lignes;
    }
}
