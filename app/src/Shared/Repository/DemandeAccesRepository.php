<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\DemandeAcces;
use App\Shared\Entity\StatutDemande;
use App\Shared\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeAcces>
 */
class DemandeAccesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeAcces::class);
    }

    /**
     * @return list<DemandeAcces>
     */
    public function findEnAttente(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.statut = :statut')
            ->setParameter('statut', StatutDemande::EnAttente)
            ->leftJoin('d.demandeur', 'u')->addSelect('u')
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countEnAttente(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.statut = :statut')
            ->setParameter('statut', StatutDemande::EnAttente)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findEnAttenteForUser(User $user): ?DemandeAcces
    {
        return $this->findOneBy([
            'demandeur' => $user,
            'statut' => StatutDemande::EnAttente,
        ]);
    }

    public function save(DemandeAcces $demande, bool $flush = true): void
    {
        $this->getEntityManager()->persist($demande);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
