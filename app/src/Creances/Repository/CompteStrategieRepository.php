<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\CompteStrategie;
use App\Creances\Enum\CompteStrategieEtat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompteStrategie>
 */
final class CompteStrategieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompteStrategie::class);
    }

    public function save(CompteStrategie $cs): void
    {
        $em = $this->getEntityManager();
        $em->persist($cs);
        $em->flush();
    }

    public function findByCompte(string $compteCode): ?CompteStrategie
    {
        return $this->findOneBy(['compteCode' => $compteCode]);
    }

    /**
     * @return list<CompteStrategie>
     */
    public function findARelancer(): array
    {
        /** @var list<CompteStrategie> $rows */
        $rows = $this->createQueryBuilder('cs')
            ->andWhere('cs.etat = :etat')
            ->setParameter('etat', CompteStrategieEtat::ARelancer)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
