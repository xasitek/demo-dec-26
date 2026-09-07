<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Entity\DossierTransition;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Shared\Entity\Etablissement;
use App\Shared\Entity\EtablissementContact;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Donnees FICTIVES du module Remboursement (dev only) : etablissements fictifs
 * (avec coordonnees bancaires debiteur + directeur) et dossiers repartis sur tous
 * les statuts, avec pieces et journal d'audit coherents. Sert a peupler le poste
 * comptable en local (file "a verifier", suivi, statuts) sans aucune donnee reelle.
 *
 * Idempotente : purge d'abord ses propres donnees (marqueur `cree_par` + codes
 * etablissement 90xx) avant de recreer. Refuse APP_ENV=prod sauf --force.
 */
#[AsCommand(
    name: 'app:remboursement:donnees-demo',
    description: 'Cree des etablissements et dossiers de remboursement FICTIFS (dev only).',
)]
final class DonneesDemoCommand extends Command
{
    /** Marqueur des dossiers de demo (colonne cree_par) : cible de purge. */
    private const MARQUEUR = 'demo@demonstration.invalid';

    private const SECRETAIRE = 'secretaire.demo@demonstration.invalid';
    private const COMPTABLE = 'comptable.demo@demonstration.invalid';

    /** @var list<array{code: string, libelle: string, societe: string, banque: string, iban: string, bic: string, sepa: string, cinq: string, directeur: string}> */
    private const ETABLISSEMENTS = [
        ['code' => '9001', 'libelle' => 'SYNTHAUTO OSKNEM 67 VN', 'societe' => 'GROUPE SYNTHAUTO', 'banque' => 'Banque de demonstration', 'iban' => 'FR7699999900010123456780134', 'bic' => 'SYNTFRP1XXX', 'sepa' => 'GROUPE SYNTHAUTO — CSP', 'cinq' => '5120901', 'directeur' => 'directeur.osknem@demonstration.invalid'],
        ['code' => '9002', 'libelle' => 'SYNTHAUTO CAVVOR 68 VO', 'societe' => 'GROUPE SYNTHAUTO', 'banque' => 'Banque de demonstration', 'iban' => 'FR7699999900020123456780216', 'bic' => 'SYNTFRP1XXX', 'sepa' => 'GROUPE SYNTHAUTO — CSP', 'cinq' => '5120902', 'directeur' => 'directeur.cavvor@demonstration.invalid'],
        ['code' => '9003', 'libelle' => 'SYNTHAUTO VOROSK 68 CARR', 'societe' => 'GROUPE SYNTHAUTO', 'banque' => 'Banque de demonstration', 'iban' => 'FR7699999900030123456780395', 'bic' => 'SYNTFRP1XXX', 'sepa' => 'GROUPE SYNTHAUTO — CSP', 'cinq' => '5120903', 'directeur' => 'directeur.vorosk@demonstration.invalid'],
        ['code' => '9004', 'libelle' => 'BRIXDAN 54 VN', 'societe' => 'BRIXBRYBRIX Distribution', 'banque' => 'Banque de demonstration', 'iban' => 'FR7699999900040123456780477', 'bic' => 'SYNTFRP1XXX', 'sepa' => 'BRIXBRYBRIX Distribution', 'cinq' => '5120904', 'directeur' => 'directeur.brixdan@demonstration.invalid'],
        ['code' => '9005', 'libelle' => 'BRIXTAL 54 VO', 'societe' => 'BRIXBRYBRIX Distribution', 'banque' => 'Banque de demonstration', 'iban' => 'FR7699999900050123456780559', 'bic' => 'SYNTFRP1XXX', 'sepa' => 'BRIXBRYBRIX Distribution', 'cinq' => '5120905', 'directeur' => 'directeur.brixtal@demonstration.invalid'],
        ['code' => '9006', 'libelle' => 'SYNTHAUTO TEGOSK 90 VN', 'societe' => 'GROUPE SYNTHAUTO', 'banque' => 'Banque de demonstration', 'iban' => 'FR7699999900060123456780641', 'bic' => 'SYNTFRP1XXX', 'sepa' => 'GROUPE SYNTHAUTO — CSP', 'cinq' => '5120906', 'directeur' => 'directeur.tegosk@demonstration.invalid'],
    ];

    /**
     * Dossiers fictifs. `ref` = immatriculation (rachat) ou code ICAR (trop-percu).
     *
     * @var list<array{motif: string, statut: string, etab: string, client: string, montant: numeric-string, ref: string, verdict: ?string, ecart?: string, iban?: string}>
     */
    private const DOSSIERS = [
        ['motif' => 'rachat_sec', 'statut' => 'a_verifier', 'etab' => '9001', 'client' => 'Dupont Jean', 'montant' => '12500.00', 'ref' => 'EA-123-BC', 'verdict' => 'valide'],
        // Second dossier ACTIF de meme immatriculation -> declenche le bandeau doublon comptable.
        ['motif' => 'rachat_sec', 'statut' => 'a_verifier', 'etab' => '9002', 'client' => 'Colin Yanis', 'montant' => '12500.00', 'ref' => 'EA-123-BC', 'verdict' => 'valide'],
        ['motif' => 'trop_percu', 'statut' => 'a_verifier', 'etab' => '9002', 'client' => 'Martin Sophie', 'montant' => '842.50', 'ref' => 'ICAR-778812', 'verdict' => 'invalide'],
        ['motif' => 'rachat_sec', 'statut' => 'a_verifier', 'etab' => '9003', 'client' => 'Bernard Luc', 'montant' => '9800.00', 'ref' => 'FG-456-HJ', 'verdict' => 'valide'],
        ['motif' => 'rachat_sec', 'statut' => 'cas_icar', 'etab' => '9001', 'client' => 'Petit Marie', 'montant' => '15200.00', 'ref' => 'DD-789-KL', 'verdict' => null],
        ['motif' => 'trop_percu', 'statut' => 'complement_requis', 'etab' => '9004', 'client' => 'Durand Paul', 'montant' => '1290.00', 'ref' => 'ICAR-990021', 'verdict' => null],
        ['motif' => 'rachat_sec', 'statut' => 'a_valider_directeur', 'etab' => '9002', 'client' => 'Robert Alice', 'montant' => '11000.00', 'ref' => 'GH-012-MN', 'verdict' => 'valide'],
        ['motif' => 'trop_percu', 'statut' => 'a_valider_directeur', 'etab' => '9005', 'client' => 'Richard Emma', 'montant' => '640.00', 'ref' => 'ICAR-334455', 'verdict' => 'valide'],
        ['motif' => 'rachat_sec', 'statut' => 'valide_directeur', 'etab' => '9003', 'client' => 'Moreau Hugo', 'montant' => '13750.00', 'ref' => 'JK-345-PQ', 'verdict' => 'valide', 'ecart' => 'montant'],
        ['motif' => 'rachat_sec', 'statut' => 'confirme', 'etab' => '9001', 'client' => 'Simon Lea', 'montant' => '8600.00', 'ref' => 'LM-678-RS', 'verdict' => 'valide', 'ecart' => 'iban'],
        ['motif' => 'trop_percu', 'statut' => 'paye', 'etab' => '9006', 'client' => 'Laurent Theo', 'montant' => '1520.00', 'ref' => 'ICAR-556677', 'verdict' => 'valide'],
        ['motif' => 'rachat_sec', 'statut' => 'paye', 'etab' => '9002', 'client' => 'Michel Chloe', 'montant' => '10250.00', 'ref' => 'NP-901-TU', 'verdict' => 'valide'],
        ['motif' => 'rachat_sec', 'statut' => 'lettre', 'etab' => '9004', 'client' => 'Garcia Nathan', 'montant' => '9990.00', 'ref' => 'QR-234-VW', 'verdict' => 'valide'],
        ['motif' => 'trop_percu', 'statut' => 'refuse', 'etab' => '9005', 'client' => 'David Manon', 'montant' => '430.00', 'ref' => 'ICAR-889900', 'verdict' => 'invalide'],
        ['motif' => 'rachat_sec', 'statut' => 'doublon', 'etab' => '9001', 'client' => 'Roux Enzo', 'montant' => '12500.00', 'ref' => 'EA-123-BC', 'verdict' => null],
        ['motif' => 'rachat_sec', 'statut' => 'erreur_generation', 'etab' => '9003', 'client' => 'Fontaine Jade', 'montant' => '14100.00', 'ref' => 'ST-567-XY', 'verdict' => 'valide'],
        // "En cours de paiement" (file "A telecharger" du poste Paiements). Deux portent un
        // ecart saisie/IA/valide (IBAN ou montant modifie a la validation) pour le manager.
        ['motif' => 'rachat_sec', 'statut' => 'generation_en_cours', 'etab' => '9002', 'client' => 'Blanc Adam', 'montant' => '9200.00', 'ref' => 'VV-111-AA', 'verdict' => 'valide', 'ecart' => 'montant'],
        ['motif' => 'trop_percu', 'statut' => 'generation_en_cours', 'etab' => '9005', 'client' => 'Faure Lina', 'montant' => '780.00', 'ref' => 'ICAR-222333', 'verdict' => 'valide', 'ecart' => 'iban'],
        ['motif' => 'rachat_sec', 'statut' => 'generation_en_cours', 'etab' => '9001', 'client' => 'Roy Sacha', 'montant' => '8800.00', 'ref' => 'WW-222-BB', 'verdict' => 'valide'],
        // Deux trop-percus DIFFERENTS (ICAR distincts) sur le MEME IBAN -> "meme compte
        // bancaire" (index aveugle), ce que l'immat/ICAR ne rattrape pas (cas de deux titulaires distincts sur un meme compte).
        ['motif' => 'trop_percu', 'statut' => 'a_verifier', 'etab' => '9002', 'client' => 'Velnau Fargil', 'montant' => '15000.00', 'ref' => 'ICAR-451222', 'verdict' => 'valide', 'iban' => 'FR7699999900070123456780723'],
        ['motif' => 'trop_percu', 'statut' => 'a_valider_directeur', 'etab' => '9002', 'client' => 'Velnau Kirdan', 'montant' => '339.21', 'ref' => 'ICAR-565451', 'verdict' => 'valide', 'iban' => 'FR7699999900070123456780723'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')]
        private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, null, 'Autoriser meme hors environnement dev.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->environnement && !$input->getOption('force')) {
            $io->error(sprintf('Refuse en environnement "%s" (donnees fictives). Relancer avec --force si vraiment voulu.', $this->environnement));

            return Command::FAILURE;
        }

        $this->purger();
        $io->writeln('Anciennes donnees de demo purgees.');

        $directeurs = $this->creerEtablissements();
        $io->writeln(sprintf('%d etablissements fictifs crees.', \count(self::ETABLISSEMENTS)));

        $n = 0;
        foreach (self::DOSSIERS as $spec) {
            $this->creerDossier($spec, $directeurs);
            ++$n;
        }
        $this->em->flush();

        $io->success(sprintf('%d dossiers fictifs crees, repartis sur les statuts. Poste comptable pret a l\'emploi.', $n));

        return Command::SUCCESS;
    }

    /** Supprime les donnees de demo precedentes (idempotence). */
    private function purger(): void
    {
        $conn = $this->em->getConnection();
        // dossier -> piece/transition en CASCADE (onDelete DB).
        $conn->executeStatement('DELETE FROM remboursement.dossier WHERE cree_par = :m', ['m' => self::MARQUEUR]);
        $codes = array_column(self::ETABLISSEMENTS, 'code');
        $conn->executeStatement(
            'DELETE FROM shared.etablissement_contact WHERE etablissement_code IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM shared.etablissement WHERE code_etab IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    /**
     * Cree les etablissements fictifs (+ contact directeur) et renvoie la carte
     * code -> e-mail directeur (acteur des transitions de validation).
     *
     * @return array<int|string, string>
     */
    private function creerEtablissements(): array
    {
        $directeurs = [];
        foreach (self::ETABLISSEMENTS as $e) {
            $etab = new Etablissement($e['code'], $e['libelle']);
            $etab->setSociete($e['societe']);
            $etab->setNomLegalSepa($e['sepa']);
            $etab->setIban($e['iban']);
            $etab->setBic($e['bic']);
            $etab->setBanque($e['banque']);
            $etab->setCompteContrepartie($e['cinq']);
            $this->em->persist($etab);
            $this->em->persist(new EtablissementContact($etab, $e['directeur'], 'directeur', self::MARQUEUR));
            $directeurs[$e['code']] = $e['directeur'];
        }
        $this->em->flush();

        return $directeurs;
    }

    /**
     * @param array{motif: string, statut: string, etab: string, client: string, montant: numeric-string, ref: string, verdict: ?string, ecart?: string, iban?: string} $spec
     * @param array<int|string, string>                                                                                                                                 $directeurs
     */
    private function creerDossier(array $spec, array $directeurs): void
    {
        $motif = DossierMotif::from($spec['motif']);
        $cible = DossierStatut::from($spec['statut']);
        // IBAN de base : partageable entre dossiers via la cle 'iban' (illustre le "meme
        // compte bancaire") ; sinon derive de la reference.
        $ibanBase = $spec['iban'] ?? $this->ibanClientFictif($spec['ref']);

        $dossier = new Dossier($motif, self::MARQUEUR);
        $dossier->setEtablissementCode($spec['etab']);
        $dossier->setNomClient($spec['client']);
        $dossier->setIbanClient($ibanBase);
        $dossier->setBicClient('AGRIFRPP889');
        $dossier->setMontant($spec['montant']);

        if (DossierMotif::RACHAT_SEC === $motif) {
            $dossier->setImmatriculation($spec['ref']);
            $dossier->setCleDoublon(strtoupper(str_replace('-', '', $spec['ref'])));
        } else {
            $dossier->setCodeIcar($spec['ref']);
            $dossier->setCleDoublon(strtoupper($spec['ref']));
        }

        // Champs controles (extraction IA "corrigee") a partir de l'etape a_verifier.
        if (!\in_array($cible, [DossierStatut::DEPOSE, DossierStatut::EXTRACTION_IA, DossierStatut::DOUBLON], true)) {
            $dossier->setControleNom(strtoupper($spec['client']));
            $dossier->setControleIban($ibanBase);
            $dossier->setControleBic('AGRIFRPP889');
            // Petite divergence de montant pour illustrer le cas "invalide".
            $dossier->setControleMontant('invalide' === $spec['verdict'] ? bcsub($spec['montant'], '50.00', 2) : $spec['montant']);
            if (DossierMotif::RACHAT_SEC === $motif) {
                $dossier->setControleImmatriculation($spec['ref']);
            } else {
                $dossier->setControleIcar($spec['ref']);
            }
            $dossier->setVerdictIa($spec['verdict']);
            if ('invalide' === $spec['verdict']) {
                $dossier->setVerdictInfo('Divergence montant saisie / piece : a verifier.');
            }
        }

        // Valeurs VALIDEES par la comptable (des l'envoi au directeur). Deux dossiers
        // portent un ECART volontaire (IBAN ou montant modifie a la validation) pour
        // illustrer le controle "ecart saisie/IA/valide" de l'ecran Paiements.
        if (self::rangNominal($cible) >= self::rangNominal(DossierStatut::A_VALIDER_DIRECTEUR)) {
            $ibanValide = $ibanBase;
            $montantValide = $spec['montant'];
            if ('iban' === ($spec['ecart'] ?? null)) {
                $ibanValide = 'FR7699999900080123456780805';
            }
            if ('montant' === ($spec['ecart'] ?? null)) {
                $montantValide = bcsub($spec['montant'], '100.00', 2);
            }
            $dossier->enregistrerValidation([
                'nom' => strtoupper($spec['client']),
                'iban' => $ibanValide,
                'bic' => 'AGRIFRPP889',
                'montant' => $montantValide,
                'immatriculation' => DossierMotif::RACHAT_SEC === $motif ? $spec['ref'] : null,
                'code_icar' => DossierMotif::RACHAT_SEC === $motif ? null : $spec['ref'],
            ], self::COMPTABLE);
        }

        if (DossierStatut::REFUSE === $cible) {
            $dossier->setRefusMotif('RIB illisible et estimation non concordante (demo).');
        }

        $dossier->definirStatut($cible);
        $this->appliquerJalons($dossier, $cible);
        $this->em->persist($dossier);

        $this->creerPieces($dossier, $motif);
        $this->creerHistorique($dossier, $cible, $directeurs[$spec['etab']] ?? self::COMPTABLE);
    }

    /** Positionne les jalons dates selon l'avancement atteint. */
    private function appliquerJalons(Dossier $dossier, DossierStatut $cible): void
    {
        $atteint = self::rangNominal($cible);
        if ($atteint >= self::rangNominal(DossierStatut::DEPOSE)) {
            $dossier->setDeposeLe(new DateTimeImmutable('-6 days'));
        }
        if ($atteint >= self::rangNominal(DossierStatut::VALIDE_DIRECTEUR)) {
            $dossier->setValideDirecteurLe(new DateTimeImmutable('-3 days'));
        }
        if ($atteint >= self::rangNominal(DossierStatut::CONFIRME)) {
            $dossier->setConfirmeLe(new DateTimeImmutable('-2 days'));
        }
        if ($cible->estPaye()) {
            $dossier->setPayeLe(new DateTimeImmutable('-1 days'));
        }
    }

    private function creerPieces(Dossier $dossier, DossierMotif $motif): void
    {
        $ordre = 0;
        foreach ($motif->piecesRequises() as $type => $libelle) {
            $this->em->persist(new DossierPiece(
                $dossier,
                $type,
                sprintf('%s-%s.pdf', $type, $dossier->getReference()),
                "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
                'application/pdf',
                40000 + $ordre * 1234,
                hash('sha256', $dossier->getReference().$type),
                self::SECRETAIRE,
            ));
            ++$ordre;
        }
    }

    /**
     * Reconstruit le journal d'audit (transitions franchies) jusqu'au statut cible :
     * chemin nominal, avec la derniere marche derivee pour les branches.
     */
    private function creerHistorique(Dossier $dossier, DossierStatut $cible, string $directeurEmail): void
    {
        foreach (self::chemin($cible) as [$de, $vers, $transition, $acteur]) {
            $par = match ($acteur) {
                'secretaire' => self::SECRETAIRE,
                'comptable' => self::COMPTABLE,
                'directeur' => $directeurEmail,
                default => null, // systeme
            };
            $this->em->persist(new DossierTransition($dossier, $de, $vers, $transition, $par));
        }
    }

    /**
     * Suite de transitions [de, vers, nom, acteur] menant au statut cible.
     *
     * @return list<array{0: DossierStatut, 1: DossierStatut, 2: string, 3: string}>
     */
    private static function chemin(DossierStatut $cible): array
    {
        $nominal = [
            [DossierStatut::BROUILLON, DossierStatut::DEPOSE, 'deposer', 'secretaire'],
            [DossierStatut::DEPOSE, DossierStatut::EXTRACTION_IA, 'demarrer_extraction', 'systeme'],
            [DossierStatut::EXTRACTION_IA, DossierStatut::A_VERIFIER, 'terminer_extraction', 'systeme'],
            [DossierStatut::A_VERIFIER, DossierStatut::A_VALIDER_DIRECTEUR, 'envoyer_directeur', 'comptable'],
            [DossierStatut::A_VALIDER_DIRECTEUR, DossierStatut::VALIDE_DIRECTEUR, 'valider_directeur', 'directeur'],
            [DossierStatut::VALIDE_DIRECTEUR, DossierStatut::CONFIRME, 'confirmer', 'comptable'],
            [DossierStatut::CONFIRME, DossierStatut::GENERATION_EN_COURS, 'demarrer_generation', 'systeme'],
            [DossierStatut::GENERATION_EN_COURS, DossierStatut::PAYE, 'terminer_generation', 'systeme'],
            [DossierStatut::PAYE, DossierStatut::LETTRE, 'lettrer', 'comptable'],
        ];

        // Branches : point de derivation (statut base) + marche finale.
        $branches = [
            DossierStatut::COMPLEMENT_REQUIS->value => [DossierStatut::A_VERIFIER, [DossierStatut::A_VERIFIER, DossierStatut::COMPLEMENT_REQUIS, 'demander_complement', 'comptable']],
            DossierStatut::CAS_ICAR->value => [DossierStatut::A_VERIFIER, [DossierStatut::A_VERIFIER, DossierStatut::CAS_ICAR, 'basculer_icar', 'comptable']],
            DossierStatut::REFUSE->value => [DossierStatut::A_VERIFIER, [DossierStatut::A_VERIFIER, DossierStatut::REFUSE, 'refuser_comptable', 'comptable']],
            DossierStatut::FRAUDE->value => [DossierStatut::A_VERIFIER, [DossierStatut::A_VERIFIER, DossierStatut::FRAUDE, 'marquer_fraude', 'comptable']],
            DossierStatut::DOUBLON->value => [DossierStatut::DEPOSE, [DossierStatut::DEPOSE, DossierStatut::DOUBLON, 'marquer_doublon', 'systeme']],
            DossierStatut::ERREUR_GENERATION->value => [DossierStatut::GENERATION_EN_COURS, [DossierStatut::GENERATION_EN_COURS, DossierStatut::ERREUR_GENERATION, 'echec_generation', 'systeme']],
        ];

        if (isset($branches[$cible->value])) {
            [$base, $marche] = $branches[$cible->value];
            $steps = self::tronquer($nominal, $base);
            $steps[] = $marche;

            return $steps;
        }

        return self::tronquer($nominal, $cible);
    }

    /**
     * Garde les marches du chemin nominal jusqu'au statut `base` (inclus).
     *
     * @param list<array{0: DossierStatut, 1: DossierStatut, 2: string, 3: string}> $nominal
     *
     * @return list<array{0: DossierStatut, 1: DossierStatut, 2: string, 3: string}>
     */
    private static function tronquer(array $nominal, DossierStatut $base): array
    {
        $steps = [];
        foreach ($nominal as $step) {
            $steps[] = $step;
            if ($step[1] === $base) {
                break;
            }
        }

        return $steps;
    }

    /** Rang du statut dans le chemin nominal (pour les jalons). -1 si hors nominal. */
    private static function rangNominal(DossierStatut $statut): int
    {
        $ordre = [
            DossierStatut::BROUILLON, DossierStatut::DEPOSE, DossierStatut::EXTRACTION_IA,
            DossierStatut::A_VERIFIER, DossierStatut::A_VALIDER_DIRECTEUR, DossierStatut::VALIDE_DIRECTEUR,
            DossierStatut::CONFIRME, DossierStatut::GENERATION_EN_COURS, DossierStatut::PAYE, DossierStatut::LETTRE,
        ];
        $rang = array_search($statut, $ordre, true);

        // Branches actives : rattachees au rang de leur point de derivation.
        if (false === $rang) {
            return match ($statut) {
                DossierStatut::COMPLEMENT_REQUIS, DossierStatut::CAS_ICAR, DossierStatut::REFUSE, DossierStatut::FRAUDE => 3,
                DossierStatut::ERREUR_GENERATION => 7,
                default => -1,
            };
        }

        return $rang;
    }

    private function ibanClientFictif(string $graine): string
    {
        // IBAN client fictif deterministe (jamais un vrai) derive de la reference.
        $chiffres = substr(str_pad((string) abs(crc32($graine)), 18, '0'), 0, 18);

        return 'FR76'.$chiffres.'042';
    }
}
