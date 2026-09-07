<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\ActivityAction;
use App\Shared\Entity\ActivityLog;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * Page de logs (du plus recent au plus ancien), filtree par utilisateur si fourni.
     *
     * @return list<ActivityLog>
     */
    public function findPage(int $page, int $perPage, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.occurredAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $user) {
            $qb->andWhere('a.user = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(?User $user = null): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');

        if (null !== $user) {
            $qb->andWhere('a.user = :user')->setParameter('user', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Nombre de connexions (LoginSuccess) d'un utilisateur depuis une date.
     */
    public function compterConnexionsDepuis(User $user, DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->andWhere('a.action = :login')
            ->setParameter('login', ActivityAction::Login)
            ->andWhere('a.occurredAt >= :depuis')
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(ActivityLog $log, bool $flush = true): void
    {
        $this->getEntityManager()->persist($log);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Supprime les connexions anterieures a la date (purge RGPD). Renvoie le
     * nombre de lignes supprimees.
     */
    public function purgerAvant(DateTimeImmutable $avant): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.occurredAt < :avant')
            ->setParameter('avant', $avant)
            ->getQuery()
            ->execute();
    }
}
