<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Service\Chiffrement;
use App\Remboursement\Service\CleDoublon;
use App\Remboursement\Service\IbanFictif;
use App\Remboursement\Service\WorkflowRemboursement;
use App\Shared\Entity\Etablissement;
use App\Shared\Entity\EtablissementContact;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple le VRAI module Remboursement avec l'univers synthetique.
 *
 * Le principe du lot est de ne rien reconstruire : on remplit le workflow
 * herite -- ses 16 etats, ses 23 transitions, ses entites, ses ecrans -- avec
 * les 2 500 dossiers que la fabrique porte deja. Aucun second circuit n'est
 * cree a cote.
 *
 * Trois consequences pratiques, et elles commandent tout le code ci-dessous :
 *
 *   1. on ecrit par les ENTITES Doctrine, jamais en SQL brut. Les IBAN sont
 *      chiffres par l'entite, l'empreinte aveugle et la cle anti-doublon sont
 *      calculees par le module. Un INSERT direct produirait des dossiers que
 *      l'application ne saurait pas relire -- et surtout des controles de
 *      doublon qui ne verraient rien ;
 *   2. les 54 etablissements du monde synthetique sont crees dans le
 *      referentiel partage avec des coordonnees bancaires FICTIVES, parce que
 *      le generateur SEPA exige un debiteur complet ;
 *   3. la verite de l'outil 8 -- ce que chaque dossier DEVAIT devenir -- est
 *      chargee dans un schema separe, `remboursement_verite`, que le module
 *      ne lit jamais.
 */
#[AsCommand(
    name: 'app:remboursement:charger-univers',
    description: 'Peuple le vrai module Remboursement avec les 2 500 dossiers synthetiques.',
)]
final class ChargerUniversRemboursementCommand extends Command
{
    /** Le prefixe des etablissements de demonstration issus du monde synthetique. */
    private const PREFIXE_ETAB = '8';

    /**
     * Table additive, cote MONDE : ce que les PIECES du dossier montrent.
     *
     * Ce n'est pas de la verite de mesure -- elle dit ce qu'un dossier doit
     * devenir. C'est un fait documentaire : ce que porte le papier. La fabrique
     * de documents lit cette table, et elle seule. Aucun module metier ne la
     * lit, et elle ne contient rien qui ressemble a une reponse attendue.
     */
    private const SCHEMA_SOURCE_PIECE = <<<'SQL'
    CREATE TABLE IF NOT EXISTS remboursement.source_piece_demo (
      dossier_id integer PRIMARY KEY,
      iban_rib varchar(34),
      montant_facture numeric(14,2),
      immatriculation_cg varchar(16),
      panne_extraction boolean NOT NULL DEFAULT false,
      piece_illisible boolean NOT NULL DEFAULT false,
      mention_manuscrite boolean NOT NULL DEFAULT false,
      vehicule_gage boolean NOT NULL DEFAULT false,
      estimation_retouchee boolean NOT NULL DEFAULT false
    );
    SQL;

    private const SCHEMA_VERITE = <<<'SQL'
    CREATE SCHEMA IF NOT EXISTS remboursement_verite;

    DROP TABLE IF EXISTS remboursement_verite.dossier CASCADE;

    -- La verite de l'outil 8 : ce que chaque dossier DEVAIT devenir, et par
    -- quel controle. Le module ne lit JAMAIS ce schema : il sert a mesurer.
    CREATE TABLE remboursement_verite.dossier (
      objet_id varchar(24) PRIMARY KEY,
      reference varchar(32) NOT NULL,
      decision_attendue varchar(16) NOT NULL,
      controle_declencheur varchar(48),
      ecart_contractuel numeric(14,2),
      surpaiement_reel boolean,
      code_scenario varchar(12) NOT NULL
    );
    CREATE INDEX idx_rbv_decision ON remboursement_verite.dossier (decision_attendue);
    CREATE INDEX idx_rbv_scenario ON remboursement_verite.dossier (code_scenario);
    SQL;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly WorkflowRemboursement $workflow,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limite', null, InputOption::VALUE_REQUIRED,
            'Ne charger que les N premiers dossiers (mise au point).', '0');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Peuplement du module Remboursement');

        $monde = \dirname(__DIR__, 3).'/../public/donnees/monde';
        $verite = \dirname(__DIR__, 3).'/../public/donnees/verite';

        $this->cnx->executeStatement(self::SCHEMA_VERITE);
        $this->cnx->executeStatement(self::SCHEMA_SOURCE_PIECE);

        // ------------------------------------------------- les etablissements
        $io->section('Etablissements de demonstration');
        $etabs = $this->lire($monde, 'ref_etablissement');
        $societes = $this->indexer($this->lire($monde, 'ref_societe'), 'id');
        $crees = $this->chargerEtablissements($etabs, $societes);
        $io->text(sprintf('%d etablissements du monde synthetique, coordonnees bancaires fictives.', $crees));

        // ------------------------------------------------------- le Buy Back
        $io->section('Contrats Buy Back');
        $nbBuyBack = $this->chargerBuyBack($monde);
        $io->text(sprintf('%s contrats charges. Le controle anti-surpaiement a desormais de quoi comparer.',
            number_format($nbBuyBack, 0, ',', ' ')));

        // --------------------------------------------------------- les dossiers
        $io->section('Dossiers de remboursement');
        $limite = (int) $entree->getOption('limite');
        $bilan = $this->chargerDossiers($monde, $verite, $limite, $io);

        $io->table(['Ce qui est charge', 'Nombre'], [
            ['Etablissements', number_format($crees, 0, ',', ' ')],
            ['Contrats Buy Back', number_format($nbBuyBack, 0, ',', ' ')],
            ['Dossiers', number_format($bilan['dossiers'], 0, ',', ' ')],
            ['Dont rachat sec', number_format($bilan['rachat'], 0, ',', ' ')],
            ['Dont trop-percu', number_format($bilan['trop_percu'], 0, ',', ' ')],
            ['Lignes de verite', number_format($bilan['verite'], 0, ',', ' ')],
        ]);

        // ------------------------------------------------ controles d'assiette
        $io->section("Controles d'assiette");
        $lignes = [];
        $fautes = [];

        $sansCle = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier WHERE cle_doublon IS NULL');
        $lignes[] = ['Tout dossier porte une cle anti-doublon', 0 === $sansCle ? 'OK' : 'ECHEC ('.$sansCle.')'];
        if (0 !== $sansCle) {
            $fautes[] = $sansCle.' dossiers sans cle anti-doublon';
        }

        $sansEmpreinte = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier WHERE iban_hash IS NULL');
        $lignes[] = ['Tout dossier porte une empreinte IBAN', 0 === $sansEmpreinte ? 'OK' : 'ECHEC ('.$sansEmpreinte.')'];
        if (0 !== $sansEmpreinte) {
            $fautes[] = $sansEmpreinte.' dossiers sans empreinte IBAN';
        }

        $orphelins = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier d
               LEFT JOIN shared.etablissement e ON e.code_etab = d.etablissement_code
              WHERE e.code_etab IS NULL');
        $lignes[] = ['Tout dossier pointe un etablissement connu', 0 === $orphelins ? 'OK' : 'ECHEC ('.$orphelins.')'];
        if (0 !== $orphelins) {
            $fautes[] = $orphelins.' dossiers sans etablissement';
        }

        $sansIban = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM shared.etablissement WHERE code_etab LIKE '8%'
               AND (iban IS NULL OR bic IS NULL OR compte_contrepartie IS NULL)");
        $lignes[] = ['Tout etablissement a des coordonnees SEPA', 0 === $sansIban ? 'OK' : 'ECHEC ('.$sansIban.')'];
        if (0 !== $sansIban) {
            $fautes[] = $sansIban.' etablissements sans coordonnees';
        }

        // La validite MATHEMATIQUE des coordonnees, verifiee ici et pas
        // supposee : un IBAN de demonstration qui ne passerait pas MOD 97
        // prouverait seulement qu'on a desactive le controle.
        $mauvais = 0;
        $mauvaisBic = 0;
        /** @var list<array<string, mixed>> $coord */
        $coord = $this->cnx->fetchAllAssociative(
            "SELECT code_etab, iban, bic FROM shared.etablissement WHERE code_etab LIKE '8%'");
        foreach ($coord as $c) {
            if (!IbanFictif::valide((string) $c['iban'])) {
                ++$mauvais;
            }
            if (!IbanFictif::bicValide((string) $c['bic'])) {
                ++$mauvaisBic;
            }
        }
        $lignes[] = ['IBAN des etablissements : MOD 97 valide',
            0 === $mauvais ? 'OK ('.\count($coord).')' : 'ECHEC ('.$mauvais.')'];
        $lignes[] = ['BIC des etablissements : syntaxe ISO 9362',
            0 === $mauvaisBic ? 'OK' : 'ECHEC ('.$mauvaisBic.')'];
        if (0 !== $mauvais) {
            $fautes[] = $mauvais.' IBAN etablissement invalides';
        }
        if (0 !== $mauvaisBic) {
            $fautes[] = $mauvaisBic.' BIC invalides';
        }

        // Et du cote des beneficiaires : TOUS les IBAN charges, sans echantillon.
        // Un controle qui n'en verifie que quatre cents laisse passer le
        // quatre cent unieme, et c'est celui-la qui sera projete au jury.
        $mauvaisClients = [];
        /** @var list<string> $ibansClients */
        $ibansClients = $this->cnx->fetchFirstColumn(
            'SELECT iban_client FROM remboursement.dossier WHERE iban_client IS NOT NULL');
        foreach ($ibansClients as $chiffre) {
            $clair = Chiffrement::dechiffrer($chiffre);
            if (null === $clair || '' === $clair) {
                continue;
            }
            // Structure : un IBAN francais de demonstration fait 27 caracteres
            // et commence par FR. Aucune autre provenance n'est admise.
            $forme = 27 === \strlen($clair) && str_starts_with($clair, 'FR');
            if (!$forme || !IbanFictif::valide($clair)) {
                $mauvaisClients[] = substr($clair, 0, 8).'…';
                if (\count($mauvaisClients) >= 5) {
                    break;
                }
            }
        }
        $lignes[] = ['IBAN des beneficiaires : structure, longueur, MOD 97',
            [] === $mauvaisClients
                ? 'OK ('.number_format(\count($ibansClients), 0, ',', ' ').' verifies, tous)'
                : 'ECHEC ('.implode(', ', $mauvaisClients).')'];
        if ([] !== $mauvaisClients) {
            $fautes[] = 'IBAN beneficiaire invalide : '.implode(', ', $mauvaisClients);
        }

        // Aucun IBAN de provenance externe : la banque de demonstration porte le
        // code 99999, et tout IBAN charge doit en venir. Un IBAN reel, meme
        // valide, n'a rien a faire ici.
        $horsDemo = 0;
        foreach ($ibansClients as $chiffre) {
            $clair = (string) Chiffrement::dechiffrer($chiffre);
            if ('' !== $clair && '99999' !== substr($clair, 4, 5)) {
                ++$horsDemo;
            }
        }
        $lignes[] = ['Aucun IBAN de provenance externe (banque 99999)',
            0 === $horsDemo ? 'OK' : 'ECHEC ('.$horsDemo.')'];
        if (0 !== $horsDemo) {
            $fautes[] = $horsDemo.' IBAN hors banque de demonstration';
        }

        $io->table(['Contrôle', 'Résultat'], $lignes);

        if ([] !== $fautes) {
            $io->error("Un controle d'assiette a cede : ".implode(' ; ', $fautes));

            return Command::FAILURE;
        }

        $io->success('Le vrai module Remboursement est peuple. Aucune donnee reelle, aucun appel reseau.');

        return Command::SUCCESS;
    }

    /**
     * Cree les etablissements du monde synthetique dans le referentiel partage.
     *
     * Les coordonnees bancaires sont FICTIVES et deterministes : le generateur
     * SEPA exige un debiteur complet -- nom legal, IBAN, BIC, compte de
     * contrepartie -- et il refuse de produire un fichier sans eux. C'est un
     * garde-fou du module reel, on ne le contourne pas : on le nourrit.
     *
     * @param list<array<string, mixed>>          $etabs
     * @param array<string, array<string, mixed>> $societes
     */
    private function chargerEtablissements(array $etabs, array $societes): int
    {
        // Rechargement : on repart des contacts a zero pour les sites de
        // l'univers, sinon la contrainte d'unicite (code, email) rejetterait le
        // second chargement. Les autres sites, s'il en existe, ne sont pas touches.
        $this->cnx->executeStatement(
            'DELETE FROM shared.etablissement_contact WHERE etablissement_code LIKE ?',
            [self::PREFIXE_ETAB.'%']);

        $depot = $this->em->getRepository(Etablissement::class);
        $n = 0;

        foreach ($etabs as $e) {
            $numero = (int) substr((string) $e['id'], 4);
            $code = self::PREFIXE_ETAB.str_pad((string) $numero, 3, '0', \STR_PAD_LEFT);
            $societe = $societes[(string) $e['societe_id']]['nom'] ?? 'GROUPE SYNTHAUTO';

            $etab = $depot->find($code) ?? new Etablissement($code, 'SYNTHAUTO '.(string) $e['nom']);
            $etab->setLibelle('SYNTHAUTO '.(string) $e['nom']);
            $etab->setSociete((string) $societe);
            $etab->setNomLegalSepa((string) $societe.' — CSP SYNTHAUTO');
            $etab->setActif(true);
            // IBAN fictif, deterministe, et MATHEMATIQUEMENT VALIDE : vraie
            // cle RIB, vraies cles MOD 97. Le module refuse un IBAN mal forme,
            // et ce garde-fou ne se contourne pas -- on le nourrit. La banque
            // porte le code 99999, qui n'est attribue a aucun etablissement
            // bancaire : la forme est juste, le compte n'existe pas.
            $etab->setIban(IbanFictif::pour('ETAB-'.$code));
            $etab->setBic('SYNTFRP1XXX');
            $etab->setBanque('BANQUE DE DEMONSTRATION');
            $etab->setCompteContrepartie('512'.$code);
            $this->em->persist($etab);

            // Les contacts du site. Le module en a besoin pour deux gestes
            // reels : prevenir le directeur qu'un dossier attend sa validation,
            // et repondre a la secretaire quand une piece doit etre corrigee.
            // Adresses de FONCTION, sur un domaine .invalid : personne n'est
            // nomme, et aucune de ces adresses ne peut exister.
            foreach (['directeur' => 'directeur', 'secretaire' => 'secretariat'] as $role => $boite) {
                $contact = new EtablissementContact(
                    $etab, $boite.'.'.$code.'@demonstration.invalid', $role, 'chargement-univers');
                $this->em->persist($contact);
            }
            ++$n;
        }
        $this->em->flush();
        $this->em->clear();

        return $n;
    }

    /**
     * Charge les contrats Buy Back : c'est la source du controle anti-surpaiement.
     *
     * Sans eux, `ControleBuyBack::pour()` rend null sur toutes les plaques et le
     * garde-fou ne peut rien garder. C'est exactement ce qui manquait.
     */
    private function chargerBuyBack(string $monde): int
    {
        $this->cnx->executeStatement('TRUNCATE remboursement.buyback_vehicule');

        $contrats = $this->lire($monde, 'ref_contrat_buyback');
        $vehicules = $this->indexer($this->lire($monde, 'ref_vehicule'), 'id');
        $financeurs = $this->indexer($this->lire($monde, 'ref_financeur'), 'id');
        $marques = $this->indexer($this->lire($monde, 'ref_marque'), 'id');
        $clients = $this->indexer($this->lire($monde, 'ref_client'), 'id');

        $colonnes = '(immat, immatriculation, vin, marque, modele, contrat, financeur, type_fi,
                      client, er_ht, er_ttc, date_echeance, km_contrat, statut, importe_le)';
        $paquet = 400;
        $n = 0;

        for ($i = 0; $i < \count($contrats); $i += $paquet) {
            $tranche = \array_slice($contrats, $i, $paquet);
            $valeurs = [];
            $args = [];
            foreach ($tranche as $c) {
                $veh = $vehicules[(string) $c['vehicule_id']] ?? [];
                $fin = $financeurs[(string) $c['financeur_id']] ?? [];
                $marque = $marques[(string) ($veh['marque_id'] ?? '')]['nom'] ?? null;
                $client = $clients[(string) ($veh['client_id'] ?? '')]['nom'] ?? null;

                $valeurs[] = '('.implode(', ', array_fill(0, 15, '?')).')';
                foreach ([
                    CleDoublon::normaliserImmatriculation((string) $c['immatriculation']),
                    (string) $c['immatriculation'],
                    (string) $c['vin'],
                    $marque,
                    $veh['modele'] ?? null,
                    (string) $c['id'],
                    $fin['nom'] ?? null,
                    $fin['type'] ?? null,
                    $client,
                    $c['er_ht'],
                    $c['er_ttc'],
                    $c['echeance'],
                    $c['km_contrat'],
                    $c['statut'],
                    '2026-09-07 00:00:00',
                ] as $v) {
                    $args[] = $v;
                }
                ++$n;
            }
            $this->cnx->executeStatement(
                'INSERT INTO remboursement.buyback_vehicule '.$colonnes
                .' VALUES '.implode(', ', $valeurs), $args);
        }

        return $n;
    }

    /**
     * Charge les dossiers PAR LES ENTITES du module.
     *
     * @return array{dossiers: int, rachat: int, trop_percu: int, verite: int}
     */
    private function chargerDossiers(string $monde, string $verite, int $limite, SymfonyStyle $io): array
    {
        $this->cnx->executeStatement(
            'TRUNCATE remboursement.dossier_transition, remboursement.dossier_piece,
                      remboursement.extraction_piece, remboursement.dossier RESTART IDENTITY CASCADE');
        $this->cnx->executeStatement('TRUNCATE remboursement.source_piece_demo');

        // Les traces des deux RENFORCEMENTS de la copie suivent l'univers : un
        // rechargement complet redistribue les identifiants de transition, et
        // une chaine d'integrite qui pointerait les anciens serait fausse. Elles
        // sont donc reprises a zero, et reposees ensuite sur le nouveau journal.
        foreach ([
            'remboursement.journal_integrite',
            'remboursement.rejeu_evenement',
            'remboursement.paiement_empreinte',
        ] as $table) {
            if (null !== $this->cnx->fetchOne('SELECT to_regclass(?)', [$table])) {
                $this->cnx->executeStatement('TRUNCATE '.$table.' RESTART IDENTITY');
            }
        }

        $dossiers = $this->lire($monde, 'ops_dossier_remboursement');
        if ($limite > 0) {
            $dossiers = \array_slice($dossiers, 0, $limite);
        }
        $ibans = $this->indexer($this->lire($monde, 'ref_iban'), 'id');
        $clients = $this->indexer($this->lire($monde, 'ref_client'), 'id');
        $utilisateurs = $this->indexer($this->lire($monde, 'ref_utilisateur'), 'id');
        $veriteParId = $this->indexer($this->lire($verite, 'truth_remboursement'), 'objet_id');

        $bilan = ['dossiers' => 0, 'rachat' => 0, 'trop_percu' => 0, 'verite' => 0];
        /** @var list<array{0: Dossier, 1: array<string, mixed>}> $enAttente */
        $enAttente = [];
        $io->progressStart(\count($dossiers));

        foreach ($dossiers as $i => $d) {
            $motif = DossierMotif::from((string) $d['motif']);
            $client = $clients[(string) $d['client_id']] ?? [];
            $ibanSaisi = $ibans[(string) $d['iban_saisi_id']]['iban'] ?? null;
            $deposant = $utilisateurs[(string) $d['deposant_id']] ?? [];
            $numero = (int) substr((string) $d['etablissement_id'], 4);

            $auteur = \sprintf('%s@demonstration.invalid',
                strtolower(str_replace(' ', '.', (string) ($deposant['nom'] ?? 'secretaire'))));

            // Le constructeur du module exige le motif et l'auteur : on l'appelle
            // tel quel plutot que de contourner l'entite.
            // Le constructeur du module engendre lui-meme la reference et pose
            // BROUILLON. On ne la surcharge pas : la correspondance avec
            // l'identifiant de la fabrique est conservee dans la verite.
            $dossier = new Dossier($motif, $auteur);
            $dossier->setEtablissementCode(self::PREFIXE_ETAB.str_pad((string) $numero, 3, '0', \STR_PAD_LEFT));
            $dossier->setNomClient((string) ($client['nom'] ?? 'CLIENT SYNTHETIQUE'));
            $dossier->setIbanClient(\is_string($ibanSaisi) ? $ibanSaisi : null);
            $dossier->setBicClient('SYNTFRP1XXX');
            $dossier->setMontant((string) $d['montant_demande']);
            $dossier->setDeposeLe(new DateTimeImmutable((string) $d['date_depot']));

            if (DossierMotif::RACHAT_SEC === $motif) {
                $dossier->setImmatriculation((string) $d['immatriculation']);
                ++$bilan['rachat'];
            } else {
                $dossier->setCodeIcar((string) $d['code_icar']);
                ++$bilan['trop_percu'];
            }

            // La cle anti-doublon et l'empreinte IBAN sont calculees par le
            // module, exactement comme au depot reel.
            // L'empreinte IBAN est posee par setIbanClient() : le module la
            // calcule lui-meme, et c'est bien ce qu'on veut verifier.
            $dossier->setCleDoublon(CleDoublon::pour($dossier));

            $this->em->persist($dossier);

            // LE DEPOT PASSE PAR LE WORKFLOW HERITE. `definirStatut()` est
            // reserve a WorkflowRemboursement, et on ne le contourne pas : la
            // transition « deposer » est franchie pour de vrai, et elle trace
            // son DossierTransition. Les 2 500 dossiers arrivent donc avec un
            // journal d'audit authentique, pas avec un statut pose de force.
            //
            // `sansGarde` est vrai parce qu'aucun utilisateur n'est connecte
            // dans une commande : la garde de role est verifiee a l'usage, par
            // les ecrans, et le peuplement de l'univers n'est pas un acte
            // utilisateur.
            $this->workflow->appliquer($dossier, 'deposer', $auteur,
                "Depot initial de l'univers de demonstration.", false, false, true);

            // Ce que les PIECES de ce dossier montreront. Le monde le dit : un
            // RIB peut porter un autre IBAN que celui saisi, une facture un
            // autre montant, un document peut etre illisible, un fournisseur
            // en panne. Rien ici ne dit ce que le dossier DOIT devenir.
            // L'identifiant du dossier n'existe qu'apres le flush du lot : on
            // met les faits documentaires en attente et on les ecrit juste apres.
            $enAttente[] = [$dossier, [
                'iban_rib' => $ibans[(string) $d['iban_extrait_id']]['iban'] ?? $ibanSaisi,
                'montant_facture' => (string) $d['montant_extrait'],
                'immatriculation_cg' => $d['immatriculation'] ?? null,
                'panne_extraction' => ($d['panne_extraction'] ?? false) ? 'true' : 'false',
                'piece_illisible' => ($d['piece_illisible'] ?? false) ? 'true' : 'false',
                'mention_manuscrite' => ($d['mention_manuscrite'] ?? false) ? 'true' : 'false',
                'vehicule_gage' => ($d['vehicule_gage'] ?? false) ? 'true' : 'false',
                'estimation_retouchee' => ($d['estimation_retouchee'] ?? false) ? 'true' : 'false',
            ]];
            ++$bilan['dossiers'];

            $v = $veriteParId[(string) $d['id']] ?? null;
            if (null !== $v) {
                $this->cnx->insert('remboursement_verite.dossier', [
                    'objet_id' => (string) $d['id'],
                    // La reference que le MODULE a engendree : c'est par elle que
                    // la mesure rejoint le dossier reel.
                    'reference' => $dossier->getReference(),
                    'decision_attendue' => (string) $v['decision_attendue'],
                    'controle_declencheur' => $v['controle_declencheur'] ?? null,
                    'ecart_contractuel' => $v['ecart_contractuel'] ?? null,
                    'surpaiement_reel' => (bool) ($v['surpaiement_reel'] ?? false) ? 'true' : 'false',
                    'code_scenario' => (string) $v['code_scenario'],
                ]);
                ++$bilan['verite'];
            }

            if (0 === ($i + 1) % 250) {
                $this->em->flush();
                $enAttente = $this->ecrireSourcesPieces($enAttente);
                $this->em->clear();
                $io->progressAdvance(250);
            }
        }
        $this->em->flush();
        $this->ecrireSourcesPieces($enAttente);
        $this->em->clear();
        $io->progressFinish();

        return $bilan;
    }

    /**
     * Ecrit les faits documentaires en attente, maintenant que les dossiers ont
     * un identifiant, et rend un buffer vide.
     *
     * @param list<array{0: Dossier, 1: array<string, mixed>}> $enAttente
     *
     * @return list<array{0: Dossier, 1: array<string, mixed>}>
     */
    private function ecrireSourcesPieces(array $enAttente): array
    {
        foreach ($enAttente as [$dossier, $donnees]) {
            $id = (int) $dossier->getId();
            if (0 === $id) {
                throw new RuntimeException('Dossier sans identifiant au moment de poser ses pieces.');
            }
            $this->cnx->insert('remboursement.source_piece_demo',
                ['dossier_id' => $id] + $donnees);
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexer(array $lignes, string $cle): array
    {
        $out = [];
        foreach ($lignes as $l) {
            $out[(string) $l[$cle]] = $l;
        }

        return $out;
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
