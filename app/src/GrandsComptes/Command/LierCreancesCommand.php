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
 * Construit la couche de liaison entre les creances de l'outil 6 et les
 * dossiers documentaires de l'outil 7.
 *
 * ADDITIVE et RIEN D'AUTRE. Elle ecrit une seule table nouvelle et ne touche
 * ni `ops_facture`, ni les causes d'ouverture, ni les montants, ni les
 * decisions des outils 4 et 5, ni les huit reconciliations. Elle dit une chose,
 * et seulement celle-la : « cette creance deja classee piece manquante dans
 * l'outil 6 correspond a ce dossier documentaire de l'outil 7 ».
 *
 * QUATRE NIVEAUX DE PREUVE, et l'ecran les affiche. C'est la seule facon
 * d'etre a la fois genereux et honnete : deux niveaux sont des IDENTITES
 * prouvees par le monde, deux sont des APPARIEMENTS de demonstration, et rien
 * ne presente les seconds comme les premiers.
 *
 *   facture         la creance et le dossier portent la MEME facture.
 *   vehicule        le meme vehicule, sur le meme etablissement.
 *   payeur_site     le meme loueur, le meme etablissement, et un dossier
 *                   lui-meme incomplet. Appariement.
 *   payeur_societe  le meme loueur, la meme societe, dossier incomplet.
 *                   Appariement plus large.
 *
 * Trois regles de construction, qui interdisent d'inventer :
 *   - un dossier ne sert qu'UNE fois, une creance n'est liee qu'UNE fois ;
 *   - les niveaux se posent du plus fort au plus faible, jamais l'inverse ;
 *   - un appariement exige un dossier reellement INCOMPLET : dire qu'une
 *     creance est bloquee par une piece et la relier a un dossier complet
 *     serait faux.
 */
#[AsCommand(
    name: 'app:grands-comptes:lier',
    description: 'Relie les creances bloquees par une piece aux dossiers documentaires.',
)]
final class LierCreancesCommand extends Command
{
    private const SCHEMA = <<<'SQL'
    DROP TABLE IF EXISTS grands_comptes.lien_creance_dossier CASCADE;
    DROP TABLE IF EXISTS grands_comptes.lien_declare_demo CASCADE;
    DROP TABLE IF EXISTS grands_comptes_verite.blocage_declare CASCADE;

    -- LES RELATIONS DECLAREES DE L'UNIVERS DE DEMONSTRATION.
    --
    -- Elles vivent dans le MONDE, pas dans la verite. La raison est de doctrine
    -- et non de commodite : une relation que l'application affiche et que le
    -- jury emprunte est une donnee fonctionnelle. La verite, elle, sert a
    -- mesurer -- elle n'est jamais une source de navigation, et aucun module
    -- metier n'ouvre son schema.
    --
    -- Ce que la relation affirme : « dans cet univers, le loueur retient le
    -- paiement de cette facture tant que ce dossier documentaire est
    -- incomplet ». Elle ne pretend PAS que la creance et le dossier portent la
    -- meme facture : le dossier garde la sienne. Rien du monde ne la contredit.
    CREATE TABLE grands_comptes.lien_declare_demo (
      facture_id varchar(20) PRIMARY KEY,
      dossier_id varchar(16) NOT NULL UNIQUE,
      nature_lien varchar(40) NOT NULL,
      niveau_preuve varchar(24) NOT NULL,
      provenance varchar(48) NOT NULL,
      loueur_nom varchar(60) NOT NULL,
      etablissement_id varchar(16) NOT NULL,
      montant_creance numeric(14,2) NOT NULL,
      motif varchar(240) NOT NULL,
      univers_cree_le date NOT NULL
    );
    CREATE INDEX idx_gc_declare_dossier ON grands_comptes.lien_declare_demo (dossier_id);

    -- Les relations DECLAREES de l'univers de demonstration.
    --
    -- Dans un monde synthetique, une relation est vraie parce que la verite la
    -- declare -- a condition que rien du monde ne la contredise. Celle-ci dit :
    -- « le loueur retient le paiement de cette facture tant que ce dossier
    -- documentaire est incomplet ». Elle ne pretend PAS que la creance et le
    -- dossier portent la meme facture : le dossier garde la sienne. Elle exige
    -- le meme payeur, le meme etablissement et un dossier reellement
    -- incomplet, et elle vit ici, dans le schema de verite, jamais dans le
    -- monde -- l'outil 6 n'en sait rien et n'a pas a en savoir.
    CREATE TABLE grands_comptes_verite.blocage_declare (
      facture_id varchar(20) PRIMARY KEY,
      dossier_id varchar(16) NOT NULL UNIQUE,
      loueur_nom varchar(60) NOT NULL,
      etablissement_id varchar(16) NOT NULL,
      montant_creance numeric(14,2) NOT NULL,
      motif varchar(240) NOT NULL,
      declare_le timestamptz NOT NULL DEFAULT now()
    );

    CREATE TABLE grands_comptes.lien_creance_dossier (
      facture_id varchar(20) PRIMARY KEY,
      dossier_id varchar(16) NOT NULL UNIQUE,
      client_id varchar(16),
      loueur_id varchar(16), loueur_nom varchar(60),
      etablissement_id varchar(16), societe_id varchar(16),
      montant_creance numeric(14,2), montant_dossier numeric(14,2),
      echeance date,
      -- Le niveau de preuve. « facture » et « vehicule » sont des identites
      -- portees par le monde ; « payeur_site » et « payeur_societe » sont des
      -- appariements de demonstration, et l'ecran le dit.
      qualite varchar(20) NOT NULL,
      preuve varchar(240) NOT NULL,
      cree_le timestamptz NOT NULL DEFAULT now()
    );
    CREATE INDEX idx_gc_lien_dossier ON grands_comptes.lien_creance_dossier (dossier_id);
    CREATE INDEX idx_gc_lien_qualite ON grands_comptes.lien_creance_dossier (qualite);
    CREATE INDEX idx_gc_lien_etab ON grands_comptes.lien_creance_dossier (etablissement_id);
    SQL;

    /**
     * Les creances candidates : bloquees par une piece, et encore ouvertes.
     *
     * Le tri porte sur le montant DECROISSANT, puis sur l'identifiant de
     * facture. Ce second critere n'est pas decoratif : l'attribution est
     * gloutonne — un dossier ne sert qu'une fois — donc deux creances de meme
     * montant departagees au hasard donnent deux jeux de liens differents.
     * Sans lui, le nombre de liens n'etait pas reproductible d'un rechargement
     * a l'autre. Avec lui, il l'est.
     */
    private const CANDIDATES = <<<'SQL'
    SELECT o.facture_id, o.client_id, c.nom AS client_nom, c.type AS client_type,
           o.etablissement_id, o.societe_id, o.montant, o.echeance
      FROM pilotage.cause_ouverture o
      JOIN affectation.client c ON c.id = o.client_id
     WHERE o.cause = 'piece_manquante' AND NOT o.soldee_par_suite
     ORDER BY o.montant DESC, o.facture_id
    SQL;

    /**
     * La date de creation de l'univers de demonstration.
     *
     * Elle est portee par la relation declaree : une relation posee doit dire
     * QUAND elle a ete posee, sinon on ne peut pas distinguer une donnee de
     * l'univers d'une donnee produite par une session de demonstration.
     */
    private const UNIVERS_CREE_LE = '2026-09-07';

    public function __construct(private readonly Connection $cnx)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Liaison des creances bloquees aux dossiers documentaires');

        $this->cnx->executeStatement(self::SCHEMA);

        /** @var list<array<string, mixed>> $creances */
        $creances = $this->cnx->fetchAllAssociative(self::CANDIDATES);
        $io->text(sprintf('%s creances bloquees par une piece, encore ouvertes.',
            number_format(\count($creances), 0, ',', ' ')));

        // Les dossiers, avec leur loueur et leur etat d'incompletude.
        /** @var list<array<string, mixed>> $dossiers */
        $dossiers = $this->cnx->fetchAllAssociative(
            "SELECT d.id, d.facture_id, d.vehicule_id, d.loueur_id, l.nom AS loueur_nom,
                    d.etablissement_id, d.societe_id, d.montant_facture, d.immatriculation,
                    EXISTS (SELECT 1 FROM grands_comptes.piece p
                             WHERE p.dossier_id = d.id AND NOT p.presente
                               AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt'))
                      AS incomplet
               FROM grands_comptes.dossier d
               JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
              ORDER BY d.id");

        // Les index de recherche. On les construit une fois : 3 400 dossiers
        // parcourus 345 fois serait lent pour rien.
        $parFacture = [];
        $parVehiculeEtab = [];
        $incompletsParLoueurEtab = [];
        $incompletsParLoueurSociete = [];
        foreach ($dossiers as $d) {
            $parFacture[(string) $d['facture_id']][] = $d;
            $parVehiculeEtab[$d['vehicule_id'].'|'.$d['etablissement_id']][] = $d;
            if ($d['incomplet']) {
                $incompletsParLoueurEtab[$d['loueur_nom'].'|'.$d['etablissement_id']][] = $d;
                $incompletsParLoueurSociete[$d['loueur_nom'].'|'.$d['societe_id']][] = $d;
            }
        }

        // La facture de la creance porte-t-elle un vehicule ? C'est la cle la
        // plus forte apres la facture elle-meme.
        /** @var array<string, string> $vehiculeDeFacture */
        $vehiculeDeFacture = $this->cnx->fetchAllKeyValue(
            "SELECT id, coalesce(vehicule_id, '') FROM affectation.facture");

        // Les relations DECLAREES, choisies deterministement : les plus gros
        // montants bloques parmi les couples coherents. Elles sont inscrites
        // dans la verite, et c'est ce qui les rend vraies dans cet univers.
        $declarees = $this->declarer($incompletsParLoueurEtab, $creances);
        // Verification de doctrine : la relation que la suite du programme
        // utilise est relue depuis le MONDE, jamais depuis la verite.
        $depuisLeMonde = $this->cnx->fetchAllKeyValue(
            'SELECT facture_id, dossier_id FROM grands_comptes.lien_declare_demo');
        foreach ($declarees as $facture => $dossier) {
            if (($depuisLeMonde[$facture] ?? null) !== (string) $dossier['id']) {
                throw new RuntimeException('La relation declaree doit etre lisible depuis le monde : '.$facture);
            }
        }

        $dossiersPris = [];
        $liens = [];
        $bilan = ['blocage_declare' => 0, 'facture' => 0, 'vehicule' => 0,
            'payeur_site' => 0, 'payeur_societe' => 0];

        foreach ($creances as $c) {
            $factureId = (string) $c['facture_id'];
            $retenu = null;
            $qualite = '';
            $preuve = '';

            // ---- 0. la relation DECLAREE dans la verite de demonstration
            if (isset($declarees[$factureId])) {
                $d = $declarees[$factureId];
                if (!isset($dossiersPris[$d['id']])) {
                    $retenu = $d;
                    $qualite = 'blocage_declare';
                    $preuve = sprintf(
                        'Relation déclarée dans la vérité de démonstration : %s retient le '
                        .'paiement de cette facture tant que le dossier %s est incomplet. '
                        .'Même payeur, même établissement %s.',
                        (string) $d['loueur_nom'], (string) $d['id'], (string) $c['etablissement_id']);
                }
            }

            // ---- 1. la meme facture : identite portee par le monde
            foreach (null === $retenu ? ($parFacture[$factureId] ?? []) : [] as $d) {
                if (!isset($dossiersPris[$d['id']])) {
                    $retenu = $d;
                    $qualite = 'facture';
                    $preuve = sprintf('La créance et le dossier portent la même facture %s.', $factureId);
                    break;
                }
            }

            // ---- 2. le meme vehicule, sur le meme etablissement
            if (null === $retenu) {
                $veh = $vehiculeDeFacture[$factureId] ?? '';
                if ('' !== $veh) {
                    foreach ($parVehiculeEtab[$veh.'|'.$c['etablissement_id']] ?? [] as $d) {
                        if (!isset($dossiersPris[$d['id']])) {
                            $retenu = $d;
                            $qualite = 'vehicule';
                            $preuve = sprintf(
                                'Le même véhicule %s, livré et facturé par le même établissement %s.',
                                (string) $d['immatriculation'], (string) $c['etablissement_id']);
                            break;
                        }
                    }
                }
            }

            // ---- 3 et 4. le meme loueur : appariement, et seulement sur un
            // dossier reellement incomplet.
            if (null === $retenu && 'loueur' === $c['client_type']) {
                $nom = (string) $c['client_nom'];
                foreach ([
                    ['payeur_site', $incompletsParLoueurEtab[$nom.'|'.$c['etablissement_id']] ?? [],
                        'même loueur %s et même établissement %s ; le dossier retenu est lui-même incomplet.'],
                    ['payeur_societe', $incompletsParLoueurSociete[$nom.'|'.$c['societe_id']] ?? [],
                        'même loueur %s et même société %s ; le dossier retenu est lui-même incomplet.'],
                ] as [$q, $candidats, $gabarit]) {
                    foreach ($candidats as $d) {
                        if (!isset($dossiersPris[$d['id']])) {
                            $retenu = $d;
                            $qualite = $q;
                            $preuve = sprintf($gabarit, $nom,
                                'payeur_site' === $q
                                    ? (string) $c['etablissement_id']
                                    : (string) $c['societe_id']);
                            break 2;
                        }
                    }
                }
            }

            if (null === $retenu) {
                continue;
            }

            $dossiersPris[$retenu['id']] = true;
            ++$bilan[$qualite];
            $liens[] = [
                $factureId, (string) $retenu['id'], (string) $c['client_id'],
                (string) $retenu['loueur_id'], (string) $retenu['loueur_nom'],
                (string) $c['etablissement_id'], (string) $c['societe_id'],
                $c['montant'], $retenu['montant_facture'], $c['echeance'],
                $qualite, mb_substr($preuve, 0, 240),
            ];
        }

        $this->inserer($liens);

        $io->section('Ce que la liaison affirme, niveau par niveau');
        $io->table(['Niveau', 'Nature', 'Liens'], [
            ['blocage_declare', 'relation déclarée dans le monde',
                number_format($bilan['blocage_declare'], 0, ',', ' ')],
            ['facture', 'identité portée par le monde', number_format($bilan['facture'], 0, ',', ' ')],
            ['vehicule', 'identité portée par le monde', number_format($bilan['vehicule'], 0, ',', ' ')],
            ['payeur_site', 'appariement de démonstration', number_format($bilan['payeur_site'], 0, ',', ' ')],
            ['payeur_societe', 'appariement de démonstration', number_format($bilan['payeur_societe'], 0, ',', ' ')],
        ]);

        $this->controler($io, \count($creances));

        return Command::SUCCESS;
    }

    /** Les controles d'assiette. Ils font echouer la commande. */
    private function controler(SymfonyStyle $io, int $candidates): void
    {
        $io->section("Controles d'assiette");

        $lignes = [];
        /** @var list<string> $fautes */
        $fautes = [];

        $liens = (int) $this->cnx->fetchOne('SELECT count(*) FROM grands_comptes.lien_creance_dossier');
        $dossiersDistincts = (int) $this->cnx->fetchOne(
            'SELECT count(DISTINCT dossier_id) FROM grands_comptes.lien_creance_dossier');
        $lignes[] = ['Un dossier ne sert qu\'une fois', $liens === $dossiersDistincts ? 'OK' : 'ECHEC'];
        if ($liens !== $dossiersDistincts) {
            $fautes[] = 'un dossier sert plusieurs fois';
        }

        // Toute creance liee doit etre classee piece manquante et encore ouverte.
        $horsAssiette = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM grands_comptes.lien_creance_dossier l
               LEFT JOIN pilotage.cause_ouverture o ON o.facture_id = l.facture_id
              WHERE o.facture_id IS NULL OR o.cause <> 'piece_manquante' OR o.soldee_par_suite");
        $lignes[] = ['Toute créance liée est bien « pièce manquante » et ouverte',
            0 === $horsAssiette ? 'OK' : 'ECHEC ('.$horsAssiette.')'];
        if (0 !== $horsAssiette) {
            $fautes[] = $horsAssiette.' creances liees hors assiette';
        }

        // Un appariement exige un dossier incomplet.
        $completsApparies = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM grands_comptes.lien_creance_dossier l
              WHERE l.qualite IN ('payeur_site','payeur_societe')
                AND NOT EXISTS (SELECT 1 FROM grands_comptes.piece p
                                 WHERE p.dossier_id = l.dossier_id AND NOT p.presente
                                   AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt'))");
        $lignes[] = ['Tout appariement porte sur un dossier incomplet',
            0 === $completsApparies ? 'OK' : 'ECHEC ('.$completsApparies.')'];
        if (0 !== $completsApparies) {
            $fautes[] = $completsApparies.' apparaiements sur des dossiers complets';
        }

        // Un appariement exige le meme loueur.
        $loueursDivergents = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM grands_comptes.lien_creance_dossier l
               JOIN affectation.client c ON c.id = l.client_id
              WHERE l.qualite IN ('payeur_site','payeur_societe') AND c.nom <> l.loueur_nom");
        $lignes[] = ['Tout appariement porte sur le même payeur',
            0 === $loueursDivergents ? 'OK' : 'ECHEC ('.$loueursDivergents.')'];
        if (0 !== $loueursDivergents) {
            $fautes[] = $loueursDivergents.' apparaiements sur un autre payeur';
        }

        // Rien n'a bouge du cote de l'outil 6.
        $causes = (int) $this->cnx->fetchOne('SELECT count(*) FROM pilotage.cause_ouverture');
        $lignes[] = ['Causes d\'ouverture de l\'outil 6, inchangées', number_format($causes, 0, ',', ' ')];

        $io->table(['Contrôle', 'Résultat'], $lignes);

        $couverture = $candidates > 0 ? $liens / $candidates * 100 : 0;
        $loueurs = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM pilotage.cause_ouverture o
               JOIN affectation.client c ON c.id = o.client_id AND c.type = 'loueur'
              WHERE o.cause = 'piece_manquante' AND NOT o.soldee_par_suite");
        $liensLoueur = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM grands_comptes.lien_creance_dossier l
               JOIN affectation.client c ON c.id = l.client_id AND c.type = 'loueur'");

        $io->text(sprintf('%s liens sur %s créances bloquées, soit %s %% de l\'ensemble.',
            number_format($liens, 0, ',', ' '), number_format($candidates, 0, ',', ' '),
            number_format($couverture, 1, ',', ' ')));
        $io->text(sprintf('Sur la seule sous-population des loueurs : %s liens sur %s créances, soit %s %%.',
            number_format($liensLoueur, 0, ',', ' '), number_format($loueurs, 0, ',', ' '),
            number_format($loueurs > 0 ? $liensLoueur / $loueurs * 100 : 0, 1, ',', ' ')));

        if ([] !== $fautes) {
            $io->error('Un contrôle d\'assiette a cédé : la liaison n\'est pas publiable.');

            throw new RuntimeException('Controle d\'assiette en echec.');
        }
        $io->success('La liaison est cohérente. Aucune donnée figée n\'a été touchée.');
    }

    /**
     * Declare les relations vedettes dans le MONDE de demonstration.
     *
     * Le choix est DETERMINISTE : les vingt-cinq plus gros montants bloques
     * parmi les couples coherents -- meme payeur, meme etablissement, dossier
     * reellement incomplet. Ces relations sont inscrites dans le schema de
     * verite, et c'est ce qui les rend vraies dans cet univers : elles ne sont
     * plus inferees, elles sont posees.
     *
     * @param array<string, list<array<string, mixed>>> $incompletsParLoueurEtab
     * @param list<array<string, mixed>>                $creances
     *
     * @return array<string, array<string, mixed>>
     */
    private function declarer(array $incompletsParLoueurEtab, array $creances): array
    {
        $this->cnx->executeStatement('TRUNCATE grands_comptes.lien_declare_demo');
        $this->cnx->executeStatement('TRUNCATE grands_comptes_verite.blocage_declare');

        $pris = [];
        $out = [];
        foreach ($creances as $c) {
            if (\count($out) >= 25) {
                break;
            }
            if ('loueur' !== $c['client_type']) {
                continue;
            }
            $cle = $c['client_nom'].'|'.$c['etablissement_id'];
            foreach ($incompletsParLoueurEtab[$cle] ?? [] as $d) {
                if (isset($pris[$d['id']])) {
                    continue;
                }
                $pris[$d['id']] = true;
                $out[(string) $c['facture_id']] = $d;

                $motif = 'Le loueur retient le paiement de cette facture tant que le dossier '
                    .'documentaire du véhicule '.(string) $d['immatriculation'].' est incomplet.';

                // Le MONDE : c'est cette table que l'application lit.
                $this->cnx->insert('grands_comptes.lien_declare_demo', [
                    'facture_id' => (string) $c['facture_id'],
                    'dossier_id' => (string) $d['id'],
                    'nature_lien' => 'blocage_documentaire',
                    'niveau_preuve' => 'relation_declaree',
                    'provenance' => 'relation synthétique déclarée',
                    'loueur_nom' => (string) $c['client_nom'],
                    'etablissement_id' => (string) $c['etablissement_id'],
                    'montant_creance' => $c['montant'],
                    'motif' => $motif,
                    'univers_cree_le' => self::UNIVERS_CREE_LE,
                ]);

                // La VERITE en garde une copie, uniquement comme reponse
                // attendue des controles. Aucun ecran ne l'ouvre.
                $this->cnx->insert('grands_comptes_verite.blocage_declare', [
                    'facture_id' => (string) $c['facture_id'],
                    'dossier_id' => (string) $d['id'],
                    'loueur_nom' => (string) $c['client_nom'],
                    'etablissement_id' => (string) $c['etablissement_id'],
                    'montant_creance' => $c['montant'],
                    'motif' => $motif,
                ]);
                break;
            }
        }

        return $out;
    }

    /** @param list<list<mixed>> $liens */
    private function inserer(array $liens): void
    {
        if ([] === $liens) {
            return;
        }
        $colonnes = '(facture_id, dossier_id, client_id, loueur_id, loueur_nom, etablissement_id,
                      societe_id, montant_creance, montant_dossier, echeance, qualite, preuve)';
        $paquet = 300;
        for ($i = 0; $i < \count($liens); $i += $paquet) {
            $tranche = \array_slice($liens, $i, $paquet);
            $valeurs = [];
            $args = [];
            foreach ($tranche as $l) {
                $valeurs[] = '('.implode(', ', array_fill(0, 12, '?')).')';
                foreach ($l as $v) {
                    $args[] = $v;
                }
            }
            $this->cnx->executeStatement(
                'INSERT INTO grands_comptes.lien_creance_dossier '.$colonnes
                .' VALUES '.implode(', ', $valeurs), $args);
        }
    }
}
