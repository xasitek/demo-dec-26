<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\CampagneExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampagneExecution>
 */
final class CampagneExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampagneExecution::class);
    }

    public function save(CampagneExecution $e): void
    {
        $em = $this->getEntityManager();
        $em->persist($e);
        $em->flush();
    }

    /**
     * @return list<CampagneExecution>
     */
    public function findRecentes(int $limit = 10): array
    {
        /** @var list<CampagneExecution> $rows */
        $rows = $this->createQueryBuilder('e')
            ->orderBy('e.lanceLe', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
