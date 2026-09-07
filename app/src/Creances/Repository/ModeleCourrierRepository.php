<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\ModeleCourrier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModeleCourrier>
 */
final class ModeleCourrierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeleCourrier::class);
    }

    public function save(ModeleCourrier $modele): void
    {
        $em = $this->getEntityManager();
        $em->persist($modele);
        $em->flush();
    }

    public function remove(ModeleCourrier $modele): void
    {
        $em = $this->getEntityManager();
        $em->remove($modele);
        $em->flush();
    }

    /**
     * @return list<ModeleCourrier>
     */
    public function findToutes(): array
    {
        /** @var list<ModeleCourrier> $rows */
        $rows = $this->createQueryBuilder('m')
            ->orderBy('m.actif', 'DESC')
            ->addOrderBy('m.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<ModeleCourrier>
     */
    public function findActifs(): array
    {
        /** @var list<ModeleCourrier> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.actif = true')
            ->orderBy('m.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
