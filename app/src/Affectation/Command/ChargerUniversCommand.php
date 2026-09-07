<?php

declare(strict_types=1);

namespace App\Affectation\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charge l'univers synthetique dans la base de demonstration.
 *
 * La source est la fabrique deterministe du projet, et rien d'autre : aucune
 * base du groupe, aucun classeur, aucun export. Les fichiers lus sont ceux
 * qu'elle produit, a graine fixe, donc rejouables a l'identique.
 *
 * Deux schemas, et la separation est PHYSIQUE :
 *   - `affectation`        le monde, ce que le moteur traite ;
 *   - `affectation_verite` la bonne reponse, que le moteur ne lit jamais.
 */
#[AsCommand(
    name: 'app:affectation:charger-univers',
    description: 'Charge l\'univers synthetique (virements, factures, payeurs) dans la base de demonstration.',
)]
final class ChargerUniversCommand extends Command
{
    public function __construct(
        private readonly Connection $cnx,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $racine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sans-verite', null, InputOption::VALUE_NONE,
            'Ne charge pas la verite de reference (utile pour prouver que le moteur s\'en passe).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Chargement de l\'univers synthetique');

        $monde = $this->racine.'/../public/donnees/monde';
        $verite = $this->racine.'/../public/donnees/verite';
        if (!is_dir($monde)) {
            $io->error("Fabrique introuvable : {$monde}. Lancez d'abord node fabrique/construire.js.");

            return Command::FAILURE;
        }

        $this->creerSchemas();
        $io->text('Schemas et tables crees.');

        $t0 = microtime(true);
        $rapport = [];

        // ---- referentiel des IBAN. On ne stocke JAMAIS l'IBAN en clair :
        //      une empreinte pour comparer, un masque pour afficher.
        $ibans = $this->lire($monde, 'ref_iban');
        $rapport[] = $this->inserer('affectation.iban',
            ['id', 'masque', 'empreinte', 'titulaire_client_id', 'occurrences', 'premiere_apparition'],
            array_map(static fn (array $l): array => [
                $l['id'],
                substr((string) $l['iban'], 0, 4).' •••• •••• '.substr((string) $l['iban'], -4),
                $l['empreinte'],
                $l['titulaire'],
                $l['occurrences'],
                $l['premiere_apparition'],
            ], $ibans));

        $clients = $this->lire($monde, 'ref_client');
        $rapport[] = $this->inserer('affectation.client',
            ['id', 'nom', 'nom_normalise', 'type', 'code_referentiel', 'code_balance', 'siren', 'iban_id', 'rythme'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['nom'], \App\Affectation\Moteur\Normalisation::nom($l['nom']), $l['type'],
                $l['code_referentiel'], $l['code_balance'], $l['siren'], $l['iban_id'], $l['rythme'],
            ], $clients));

        $vehicules = $this->lire($monde, 'ref_vehicule');
        $rapport[] = $this->inserer('affectation.vehicule',
            ['id', 'serie', 'serie8', 'immatriculation', 'client_id', 'modele'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['vin'], substr((string) $l['vin'], -8),
                $l['immatriculation'], $l['client_id'], $l['modele'],
            ], $vehicules));

        $factures = $this->lire($monde, 'ops_facture');
        $rapport[] = $this->inserer('affectation.facture',
            ['id', 'numero', 'client_id', 'societe_id', 'etablissement_id', 'vehicule_id', 'type', 'date_facture', 'echeance', 'montant', 'statut', 'affectee'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['numero'], $l['client_id'], $l['societe_id'], $l['etablissement_id'],
                $l['vehicule_id'], $l['type'], $l['date'], $l['echeance'], $l['montant'], $l['statut'],
                $l['affectee'] ? 1 : 0,
            ], $factures));

        $profils = $this->lire($monde, 'ref_payeur_profil');
        $rapport[] = $this->inserer('affectation.payeur_profil',
            ['client_id', 'nb_reglements', 'montant_moyen', 'montant_min', 'montant_max', 'societes_reglees', 'nb_societes', 'rythme', 'dernier_reglement', 'motif_reference'],
            array_map(static fn (array $l): array => [
                $l['client_id'], $l['nb_reglements'], $l['montant_moyen'], $l['montant_min'],
                $l['montant_max'], $l['societes_reglees'], $l['nb_societes'], $l['rythme'],
                $l['dernier_reglement'], $l['motif_reference'],
            ], $profils));

        $virements = $this->lire($monde, 'ops_virement');
        $rapport[] = $this->inserer('affectation.virement',
            ['id', 'date_operation', 'montant', 'libelle', 'nom_donneur_ordre', 'reference_bout_en_bout', 'banque_id', 'iban_emetteur_id', 'societe_id', 'sens_mixte', 'vedette', 'cohorte'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['date'], $l['montant'], $l['libelle'], $l['nom_donneur_ordre'],
                $l['reference_bout_en_bout'], $l['banque_id'], $l['iban_emetteur_id'],
                $l['societe_id'], $l['sens_mixte'] ? 1 : 0, $l['vedette'] ? 1 : 0, $l['cohorte'],
            ], $virements));

        $etabs = $this->lire($monde, 'ref_etablissement');
        $rapport[] = $this->inserer('affectation.etablissement',
            ['id', 'code', 'nom', 'societe_id', 'service'],
            array_map(static fn (array $l): array => [
                $l['id'], $l['code'], $l['nom'], $l['societe_id'], $l['service'],
            ], $etabs));

        // ---- la verite, dans son schema separe
        if (!$input->getOption('sans-verite') && is_dir($verite)) {
            $tv = $this->lire($verite, 'truth_virement');
            $tr = $this->lire($verite, 'truth_reglement');
            $parObjet = [];
            foreach ($tr as $l) {
                $parObjet[$l['objet_id']] = $l;
            }
            $rapport[] = $this->inserer('affectation_verite.virement',
                ['objet_id', 'vrai_client', 'vraie_societe', 'decision_attendue', 'factures_de_reference', 'nb_factures', 'code_scenario'],
                array_map(static fn (array $l) => [
                    $l['objet_id'], $l['vrai_client'], $l['vraie_societe'], $l['decision_attendue'],
                    $parObjet[$l['objet_id']]['factures_de_reference'] ?? '',
                    $parObjet[$l['objet_id']]['nb_factures'] ?? 0,
                    $l['code_scenario'],
                ], $tv));
        }

        $io->table(['Table', 'Lignes', 'Millisecondes'], array_map(
            static fn (array $r): array => [$r['table'], number_format($r['n'], 0, ',', ' '), (string) (int) $r['ms']],
            $rapport));

        $total = array_sum(array_column($rapport, 'n'));
        $io->success(sprintf('%s lignes chargees en %d ms. Univers synthetique, graine fixe, aucune donnee reelle.',
            number_format($total, 0, ',', ' '), (int) ((microtime(true) - $t0) * 1000)));

        return Command::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function lire(string $dossier, string $table): array
    {
        $brut = json_decode((string) file_get_contents($dossier.'/'.$table.'.json'), true, 512, \JSON_THROW_ON_ERROR);
        // PHP retype en entier une cle numerique ; les colonnes sont des noms.
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
     * @return array{table: string, n: int, ms: float}
     */
    private function inserer(string $table, array $colonnes, array $lignes): array
    {
        $t0 = microtime(true);
        $this->cnx->executeStatement('TRUNCATE '.$table);
        $paquet = 500;
        $liste = '('.implode(',', array_map(static fn (string $c): string => '"'.$c.'"', $colonnes)).')';

        for ($debut = 0; $debut < \count($lignes); $debut += $paquet) {
            $tranche = \array_slice($lignes, $debut, $paquet);
            if ([] === $tranche) {
                break;
            }
            $valeurs = [];
            $params = [];
            foreach ($tranche as $l) {
                $valeurs[] = '('.implode(',', array_fill(0, \count($colonnes), '?')).')';
                foreach ($l as $v) {
                    $params[] = $v;
                }
            }
            $this->cnx->executeStatement(
                'INSERT INTO '.$table.' '.$liste.' VALUES '.implode(',', $valeurs), $params);
        }

        return ['table' => $table, 'n' => \count($lignes), 'ms' => (microtime(true) - $t0) * 1000];
    }

    private function creerSchemas(): void
    {
        $sql = <<<'SQL'
CREATE SCHEMA IF NOT EXISTS affectation;
CREATE SCHEMA IF NOT EXISTS affectation_verite;

CREATE TABLE IF NOT EXISTS affectation.iban (
  id varchar(16) PRIMARY KEY, masque varchar(40) NOT NULL, empreinte varchar(40) NOT NULL,
  titulaire_client_id varchar(16), occurrences int NOT NULL DEFAULT 0, premiere_apparition date);
CREATE INDEX IF NOT EXISTS idx_aff_iban_empreinte ON affectation.iban (empreinte);

CREATE TABLE IF NOT EXISTS affectation.client (
  id varchar(16) PRIMARY KEY, nom varchar(180) NOT NULL, nom_normalise varchar(180) NOT NULL,
  type varchar(20) NOT NULL, code_referentiel varchar(20), code_balance varchar(20),
  siren varchar(12), iban_id varchar(16), rythme varchar(20));
CREATE INDEX IF NOT EXISTS idx_aff_client_nom ON affectation.client (nom_normalise);
CREATE INDEX IF NOT EXISTS idx_aff_client_siren ON affectation.client (siren);
CREATE INDEX IF NOT EXISTS idx_aff_client_iban ON affectation.client (iban_id);

CREATE TABLE IF NOT EXISTS affectation.vehicule (
  id varchar(16) PRIMARY KEY, serie varchar(20) NOT NULL, serie8 varchar(8) NOT NULL,
  immatriculation varchar(16) NOT NULL, client_id varchar(16), modele varchar(80));
CREATE INDEX IF NOT EXISTS idx_aff_veh_client ON affectation.vehicule (client_id);

CREATE TABLE IF NOT EXISTS affectation.etablissement (
  id varchar(16) PRIMARY KEY, code varchar(8), nom varchar(120), societe_id varchar(16), service varchar(8));

CREATE TABLE IF NOT EXISTS affectation.facture (
  id varchar(20) PRIMARY KEY, numero varchar(30) NOT NULL, client_id varchar(16) NOT NULL,
  societe_id varchar(16) NOT NULL, etablissement_id varchar(16) NOT NULL, vehicule_id varchar(16),
  type varchar(8), date_facture date, echeance date, montant numeric(14,2) NOT NULL, statut varchar(12) NOT NULL,
  affectee boolean NOT NULL DEFAULT true);
CREATE INDEX IF NOT EXISTS idx_aff_fac_client_statut ON affectation.facture (client_id, affectee);
CREATE INDEX IF NOT EXISTS idx_aff_fac_numero ON affectation.facture (numero);
CREATE INDEX IF NOT EXISTS idx_aff_fac_montant ON affectation.facture (montant);

CREATE TABLE IF NOT EXISTS affectation.payeur_profil (
  client_id varchar(16) PRIMARY KEY, nb_reglements int, montant_moyen numeric(14,2),
  montant_min numeric(14,2), montant_max numeric(14,2), societes_reglees text,
  nb_societes int, rythme varchar(20), dernier_reglement date, motif_reference varchar(12));

CREATE TABLE IF NOT EXISTS affectation.virement (
  id varchar(16) PRIMARY KEY, date_operation date NOT NULL, montant numeric(14,2) NOT NULL,
  libelle varchar(120) NOT NULL, nom_donneur_ordre varchar(120), reference_bout_en_bout varchar(60),
  banque_id varchar(8), iban_emetteur_id varchar(16), societe_id varchar(16),
  sens_mixte boolean NOT NULL DEFAULT false, vedette boolean NOT NULL DEFAULT false,
  cohorte varchar(16) NOT NULL);
CREATE INDEX IF NOT EXISTS idx_aff_vir_cohorte ON affectation.virement (cohorte);
CREATE INDEX IF NOT EXISTS idx_aff_vir_vedette ON affectation.virement (vedette);

CREATE TABLE IF NOT EXISTS affectation.decision (
  virement_id varchar(16) PRIMARY KEY, mode varchar(10) NOT NULL DEFAULT 'ENRICHED',
  decision varchar(16) NOT NULL, score int NOT NULL, score_suivant int,
  -- La marge se mesure sur les points bruts, avant le plafond d'affichage.
  marge int, motif varchar(240),
  client_propose varchar(16), societes text, factures text, nb_factures int,
  montant_explique numeric(14,2), duree_ms numeric(10,3),
  duree_enrichissement_ms numeric(10,3), duree_candidats_ms numeric(10,3),
  duree_combinaison_ms numeric(10,3), duree_score_ms numeric(10,3),
  nb_candidats int, noeuds_explores int, calcule_le timestamptz NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS idx_aff_dec_decision ON affectation.decision (decision);

CREATE TABLE IF NOT EXISTS affectation.preuve (
  id bigserial PRIMARY KEY, virement_id varchar(16) NOT NULL, rang int NOT NULL,
  signal varchar(40) NOT NULL, libelle varchar(200) NOT NULL, constat varchar(300),
  poids int NOT NULL, origine varchar(20));
CREATE INDEX IF NOT EXISTS idx_aff_preuve_vir ON affectation.preuve (virement_id, rang);

CREATE TABLE IF NOT EXISTS affectation.candidat (
  id bigserial PRIMARY KEY, virement_id varchar(16) NOT NULL, rang int NOT NULL,
  client_id varchar(16) NOT NULL, score int NOT NULL, retenu boolean NOT NULL DEFAULT false,
  motif_ecart varchar(200));
CREATE INDEX IF NOT EXISTS idx_aff_cand_vir ON affectation.candidat (virement_id, rang);

CREATE TABLE IF NOT EXISTS affectation_verite.virement (
  objet_id varchar(16) PRIMARY KEY, vrai_client varchar(16), vraie_societe varchar(16),
  decision_attendue varchar(16) NOT NULL, factures_de_reference text, nb_factures int,
  code_scenario varchar(16));
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $requete) {
            $this->cnx->executeStatement($requete);
        }
    }
}
