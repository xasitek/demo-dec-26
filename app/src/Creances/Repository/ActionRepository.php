<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Action;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Action>
 */
final class ActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Action::class);
    }

    public function save(Action $action): void
    {
        $em = $this->getEntityManager();
        $em->persist($action);
        $em->flush();
    }

    public function remove(Action $action): void
    {
        $em = $this->getEntityManager();
        $em->remove($action);
        $em->flush();
    }

    /**
     * @return list<Action>
     */
    public function findByCompte(string $compteCode): array
    {
        /** @var list<Action> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('a.echeance', 'DESC')
            ->addOrderBy('a.creeLe', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Actions a faire (non realisees) pour un utilisateur, triees par
     * echeance croissante (les plus urgentes en premier).
     *
     * @return list<Action>
     */
    public function findAFaire(User $destinataire): array
    {
        /** @var list<Action> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.destinataire = :user')
            ->andWhere('a.realisee = false')
            ->setParameter('user', $destinataire)
            ->orderBy('a.echeance', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Compte les actions en retard (non realisees + echeance passee) pour
     * l'utilisateur courant.
     */
    public function countEnRetard(User $destinataire): int
    {
        $today = new DateTimeImmutable('today');
        /** @var int|string $count */
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.destinataire = :user')
            ->andWhere('a.realisee = false')
            ->andWhere('a.echeance < :today')
            ->setParameter('user', $destinataire)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
