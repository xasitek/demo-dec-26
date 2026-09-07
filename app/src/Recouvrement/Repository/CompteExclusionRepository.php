<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\CompteExclusion;
use App\Recouvrement\Enum\CompteExclusionEtat;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Curation des comptes relancables (recouvrement.compte_exclusion).
 *
 * @extends ServiceEntityRepository<CompteExclusion>
 */
final class CompteExclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompteExclusion::class);
    }

    public function findOneByCompte(string $compteCode): ?CompteExclusion
    {
        return $this->findOneBy(['compteCode' => $compteCode]);
    }

    public function save(CompteExclusion $exclusion, bool $flush = true): void
    {
        $this->getEntityManager()->persist($exclusion);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Nombre de comptes ecartes (pour les recaps / le suivi).
     */
    public function compterEcartes(): int
    {
        return $this->compterParEtat(CompteExclusionEtat::ECARTE, null);
    }

    /**
     * Page de comptes d'un etat donne (curation), du plus recemment modifie au
     * plus ancien, avec recherche optionnelle sur le code compte.
     *
     * @return list<CompteExclusion>
     */
    public function pageParEtat(CompteExclusionEtat $etat, int $page, int $taille, ?string $recherche = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.etat = :etat')
            ->setParameter('etat', $etat)
            ->orderBy('c.modifieLe', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $taille))
            ->setMaxResults($taille);

        if (null !== $recherche && '' !== $recherche) {
            $qb->andWhere('LOWER(c.compteCode) LIKE :q')->setParameter('q', '%'.strtolower($recherche).'%');
        }

        /** @var list<CompteExclusion> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function compterParEtat(CompteExclusionEtat $etat, ?string $recherche = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.etat = :etat')
            ->setParameter('etat', $etat);

        if (null !== $recherche && '' !== $recherche) {
            $qb->andWhere('LOWER(c.compteCode) LIKE :q')->setParameter('q', '%'.strtolower($recherche).'%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Pose (ou met a jour) une decision MANUELLE sur un compte : etat + motif +
     * tracage qui/quand. Mutualise par les commandes CLI et la vue de curation.
     * Une decision manuelle prime sur le semis auto (origine = manuel).
     */
    public function decisionManuelle(string $compteCode, CompteExclusionEtat $etat, ?string $motif, string $par): CompteExclusion
    {
        $now = new DateTimeImmutable();
        $exclusion = $this->findOneByCompte($compteCode)
            ?? new CompteExclusion($compteCode, $etat, CompteExclusion::ORIGINE_MANUEL);

        $exclusion->setEtat($etat);
        $exclusion->setOrigine(CompteExclusion::ORIGINE_MANUEL);
        $exclusion->setMotif($motif);
        $exclusion->setDecidePar($par);
        $exclusion->setDecideLe($now);
        $exclusion->setModifieLe($now);

        $this->save($exclusion);

        return $exclusion;
    }
}
