<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Notification;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    /** Taille de page de la cloche (rendu initial et "Voir plus"). */
    public const PAR_PAGE = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function compterToutes(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.destinataire = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Notification>
     */
    public function pageDe(User $user, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.destinataire = :u')
            ->setParameter('u', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function compterNonLues(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.destinataire = :u')
            ->setParameter('u', $user)
            ->andWhere('n.luAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Notification>
     */
    public function recentes(User $user, int $limit = 15): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.destinataire = :u')
            ->setParameter('u', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Marque toutes les notifications non lues comme lues. Renvoie le nombre traite.
     */
    public function marquerToutesLues(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.luAt', ':now')
            ->setParameter('now', new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->where('n.destinataire = :u')
            ->setParameter('u', $user)
            ->andWhere('n.luAt IS NULL')
            ->getQuery()
            ->execute();
    }

    public function save(Notification $notification, bool $flush = true): void
    {
        $this->getEntityManager()->persist($notification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
