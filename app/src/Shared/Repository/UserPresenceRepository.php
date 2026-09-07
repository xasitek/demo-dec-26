<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\User;
use App\Shared\Entity\UserPresence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPresence>
 */
class UserPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPresence::class);
    }

    public function findOneByUser(User $user): ?UserPresence
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Toutes les presences, avec l'utilisateur charge.
     *
     * @return list<UserPresence>
     */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->getQuery()
            ->getResult();
    }

    public function save(UserPresence $presence, bool $flush = true): void
    {
        $this->getEntityManager()->persist($presence);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
