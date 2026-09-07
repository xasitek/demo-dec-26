<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\EnvoiProgramme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnvoiProgramme>
 */
final class EnvoiProgrammeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvoiProgramme::class);
    }

    public function save(EnvoiProgramme $e): void
    {
        $em = $this->getEntityManager();
        $em->persist($e);
        $em->flush();
    }

    public function remove(EnvoiProgramme $e): void
    {
        $em = $this->getEntityManager();
        $em->remove($e);
        $em->flush();
    }

    /**
     * @return list<EnvoiProgramme>
     */
    public function findToutes(): array
    {
        /** @var list<EnvoiProgramme> $rows */
        $rows = $this->createQueryBuilder('e')
            ->orderBy('e.active', 'DESC')
            ->addOrderBy('e.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
