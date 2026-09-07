<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\EmailReponse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailReponse>
 */
final class EmailReponseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailReponse::class);
    }

    public function save(EmailReponse $r): void
    {
        $em = $this->getEntityManager();
        $em->persist($r);
        $em->flush();
    }

    public function remove(EmailReponse $r): void
    {
        $em = $this->getEntityManager();
        $em->remove($r);
        $em->flush();
    }

    /**
     * @return list<EmailReponse>
     */
    public function findByCompte(string $compteCode): array
    {
        /** @var list<EmailReponse> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('r.recuLe', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<EmailReponse>
     */
    public function findNonTraites(int $limit = 50): array
    {
        /** @var list<EmailReponse> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.traite = false')
            ->orderBy('r.recuLe', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countNonTraites(): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.traite = false')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
