<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Dossier;
use App\Creances\Enum\DossierStatut;
use App\Creances\Enum\DossierType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dossier>
 */
final class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    public function save(Dossier $dossier): void
    {
        $em = $this->getEntityManager();
        $em->persist($dossier);
        $em->flush();
    }

    public function remove(Dossier $dossier): void
    {
        $em = $this->getEntityManager();
        $em->remove($dossier);
        $em->flush();
    }

    /**
     * @return list<Dossier>
     */
    public function findByCompte(string $compteCode): array
    {
        /** @var list<Dossier> $rows */
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('d.dateDebut', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Dossier>
     */
    public function findByCompteEtType(string $compteCode, DossierType $type): array
    {
        /** @var list<Dossier> $rows */
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.compteCode = :code')
            ->andWhere('d.type = :type')
            ->setParameter('code', $compteCode)
            ->setParameter('type', $type)
            ->orderBy('d.dateDebut', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Dossier>
     */
    public function findOuverts(DossierType $type): array
    {
        /** @var list<Dossier> $rows */
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.type = :type')
            ->andWhere('d.statut = :statut')
            ->setParameter('type', $type)
            ->setParameter('statut', DossierStatut::Ouvert)
            ->orderBy('d.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
