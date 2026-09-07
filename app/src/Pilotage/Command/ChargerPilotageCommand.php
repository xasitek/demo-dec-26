<?php

declare(strict_types=1);

namespace App\Pilotage\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charge les deux tables que le cockpit ajoute : le chiffre d'affaires et la
 * cause d'ouverture de chaque creance.
 *
 * Il n'y a pas de schema de verite pour l'outil 6, et c'est volontaire. Un
 * cockpit ne predit rien : il consolide et retraite ce que les outils 4 et 5
 * ont decide. Ce qui remplace le test aveugle, ce sont les controles de
 * reconciliation de `app:pilotage:reconcilier`.
 */
#[AsCommand(
    name: 'app:pilotage:charger-univers',
    description: 'Charge le chiffre d\'affaires et les causes d\'ouverture du cockpit.',
)]
final class ChargerPilotageCommand extends Command
{
    private const SCHEMA = <<<'SQL'
CREATE SCHEMA IF NOT EXISTS pilotage;

DROP TABLE IF EXISTS pilotage.chiffre_affaires, pilotage.cause_ouverture, pilotage.cycle CASCADE;

CREATE TABLE pilotage.chiffre_affaires (
  societe_id varchar(16) NOT NULL, etablissement_id varchar(16) NOT NULL,
  cycle varchar(8) NOT NULL, mois char(7) NOT NULL,
  chiffre_affaires_ht numeric(16,2) NOT NULL,
  chiffre_affaires_ttc numeric(16,2) NOT NULL,
  nb_factures int NOT NULL, delai_moyen_theorique int NOT NULL
);
CREATE INDEX idx_pil_ca_mois ON pilotage.chiffre_affaires (mois);
CREATE INDEX idx_pil_ca_etab ON pilotage.chiffre_affaires (etablissement_id);

CREATE TABLE pilotage.cause_ouverture (
  facture_id varchar(16) PRIMARY KEY,
  client_id varchar(16), societe_id varchar(16), etablissement_id varchar(16),
  cause varchar(24) NOT NULL, suite varchar(16) NOT NULL,
  montant numeric(14,2) NOT NULL, date_facture date NOT NULL, echeance date NOT NULL,
  -- Renseignee APRES coup, en lisant les decisions des outils 4 et 5 : cette
  -- creance a-t-elle ete soldee par la suite de la chaine ? Sans cette colonne,
  -- une facture reglee par un virement que l'outil 4 a affecte comptait a la
  -- fois dans le cash encaisse et dans l'exposition a traiter. Le controle de
  -- reconciliation l'a vu.
  soldee_par_suite boolean NOT NULL DEFAULT false,
  soldee_par varchar(12)
);
CREATE INDEX idx_pil_cause ON pilotage.cause_ouverture (cause);
CREATE INDEX idx_pil_cause_etab ON pilotage.cause_ouverture (etablissement_id);
CREATE INDEX idx_pil_cause_client ON pilotage.cause_ouverture (client_id);

CREATE TABLE pilotage.cycle (
  code varchar(8) PRIMARY KEY, libelle varchar(60) NOT NULL,
  poids_cible numeric(6,4) NOT NULL, delai_moyen_theorique int NOT NULL
);

-- La trace des gestes du comptable. Volontairement HORS du DROP ci-dessus, et
-- creee « IF NOT EXISTS » : elle porte le travail humain fait dans la
-- demonstration, et un rechargement des agregats du cockpit ne doit pas
-- effacer ce qu'une personne a decide. Un historique qu'un recalcul peut
-- balayer n'est pas une piste d'audit.
CREATE TABLE IF NOT EXISTS pilotage.geste (
  id bigserial PRIMARY KEY,
  type varchar(20) NOT NULL,
  objet_type varchar(12) NOT NULL,
  objet_id varchar(24) NOT NULL,
  client_id varchar(16), etablissement_id varchar(16),
  montant numeric(14,2),
  niveau varchar(8),
  -- Le module vers lequel le travail a ete envoye, quand la trace est une
  -- consultation. L'outil 6 orchestre : il note ou il a adresse le dossier,
  -- il n'ecrit pas la decision que le module cible executera.
  module varchar(12),
  note varchar(400),
  auteur varchar(80) NOT NULL,
  file varchar(28),
  fait_le timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_pil_geste_objet ON pilotage.geste (objet_type, objet_id);
CREATE INDEX IF NOT EXISTS idx_pil_geste_auteur ON pilotage.geste (auteur, fait_le DESC);
SQL;

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Chargement des donnees du cockpit');

        $monde = \dirname(__DIR__, 3).'/../public/donnees/monde';
        $this->cnx->executeStatement(self::SCHEMA);
        $io->text('Schema et tables crees.');

        $rapport = [];

        $ca = $this->lire($monde, 'ops_chiffre_affaires');
        $rapport[] = $this->inserer('pilotage.chiffre_affaires',
            ['societe_id', 'etablissement_id', 'cycle', 'mois', 'chiffre_affaires_ht',
                'chiffre_affaires_ttc', 'nb_factures', 'delai_moyen_theorique'],
            array_map(static fn (array $l): array => [
                $l['societe_id'], $l['etablissement_id'], $l['cycle'], $l['mois'],
                $l['chiffre_affaires_ht'], $l['chiffre_affaires_ttc'],
                $l['nb_factures'], $l['delai_moyen_theorique'],
            ], $ca));

        $causes = $this->lire($monde, 'ops_cause_ouverture');
        $rapport[] = $this->inserer('pilotage.cause_ouverture',
            ['facture_id', 'client_id', 'societe_id', 'etablissement_id', 'cause', 'suite',
                'montant', 'date_facture', 'echeance'],
            array_map(static fn (array $l): array => [
                $l['facture_id'], $l['client_id'], $l['societe_id'], $l['etablissement_id'],
                $l['cause'], $l['suite'], $l['montant'], $l['date_facture'], $l['echeance'],
            ], $causes));

        $cycles = $this->lire($monde, 'ref_cycle');
        $rapport[] = $this->inserer('pilotage.cycle',
            ['code', 'libelle', 'poids_cible', 'delai_moyen_theorique'],
            array_map(static fn (array $l): array => [
                $l['code'], $l['libelle'], $l['poids_cible'], $l['delai_moyen_theorique'],
            ], $cycles));

        $io->table(['Table', 'Lignes', 'Millisecondes'], $rapport);

        // ---- La chaine : on LIT les decisions des outils 4 et 5, et on marque
        // les creances qu'elles ont soldees. L'outil 6 ne recalcule rien de ce
        // que les deux precedents ont decide : il en tire les consequences.
        $io->section('Consolidation de la chaine');
        $t0 = microtime(true);

        $parAffectation = $this->cnx->executeStatement(
            "UPDATE pilotage.cause_ouverture c
                SET soldee_par_suite = true, soldee_par = 'outil-4'
              WHERE EXISTS (
                    SELECT 1 FROM affectation.decision d
                     WHERE d.decision = 'automatique'
                       AND c.facture_id = ANY(string_to_array(d.factures, '|')))");

        $parLettrage = $this->cnx->executeStatement(
            "UPDATE pilotage.cause_ouverture c
                SET soldee_par_suite = true, soldee_par = 'outil-5'
              WHERE NOT c.soldee_par_suite
                AND EXISTS (
                    SELECT 1 FROM lettrage.decision ld
                      JOIN lettrage.ecriture e ON e.lot_demo = ld.lot_id
                     WHERE ld.verdict = 'automatique' AND e.facture_id = c.facture_id)");

        $restantes = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM pilotage.cause_ouverture WHERE NOT soldee_par_suite');

        $io->table(['Etape', 'Creances'], [
            ["Soldees par l'affectation de l'outil 4", number_format((int) $parAffectation, 0, ',', ' ')],
            ['Soldees par le lettrage de l\'outil 5', number_format((int) $parLettrage, 0, ',', ' ')],
            ['Encore ouvertes apres la chaine', number_format($restantes, 0, ',', ' ')],
        ]);
        $io->text(sprintf('Consolidation en %d ms.', (int) ((microtime(true) - $t0) * 1000)));

        $io->success('Donnees du cockpit chargees. Aucune table des outils 4 et 5 n\'a ete touchee.');

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
        $trous = '('.implode(',', array_fill(0, \count($colonnes), '?')).')';
        for ($i = 0; $i < \count($lignes); $i += 500) {
            $tranche = \array_slice($lignes, $i, 500);
            if ([] === $tranche) {
                break;
            }
            $this->cnx->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(',', $colonnes),
                    implode(',', array_fill(0, \count($tranche), $trous))),
                array_merge(...array_map('array_values', $tranche)));
        }

        return [$table, number_format(\count($lignes), 0, ',', ' '), (string) (int) ((microtime(true) - $t0) * 1000)];
    }
}
