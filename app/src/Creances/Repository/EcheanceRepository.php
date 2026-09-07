<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Echeance;
use App\Creances\Enum\EcheanceStatut;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Echeance>
 */
final class EcheanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Echeance::class);
    }

    public function save(Echeance $echeance): void
    {
        $em = $this->getEntityManager();
        $em->persist($echeance);
        $em->flush();
    }

    public function remove(Echeance $echeance): void
    {
        $em = $this->getEntityManager();
        $em->remove($echeance);
        $em->flush();
    }

    /**
     * Toutes les echeances en retard, tous dossiers confondus. Utilise par le
     * job qui rafraichit les statuts (a_venir -> en_retard) et par le widget
     * "echeances a venir".
     *
     * @return list<Echeance>
     */
    public function findEnRetard(): array
    {
        $today = new DateTimeImmutable('today');
        /** @var list<Echeance> $rows */
        $rows = $this->createQueryBuilder('e')
            ->andWhere('e.statut IN (:statuts)')
            ->andWhere('e.datePrevue < :today')
            ->setParameter('statuts', [EcheanceStatut::AVenir, EcheanceStatut::Partiel])
            ->setParameter('today', $today)
            ->orderBy('e.datePrevue', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
