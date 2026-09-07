<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\MessageSortant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Journal des messages sortants (recouvrement.message_sortant) : les réponses
 * envoyées par les comptables aux clients.
 *
 * @extends ServiceEntityRepository<MessageSortant>
 */
final class MessageSortantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageSortant::class);
    }

    public function save(MessageSortant $message, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($message);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Réponses envoyées à un compte, de la plus ancienne à la plus récente
     * (pour la timeline des échanges).
     *
     * @return list<MessageSortant>
     */
    public function findParCompte(string $compteCode, int $limit = 100): array
    {
        /** @var list<MessageSortant> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('m.envoyeLe', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
