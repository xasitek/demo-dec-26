<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Strategie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Strategie>
 */
final class StrategieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Strategie::class);
    }

    public function save(Strategie $strategie): void
    {
        $em = $this->getEntityManager();
        $em->persist($strategie);
        $em->flush();
    }

    public function remove(Strategie $strategie): void
    {
        $em = $this->getEntityManager();
        $em->remove($strategie);
        $em->flush();
    }

    /**
     * @return list<Strategie>
     */
    public function findActives(): array
    {
        /** @var list<Strategie> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->orderBy('s.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Strategie>
     */
    public function findToutes(): array
    {
        /** @var list<Strategie> $rows */
        $rows = $this->createQueryBuilder('s')
            ->orderBy('s.active', 'DESC')
            ->addOrderBy('s.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
