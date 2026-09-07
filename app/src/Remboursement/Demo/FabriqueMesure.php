<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Entity\BuyBackVehicule;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Repository\BuyBackVehiculeRepository;
use App\Remboursement\Service\CleDoublon;
use App\Remboursement\Service\FabricantPieces;
use App\Remboursement\Service\IbanFictif;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * La fabrique de l'ENVIRONNEMENT DE MESURE, separee de l'univers jury.
 *
 * POURQUOI UN ENVIRONNEMENT SEPARE. L'univers jury porte 2 500 dossiers ouverts
 * en meme temps -- un etat qui n'existe dans aucun service, et qui presentait au
 * controle de doublon deux mille quatre cent quatre-vingt-dix-neuf dossiers
 * comme s'ils faisaient partie du scenario. Le controle avait raison de les
 * voir ; c'est le protocole qui avait tort de les lui montrer. La mesure
 * diagnostique l'a etabli, et c'est la seule chose qu'elle etablit.
 *
 * COMMENT L'ISOLATION EST OBTENUE. Chaque cas s'evalue dans une TRANSACTION que
 * l'on annule ensuite. A l'ouverture, la table des dossiers est videe -- dans la
 * transaction, donc invisible du reste du monde --, puis la premisse du cas est
 * construite, puis le cas est evalue, puis tout est annule. L'univers jury n'est
 * jamais modifie : apres la mesure, il est bit pour bit ce qu'il etait avant.
 *
 * L'objectif n'est PAS de neutraliser le controle de doublon. Il travaille
 * pleinement : quand un cas veut un doublon, la fabrique cree le dossier temoin,
 * et c'est le module qui le trouve. L'objectif est de ne pas lui presenter, par
 * accident, deux mille autres dossiers comme partie du meme scenario.
 *
 * LE MEME CODE METIER. Rien n'est reimplemente : les dossiers naissent par
 * `DepotDossier` ou par les entites du module, les transitions passent par
 * `WorkflowRemboursement`, les pieces par `DossierPiece`, la lecture par
 * `AnalyseDossierService`, le paiement par le bus. Seule la frontiere de
 * transaction est ajoutee.
 */
final class FabriqueMesure
{
    /** Les tables que la mesure vide dans sa transaction, avant de batir son cas. */
    /**
     * La structure d'IBAN que le module exige reellement, recopiee de
     * GenerateurSepa::exigerIban(). On ne la durcit pas : on la constate.
     */
    private const STRUCTURE_IBAN = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/';

    private const TABLES_A_VIDER = [
        'remboursement.journal_integrite',
        'remboursement.rejeu_evenement',
        'remboursement.paiement_empreinte',
        'remboursement.source_piece_demo',
        'remboursement.extraction_piece',
        'remboursement.dossier_piece',
        'remboursement.dossier_transition',
        'remboursement.dossier',
    ];

    private ?int $graine = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly WorkflowRemboursement $workflow,
        private readonly BuyBackVehiculeRepository $buyBacks,
        private readonly FabricantPieces $fabricant,
    ) {
    }

    /** La graine de la population : elle rend la mesure rejouable a l'identique. */
    public function semer(int $graine): void
    {
        $this->graine = $graine;
    }

    /** Ouvre la transaction du cas et met la table des dossiers a nu. */
    public function ouvrir(): void
    {
        $this->cnx->setNestTransactionsWithSavepoints(true);
        $this->cnx->beginTransaction();
        foreach (self::TABLES_A_VIDER as $table) {
            if (null !== $this->cnx->fetchOne('SELECT to_regclass(?)', [$table])) {
                $this->cnx->executeStatement('DELETE FROM '.$table);
            }
        }
        $this->em->clear();
    }

    /** Annule tout ce que le cas a ecrit. L'univers jury n'a pas bouge. */
    public function annuler(): void
    {
        if ($this->cnx->isTransactionActive()) {
            $this->cnx->rollBack();
        }
        $this->em->clear();
    }

    /**
     * Un contrat Buy Back reel, tire de facon deterministe.
     *
     * Les contrats ne sont pas vides par l'isolation : ils appartiennent au
     * referentiel, pas au flux des dossiers.
     */
    public function contrat(int $rang): BuyBackVehicule
    {
        $ids = $this->cnx->fetchFirstColumn(
            'SELECT immat FROM remboursement.buyback_vehicule ORDER BY immat LIMIT 400');
        if ([] === $ids) {
            throw new RuntimeException('Aucun contrat Buy Back : rechargez l\'univers.');
        }
        $immat = (string) $ids[$rang % \count($ids)];
        $contrat = $this->buyBacks->findOneBy(['immat' => $immat]);
        if (null === $contrat) {
            throw new RuntimeException('Contrat Buy Back introuvable : '.$immat);
        }

        return $contrat;
    }

    /** Un etablissement reel du referentiel, tire de facon deterministe. */
    public function etablissement(int $rang): string
    {
        $codes = $this->cnx->fetchFirstColumn(
            "SELECT code_etab FROM shared.etablissement WHERE code_etab LIKE '8%' ORDER BY code_etab");
        if ([] === $codes) {
            throw new RuntimeException('Aucun etablissement de demonstration.');
        }

        return (string) $codes[$rang % \count($codes)];
    }

    /** Un IBAN fictif valide, deterministe pour un rang donne. */
    public function iban(int $rang): string
    {
        return IbanFictif::pour(sprintf('MESURE-%d-%d', $this->graine ?? 0, $rang));
    }

    /** Un nom de client synthetique, deterministe et reconnaissable comme invente. */
    public function client(int $rang): string
    {
        $syllabes = ['Velkur', 'Nauteg', 'Dulcewen', 'Kressnau', 'Trikal', 'Voltnem',
            'Brixdan', 'Cavvor', 'Vorosk', 'Tegosk', 'Gilnau', 'Kalval'];
        $suffixes = ['Fargil', 'Kirdan', 'Maevor', 'Sabfex', 'Osklim', 'Talgil',
            'Pelnau', 'Zorlim', 'Wensol', 'Ryndan'];

        return $syllabes[$rang % \count($syllabes)].' '.$suffixes[($rang * 7) % \count($suffixes)];
    }

    /**
     * Cree un dossier DE REFERENCE, actif, par les entites et le workflow reels.
     *
     * C'est la premisse des cas de doublon : sans temoin, il n'y a rien a
     * detecter, et une verite qui annoncerait un doublon serait fausse.
     */
    public function dossierTemoin(
        DossierMotif $motif,
        string $etablissement,
        string $client,
        string $iban,
        string $montant,
        ?string $immatriculation,
        ?string $codeIcar,
    ): Dossier {
        $auteur = 'secretariat.'.$etablissement.'@demonstration.invalid';
        $dossier = new Dossier($motif, $auteur);
        $dossier->setEtablissementCode($etablissement);
        $dossier->setNomClient($client);
        $dossier->setIbanClient($iban);
        $dossier->setBicClient('SYNTFRP1XXX');
        $dossier->setMontant($montant);
        if (DossierMotif::RACHAT_SEC === $motif) {
            $dossier->setImmatriculation($immatriculation);
        }
        $dossier->setCodeIcar($codeIcar);
        $dossier->setCleDoublon(CleDoublon::pour($dossier));
        $this->em->persist($dossier);
        $this->workflow->appliquer($dossier, 'deposer', $auteur,
            'Dossier temoin du cas de mesure.', false, true, true);

        return $dossier;
    }

    /**
     * Attache les pieces d'un dossier, en `bytea`, par le vrai modele.
     *
     * @param array<string, mixed> $contexte les faits que les documents portent
     */
    public function pieces(Dossier $dossier, array $contexte = []): int
    {
        $n = 0;
        foreach ($this->fabricant->typesPour($dossier->getMotif(), false) as $type) {
            $doc = $this->fabricant->pour($dossier, $type, null, $contexte);
            $this->em->persist(new DossierPiece(
                $dossier, $type, $doc['nom'], $doc['html'], $doc['mime'],
                \strlen($doc['html']), hash('sha256', $doc['html']),
                'mesure@demonstration.invalid'));
            ++$n;
        }
        $this->em->flush();

        return $n;
    }

    /**
     * L'INVARIANT DE PREMISSE : ce que le cas exige doit etre la, materiellement.
     *
     * On ne verifie pas une intention, on compte des lignes. Un cas de doublon
     * exige un dossier temoin actif ; un cas de surpaiement exige un contrat et
     * un montant qui permettent de recalculer l'ecart ; un cas d'IBAN invalide
     * exige une saisie reellement invalide. Si l'objet manque, la mesure
     * s'arrete : mieux vaut pas de chiffre qu'un chiffre qui ne mesure rien.
     *
     * @param list<string>         $premisses
     * @param array<string, mixed> $contexte
     */
    public function verifierPremisse(array $premisses, array $contexte): void
    {
        foreach ($premisses as $exigence) {
            $present = match ($exigence) {
                'dossier' => isset($contexte['dossier']) && $contexte['dossier'] instanceof Dossier,
                'temoin_actif' => isset($contexte['temoin'])
                    && $contexte['temoin'] instanceof Dossier
                    && 1 === (int) $this->cnx->fetchOne(
                        "SELECT count(*) FROM remboursement.dossier
                          WHERE id = ? AND statut NOT IN ('refuse', 'doublon')",
                        [(int) $contexte['temoin']->getId()]),
                'meme_cle_doublon' => isset($contexte['dossier'], $contexte['temoin'])
                    && $contexte['dossier'] instanceof Dossier
                    && $contexte['temoin'] instanceof Dossier
                    && null !== $contexte['dossier']->getCleDoublon()
                    && $contexte['dossier']->getCleDoublon() === $contexte['temoin']->getCleDoublon(),
                'meme_empreinte_iban' => isset($contexte['dossier'], $contexte['temoin'])
                    && $contexte['dossier'] instanceof Dossier
                    && $contexte['temoin'] instanceof Dossier
                    && null !== $contexte['dossier']->getIbanHash()
                    && $contexte['dossier']->getIbanHash() === $contexte['temoin']->getIbanHash(),
                'contrat_buyback' => isset($contexte['contrat']) && $contexte['contrat'] instanceof BuyBackVehicule,
                'ecart_recalculable' => isset($contexte['contrat'], $contexte['montant'])
                    && $contexte['contrat'] instanceof BuyBackVehicule
                    && \is_numeric($contexte['montant']),
                // DEUX PREMISSES DISTINCTES, et la distinction est tout le sujet.
                // Le module verifie la STRUCTURE de l'IBAN (deux lettres, deux
                // chiffres, onze a trente alphanumeriques) et rien de plus. Un
                // IBAN hors structure est donc refuse ; un IBAN de structure
                // parfaite mais de cle MOD 97 fausse passe. Confondre les deux
                // ferait attendre un blocage qui n'existe pas.
                'iban_saisi_hors_structure' => isset($contexte['iban_saisi'])
                    && \is_string($contexte['iban_saisi'])
                    && 1 !== preg_match(self::STRUCTURE_IBAN, $contexte['iban_saisi']),
                'iban_saisi_cle_fausse' => isset($contexte['iban_saisi'])
                    && \is_string($contexte['iban_saisi'])
                    && 1 === preg_match(self::STRUCTURE_IBAN, $contexte['iban_saisi'])
                    && !IbanFictif::valide($contexte['iban_saisi']),
                'rib_divergent' => isset($contexte['iban_document'], $contexte['dossier'])
                    && $contexte['dossier'] instanceof Dossier
                    && (string) $contexte['iban_document'] !== (string) $contexte['dossier']->getIbanClient(),
                'facture_divergente' => isset($contexte['montant_document'], $contexte['dossier'])
                    && $contexte['dossier'] instanceof Dossier
                    && abs((float) $contexte['montant_document'] - (float) $contexte['dossier']->getMontant()) >= 0.01,
                'piece_declaree_illisible' => true === ($contexte['document_invalide'] ?? false),
                'panne_declaree' => true === ($contexte['panne_extraction'] ?? false),
                'vehicule_declare_gage' => false === ($contexte['vehicule_libre'] ?? true),
                'mention_manuscrite_declaree' => true === ($contexte['ecriture_manuscrite'] ?? false),
                'estimation_retouchee_declaree' => true === ($contexte['modifications_detectees'] ?? false),
                'paiement_deja_produit' => isset($contexte['dossier'])
                    && $contexte['dossier'] instanceof Dossier
                    && 1 === (int) $this->cnx->fetchOne(
                        "SELECT count(*) FROM remboursement.dossier_piece
                          WHERE dossier_id = ? AND type = 'fichier_sepa'",
                        [(int) $contexte['dossier']->getId()]),
                'valeurs_retenues_incompletes' => isset($contexte['valeurs_manquantes'])
                    && \is_array($contexte['valeurs_manquantes'])
                    && [] !== $contexte['valeurs_manquantes'],
                default => throw new RuntimeException('Premisse inconnue : '.$exigence),
            };

            if (!$present) {
                throw new RuntimeException(sprintf('PREMISSE ABSENTE : « %s ». Une verite ne peut pas exister sans sa cause : le cas est refuse plutot que mesure a vide.', $exigence));
            }
        }
    }
}
