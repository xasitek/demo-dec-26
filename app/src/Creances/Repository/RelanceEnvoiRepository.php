<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\RelanceEnvoi;
use App\Creances\Enum\RelanceStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelanceEnvoi>
 */
final class RelanceEnvoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelanceEnvoi::class);
    }

    public function save(RelanceEnvoi $envoi): void
    {
        $em = $this->getEntityManager();
        $em->persist($envoi);
        $em->flush();
    }

    /**
     * @return list<RelanceEnvoi>
     */
    public function findByCompte(string $compteCode): array
    {
        /** @var list<RelanceEnvoi> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('r.creeLe', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<RelanceEnvoi>
     */
    public function findAEnvoyer(): array
    {
        /** @var list<RelanceEnvoi> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', RelanceStatut::AEnvoyer)
            ->orderBy('r.creeLe', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
