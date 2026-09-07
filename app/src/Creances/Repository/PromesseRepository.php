<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Promesse;
use App\Creances\Enum\PromesseStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Promesse>
 */
final class PromesseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Promesse::class);
    }

    public function save(Promesse $promesse): void
    {
        $em = $this->getEntityManager();
        $em->persist($promesse);
        $em->flush();
    }

    public function remove(Promesse $promesse): void
    {
        $em = $this->getEntityManager();
        $em->remove($promesse);
        $em->flush();
    }

    /**
     * @return list<Promesse>
     */
    public function findByCompte(string $compteCode): array
    {
        /** @var list<Promesse> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('p.datePromesse', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Promesse>
     */
    public function findByEcriture(string $ecritureNumero): array
    {
        /** @var list<Promesse> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.ecritureNumero = :num')
            ->setParameter('num', $ecritureNumero)
            ->orderBy('p.datePromesse', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Promesse active (en cours, la plus recente) pour une ecriture donnee.
     */
    public function findActiveByEcriture(string $ecritureNumero): ?Promesse
    {
        /** @var Promesse|null $row */
        $row = $this->createQueryBuilder('p')
            ->andWhere('p.ecritureNumero = :num')
            ->andWhere('p.statut = :statut')
            ->setParameter('num', $ecritureNumero)
            ->setParameter('statut', PromesseStatut::EnCours)
            ->orderBy('p.datePromesse', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
}
