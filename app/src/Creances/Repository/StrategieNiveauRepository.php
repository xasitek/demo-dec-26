<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\StrategieNiveau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StrategieNiveau>
 */
final class StrategieNiveauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StrategieNiveau::class);
    }

    public function save(StrategieNiveau $niveau): void
    {
        $em = $this->getEntityManager();
        $em->persist($niveau);
        $em->flush();
    }

    public function remove(StrategieNiveau $niveau): void
    {
        $em = $this->getEntityManager();
        $em->remove($niveau);
        $em->flush();
    }
}
