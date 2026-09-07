<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Enum\StatutSecretaire;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dossier>
 */
class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    public function save(Dossier $dossier, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($dossier);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Combien de dossiers en base partagent CETTE valeur pour un champ (saisie OU
     * controle IA) — signal de doublon / fraude affiche a cote du champ. Casse ignoree.
     * Les noms de colonnes proviennent des methodes publiques (litteraux), pas de saisie.
     */
    private function compterChamp(string $champSaisie, string $champControle, ?string $valeur): int
    {
        $v = mb_strtolower(trim((string) $valeur));
        if ('' === $v) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere(sprintf('LOWER(%s) = :v OR LOWER(%s) = :v', $champSaisie, $champControle))
            ->setParameter('v', $v)
            ->getQuery()->getSingleScalarResult();
    }

    public function compterNom(?string $valeur): int
    {
        return $this->compterChamp('d.nomClient', 'd.controleNom', $valeur);
    }

    public function compterIban(?string $valeur): int
    {
        return $this->compterChamp('d.ibanClient', 'd.controleIban', $valeur);
    }

    public function compterIcar(?string $valeur): int
    {
        return $this->compterChamp('d.codeIcar', 'd.controleIcar', $valeur);
    }

    public function compterImmat(?string $valeur): int
    {
        return $this->compterChamp('d.immatriculation', 'd.controleImmatriculation', $valeur);
    }

    /**
     * Dossiers dans un etat donne, plus recents d'abord (file de travail).
     *
     * @return list<Dossier>
     */
    public function parStatut(DossierStatut $statut, int $limite = 100): array
    {
        /** @var list<Dossier> $r */
        $r = $this->createQueryBuilder('d')
            ->andWhere('d.statut = :s')->setParameter('s', $statut)
            ->orderBy('d.creeLe', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Dossiers dont le virement SEPA est genere (statut GENERATION_EN_COURS), separes
     * selon qu'il a deja ete telecharge par le manager. File a traiter = plus ancien
     * d'abord (par validation directeur) ; deja traites = plus recemment recuperes d'abord.
     *
     * @return list<Dossier>
     */
    public function sepaAtraiter(bool $telecharge): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.statut = :s')->setParameter('s', DossierStatut::GENERATION_EN_COURS);

        if ($telecharge) {
            $qb->andWhere('d.sepaTelechargeLe IS NOT NULL')->orderBy('d.sepaTelechargeLe', 'DESC');
        } else {
            $qb->andWhere('d.sepaTelechargeLe IS NULL')->orderBy('d.valideDirecteurLe', 'ASC')->addOrderBy('d.id', 'ASC');
        }

        /** @var list<Dossier> $r */
        $r = $qb->getQuery()->getResult();

        return $r;
    }

    /**
     * Base de matching des doublons/anomalies : dossiers au statut secretaire
     * « Dossier validé » et au-dela — c.-a-d. les 3 derniers statuts (validé, en
     * cours de paiement, payé), soit 6 statuts techniques. On detecte ainsi un
     * doublon meme si le jumeau est deja payé ou pas encore genere. Sert au calcul
     * des doublons/anomalies et au panneau, PAS a l'affichage des files (qui restent
     * en GENERATION_EN_COURS). Le plus ancien d'abord.
     *
     * @return list<Dossier>
     */
    public function aControler(): array
    {
        /** @var list<Dossier> $r */
        $r = $this->createQueryBuilder('d')
            ->andWhere('d.statut IN (:s)')
            ->setParameter('s', [
                DossierStatut::VALIDE_DIRECTEUR, DossierStatut::CONFIRME,
                DossierStatut::GENERATION_EN_COURS, DossierStatut::ERREUR_GENERATION,
                DossierStatut::PAYE, DossierStatut::LETTRE,
            ])
            ->orderBy('d.valideDirecteurLe', 'ASC')->addOrderBy('d.id', 'ASC')
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Dossiers PAYES (paye + lettre) : le vivier d'appariement de l'onglet Lettrage.
     * Les plus recents d'abord — un paiement non lettre est presque toujours recent.
     *
     * NB perf : charge tous les dossiers payes. Le rapprochement compare 12 signaux par
     * dossier, ce n'est pas projetable en SQL sans y reecrire le bareme ; borne par
     * `$limite` pour garder la page bornee en memoire quand l'historique grossira.
     *
     * @return list<Dossier>
     */
    public function payes(int $limite = 2000): array
    {
        /** @var list<Dossier> $r */
        $r = $this->createQueryBuilder('d')
            ->andWhere('d.statut IN (:payes)')
            ->setParameter('payes', [DossierStatut::PAYE, DossierStatut::LETTRE])
            ->orderBy('d.payeLe', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(max(1, $limite))
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Base de matching des doublons/anomalies cote COMPTABLE (« A verifier ») : TOUS les
     * dossiers sauf ceux ecartes (statut secretaire « Refusé » = refuse / doublon / fraude).
     * Permet a la comptable de reperer, au moment de verifier, qu'un dossier est un doublon
     * d'un autre encore actif (n'importe ou dans le cycle) et de decider de refuser ou non.
     * NB perf : charge l'ensemble des dossiers actifs — a projeter (id + cles) si le volume
     * grossit beaucoup.
     *
     * @return list<Dossier>
     */
    public function nonRefuses(): array
    {
        /** @var list<Dossier> $r */
        $r = $this->createQueryBuilder('d')
            ->andWhere('d.statut NOT IN (:exclus)')
            ->setParameter('exclus', [
                DossierStatut::REFUSE, DossierStatut::DOUBLON, DossierStatut::FRAUDE,
            ])
            ->orderBy('d.id', 'ASC')
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Base commune du journal des paiements : dossiers dont un lot SEPA a ete telecharge
     * (en attente OU deja payes), filtres etablissement + recherche. Sans ordre.
     */
    private function qbJournal(?string $etablissement, ?string $recherche): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.sepaTelechargeLe IS NOT NULL')
            ->andWhere('d.statut IN (:s)')
            ->setParameter('s', [
                DossierStatut::GENERATION_EN_COURS, DossierStatut::PAYE, DossierStatut::LETTRE,
            ]);

        if (null !== $etablissement && '' !== $etablissement) {
            $qb->andWhere('d.etablissementCode = :etab')->setParameter('etab', $etablissement);
        }
        if (null !== $recherche && '' !== $recherche) {
            $qb->andWhere('LOWER(d.reference) LIKE :q OR LOWER(d.nomClient) LIKE :q OR LOWER(d.immatriculation) LIKE :q OR LOWER(d.codeIcar) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($recherche)).'%');
        }

        return $qb;
    }

    /**
     * Jours (Y-m-d) DISTINCTS ayant un lot telecharge, du plus recent au plus ancien.
     * Sert a paginer le journal PAR JOUR (scroll infini).
     *
     * @return list<string>
     */
    public function journalJours(?string $etablissement, ?string $recherche): array
    {
        /** @var list<array{jour: mixed}> $rows */
        $rows = $this->qbJournal($etablissement, $recherche)
            ->select('d.sepaTelechargeLe AS jour')
            ->orderBy('d.sepaTelechargeLe', 'DESC')
            ->getQuery()->getScalarResult();

        $jours = [];
        foreach ($rows as $row) {
            $jours[substr((string) $row['jour'], 0, 10)] = true; // dedup, ordre conserve (recent d'abord)
        }

        return array_keys($jours);
    }

    /**
     * Dossiers des JOURS donnes (dates Y-m-d contigües d'une page), plus recent d'abord.
     * Le controleur regroupe par jour et trie par montant.
     *
     * @param list<string> $jours
     *
     * @return list<Dossier>
     */
    public function dossiersDesJours(array $jours, ?string $etablissement, ?string $recherche): array
    {
        if ([] === $jours) {
            return [];
        }
        // Les jours d'une page sont des dates de telechargement CONSECUTIVES : une simple
        // plage [min 00:00, max+1 00:00) les capture exactement (aucun autre jour entre eux).
        $debut = new DateTimeImmutable(min($jours).' 00:00:00');
        $fin = (new DateTimeImmutable(max($jours).' 00:00:00'))->modify('+1 day');

        /** @var list<Dossier> $r */
        $r = $this->qbJournal($etablissement, $recherche)
            ->andWhere('d.sepaTelechargeLe >= :debut AND d.sepaTelechargeLe < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('d.sepaTelechargeLe', 'DESC')->addOrderBy('d.id', 'DESC')
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Dossiers ENCORE en attente de paiement (GENERATION_EN_COURS) telecharges un jour
     * donne. Cible de la confirmation « Payé » par le directeur pour ce lot.
     *
     * @return list<Dossier>
     */
    public function enAttentePaiementDuJour(DateTimeImmutable $jour): array
    {
        $debut = $jour->setTime(0, 0);

        /** @var list<Dossier> $r */
        $r = $this->createQueryBuilder('d')
            ->andWhere('d.statut = :s')->setParameter('s', DossierStatut::GENERATION_EN_COURS)
            ->andWhere('d.sepaTelechargeLe >= :debut AND d.sepaTelechargeLe < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('d.id', 'ASC')
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Dossiers dont le SEPA a deja ete telecharge, filtres (recherche + etablissement),
     * plus recemment recuperes d'abord. Scroll infini.
     *
     * @return list<Dossier>
     */
    public function sepaTelechargesFiltre(?string $recherche, ?string $etablissement, int $page = 1): array
    {
        /** @var list<Dossier> $r */
        $r = $this->qbSepaTelecharges($recherche, $etablissement)
            ->orderBy('d.sepaTelechargeLe', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * self::PAR_PAGE))
            ->setMaxResults(self::PAR_PAGE)
            ->getQuery()->getResult();

        return $r;
    }

    public function compterSepaTelecharges(?string $recherche, ?string $etablissement): int
    {
        return (int) $this->qbSepaTelecharges($recherche, $etablissement)
            ->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
    }

    private function qbSepaTelecharges(?string $recherche, ?string $etablissement): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.statut = :s')->setParameter('s', DossierStatut::GENERATION_EN_COURS)
            ->andWhere('d.sepaTelechargeLe IS NOT NULL');

        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('LOWER(d.reference) LIKE :q OR LOWER(d.nomClient) LIKE :q OR LOWER(d.immatriculation) LIKE :q OR LOWER(d.codeIcar) LIKE :q OR LOWER(d.etablissementCode) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($recherche)).'%');
        }
        if (null !== $etablissement && '' !== trim($etablissement)) {
            $qb->andWhere('d.etablissementCode = :etab')->setParameter('etab', trim($etablissement));
        }

        return $qb;
    }

    /**
     * Dossiers dans l'un des etats donnes, plus recents d'abord. Sert la section
     * "A verifier" (regroupe le statut secretaire "Verification comptable").
     *
     * @param list<DossierStatut> $statuts
     *
     * @return list<Dossier>
     */
    public function parStatuts(array $statuts, int $limite = 100): array
    {
        if ([] === $statuts) {
            return [];
        }

        // File "A verifier" : du PLUS ANCIEN au plus recent SELON LA DATE DE DEPOT (colonne affichee).
        /** @var list<Dossier> $r */
        $r = $this->createQueryBuilder('d')
            ->addSelect('COALESCE(d.deposeLe, d.creeLe) AS HIDDEN tri')
            ->andWhere('d.statut IN (:s)')->setParameter('s', $statuts)
            ->orderBy('tri', 'ASC')->addOrderBy('d.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();

        return $r;
    }

    /** Taille d'une tranche de scroll infini (« Mes dossiers » + « Tous les dossiers »). */
    public const PAR_PAGE = 25;

    /**
     * Une page de "Tous les dossiers" (vue comptable), plus recents d'abord, filtres
     * (recherche ref/client/immat/ICAR/etablissement, motif, statut). Scroll infini.
     *
     * @return list<Dossier>
     */
    public function tousFiltre(?string $recherche, ?DossierMotif $motif, ?StatutSecretaire $statut, int $page = 1): array
    {
        // Du PLUS RECENT au plus ancien selon la date de depot (colonne affichee).
        /** @var list<Dossier> $r */
        $r = $this->qbTousFiltre($recherche, $motif, $statut)
            ->addSelect('COALESCE(d.deposeLe, d.creeLe) AS HIDDEN tri')
            ->orderBy('tri', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * self::PAR_PAGE))
            ->setMaxResults(self::PAR_PAGE)
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Nombre de dossiers dans l'un des etats donnes (badge de l'onglet "A verifier").
     *
     * @param list<DossierStatut> $statuts
     */
    public function compterParStatuts(array $statuts): int
    {
        if ([] === $statuts) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.statut IN (:s)')->setParameter('s', $statuts)
            ->getQuery()->getSingleScalarResult();
    }

    /** Nombre total de dossiers correspondant aux filtres (pagination "Tous les dossiers"). */
    public function compterTousFiltre(?string $recherche, ?DossierMotif $motif, ?StatutSecretaire $statut): int
    {
        return (int) $this->qbTousFiltre($recherche, $motif, $statut)
            ->select('COUNT(d.id)')
            ->getQuery()->getSingleScalarResult();
    }

    private function qbTousFiltre(?string $recherche, ?DossierMotif $motif, ?StatutSecretaire $statut): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            // Les dossiers "en verification comptable" ne figurent QUE dans "A verifier".
            ->andWhere('d.statut NOT IN (:horsVerif)')
            ->setParameter('horsVerif', array_map(static fn (DossierStatut $s): string => $s->value, StatutSecretaire::VERIFICATION->statuts()));

        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('LOWER(d.reference) LIKE :q OR LOWER(d.nomClient) LIKE :q OR LOWER(d.immatriculation) LIKE :q OR LOWER(d.codeIcar) LIKE :q OR LOWER(d.etablissementCode) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($recherche)).'%');
        }
        if (null !== $motif) {
            $qb->andWhere('d.motif = :m')->setParameter('m', $motif);
        }
        if (null !== $statut) {
            $qb->andWhere('d.statut IN (:statuts)')
                ->setParameter('statuts', array_map(static fn (DossierStatut $s): string => $s->value, $statut->statuts()));
        }

        return $qb;
    }

    /**
     * Une page de dossiers d'un createur (suivi "mes dossiers" cote secretaire), du
     * PLUS ANCIEN au plus recent (scroll
     * infini), filtres (recherche client/reference, motif, statut). La secretaire
     * ne voit QUE ses propres depots (creePar).
     *
     * @return list<Dossier>
     */
    public function parCreateurFiltre(?string $par, ?string $recherche, ?DossierMotif $motif, ?StatutSecretaire $statut, int $page = 1): array
    {
        if (null === $par || '' === $par) {
            return [];
        }
        // "Correction requise" remonte en TETE (ses actions a faire), puis recent -> ancien.
        /** @var list<Dossier> $r */
        $r = $this->qbCreateurFiltre($par, $recherche, $motif, $statut)
            ->addSelect('CASE WHEN d.statut = :prioCorr THEN 0 ELSE 1 END AS HIDDEN prio')
            ->addSelect('COALESCE(d.deposeLe, d.creeLe) AS HIDDEN tri')
            ->setParameter('prioCorr', DossierStatut::COMPLEMENT_REQUIS->value)
            ->orderBy('prio', 'ASC')
            ->addOrderBy('tri', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * self::PAR_PAGE))
            ->setMaxResults(self::PAR_PAGE)
            ->getQuery()->getResult();

        return $r;
    }

    /** Nombre total de dossiers du createur correspondant aux filtres (pagination). */
    public function compterParCreateurFiltre(?string $par, ?string $recherche, ?DossierMotif $motif, ?StatutSecretaire $statut): int
    {
        if (null === $par || '' === $par) {
            return 0;
        }

        return (int) $this->qbCreateurFiltre($par, $recherche, $motif, $statut)
            ->select('COUNT(d.id)')
            ->getQuery()->getSingleScalarResult();
    }

    /** Nombre de dossiers d'un createur en « Correction requise » (badge secretaire). */
    public function compterCorrectionRequise(?string $par): int
    {
        if (null === $par || '' === $par) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.creePar = :p')->setParameter('p', $par)
            ->andWhere('d.statut = :s')->setParameter('s', DossierStatut::COMPLEMENT_REQUIS)
            ->getQuery()->getSingleScalarResult();
    }

    private function qbCreateurFiltre(string $par, ?string $recherche, ?DossierMotif $motif, ?StatutSecretaire $statut): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.creePar = :p')->setParameter('p', $par);

        if (null !== $recherche && '' !== trim($recherche)) {
            // Cherche sur la saisie ET le nom retenu (valide) : la secretaire retrouve son
            // dossier par ce qu'elle a tape comme par le nom affiche.
            $qb->andWhere('LOWER(d.nomClient) LIKE :q OR LOWER(d.valideNom) LIKE :q OR LOWER(d.reference) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($recherche)).'%');
        }
        if (null !== $motif) {
            $qb->andWhere('d.motif = :m')->setParameter('m', $motif);
        }
        if (null !== $statut) {
            $qb->andWhere('d.statut IN (:statuts)')
                ->setParameter('statuts', array_map(static fn (DossierStatut $s): string => $s->value, $statut->statuts()));
        }

        return $qb;
    }

    /**
     * Anti-doublon (aligne sur le comportement N8N, cf. arbitrage PO 2026-08-11) :
     * existe-t-il DEJA un dossier NON clos du meme motif portant la meme cle ?
     * Cle = immatriculation (rachat) / code ICAR seul (trop-percu), en valeurs
     * CONTROLEES. Les etats refuses/doublon ne comptent pas comme des blocages.
     */
    public function doublonExistant(DossierMotif $motif, string $cleDoublon, ?int $exclureId = null): bool
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.motif = :m')->setParameter('m', $motif)
            ->andWhere('d.cleDoublon = :c')->setParameter('c', $cleDoublon)
            ->andWhere('d.statut NOT IN (:exclus)')
            ->setParameter('exclus', [DossierStatut::REFUSE, DossierStatut::DOUBLON]);

        if (null !== $exclureId) {
            $qb->andWhere('d.id != :id')->setParameter('id', $exclureId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Jumeaux ACTIFS d'un dossier (poste comptable, anti double-paiement) : autres
     * dossiers NON clos, meme motif, meme cle anti-doublon (immatriculation pour un
     * rachat, code ICAR SEUL pour un trop-percu). Renvoie les entites pour les afficher
     * (reference, statut, montant, etablissement) dans le bandeau de la fiche.
     *
     * @return list<Dossier>
     */
    public function doublonsActifs(DossierMotif $motif, ?string $cleDoublon, ?int $exclureId = null): array
    {
        if (null === $cleDoublon || '' === $cleDoublon) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.motif = :m')->setParameter('m', $motif)
            ->andWhere('d.cleDoublon = :c')->setParameter('c', $cleDoublon)
            ->andWhere('d.statut NOT IN (:exclus)')
            ->setParameter('exclus', [DossierStatut::REFUSE, DossierStatut::DOUBLON])
            ->orderBy('d.creeLe', 'DESC')
            ->setMaxResults(20);

        if (null !== $exclureId) {
            $qb->andWhere('d.id != :id')->setParameter('id', $exclureId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Recapitulatif chiffre pour l'encadrement : montant PAYE (statuts payé + lettré) par
     * motif, nombre de dossiers REFUSES (refusé + doublon + fraude) et nombre TOTAL de
     * dossiers. Montant retenu = valide ?: controle ?: saisie (COALESCE), coherent avec
     * l'affichage des ecrans.
     *
     * @return array{payeParMotif: array{rachat_sec: array{total: float, n: int}, trop_percu: array{total: float, n: int}}, nbRefuses: int, total: int}
     */
    public function recap(): array
    {
        $payes = StatutSecretaire::PAYE->statuts();

        return [
            'payeParMotif' => [
                'rachat_sec' => $this->sommeMotifStatuts(DossierMotif::RACHAT_SEC, $payes),
                'trop_percu' => $this->sommeMotifStatuts(DossierMotif::TROP_PERCU, $payes),
            ],
            'nbRefuses' => $this->compterParStatuts(StatutSecretaire::REFUSE->statuts()),
            'total' => $this->count([]),
        ];
    }

    /**
     * Somme des montants (valide ?: controle ?: saisie) et nombre de dossiers pour un
     * motif et un jeu de statuts donnes.
     *
     * @param list<DossierStatut> $statuts
     *
     * @return array{total: float, n: int}
     */
    private function sommeMotifStatuts(DossierMotif $motif, array $statuts): array
    {
        /** @var array{total: mixed, n: mixed} $row */
        $row = $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(COALESCE(d.valideMontant, d.controleMontant, d.montant)), 0) AS total')
            ->addSelect('COUNT(d.id) AS n')
            ->andWhere('d.motif = :m')->setParameter('m', $motif)
            ->andWhere('d.statut IN (:s)')->setParameter('s', $statuts)
            ->getQuery()
            ->getSingleResult();

        return ['total' => (float) $row['total'], 'n' => (int) $row['n']];
    }

    /**
     * Dossiers ACTIFS portant le MEME compte bancaire (meme empreinte IBAN) qu'un dossier
     * donne, tous motifs confondus. Detecte un rapprochement "meme beneficiaire" que la cle
     * immat/ICAR ne voit pas (ex. deux trop-percus distincts payes sur le meme IBAN). La
     * comparaison porte sur l'index aveugle (iban_hash), jamais sur l'IBAN en clair.
     *
     * @return list<Dossier>
     */
    public function memeIbanActif(?string $ibanHash, ?int $exclureId = null): array
    {
        if (null === $ibanHash || '' === $ibanHash) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.ibanHash = :h')->setParameter('h', $ibanHash)
            ->andWhere('d.statut NOT IN (:exclus)')
            ->setParameter('exclus', [DossierStatut::REFUSE, DossierStatut::DOUBLON])
            ->orderBy('d.creeLe', 'DESC')
            ->setMaxResults(20);

        if (null !== $exclureId) {
            $qb->andWhere('d.id != :id')->setParameter('id', $exclureId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Combien de dossiers en base partagent CE compte bancaire, tous statuts confondus —
     * compteur affiche a cote de l'IBAN sur l'ecran de verification. Le comptage porte
     * sur l'index aveugle (iban_hash, indexe) : l'IBAN etant chiffre en base, un
     * LOWER(colonne) = valeur ne matcherait jamais.
     */
    public function compterIbanHash(?string $ibanHash): int
    {
        if (null === $ibanHash || '' === $ibanHash) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.ibanHash = :h')->setParameter('h', $ibanHash)
            ->getQuery()->getSingleScalarResult();
    }
}
