<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Campagne;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Campagne>
 */
final class CampagneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campagne::class);
    }

    public function save(Campagne $c): void
    {
        $em = $this->getEntityManager();
        $em->persist($c);
        $em->flush();
    }

    public function remove(Campagne $c): void
    {
        $em = $this->getEntityManager();
        $em->remove($c);
        $em->flush();
    }

    /**
     * @return list<Campagne>
     */
    public function findToutes(): array
    {
        /** @var list<Campagne> $rows */
        $rows = $this->createQueryBuilder('c')
            ->orderBy('c.active', 'DESC')
            ->addOrderBy('c.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
