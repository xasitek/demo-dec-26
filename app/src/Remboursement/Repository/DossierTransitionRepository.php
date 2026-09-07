<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierTransition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierTransition>
 */
class DossierTransitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierTransition::class);
    }

    /**
     * Historique d'un dossier, du plus recent au plus ancien.
     *
     * @return list<DossierTransition>
     */
    public function pourDossier(Dossier $dossier): array
    {
        /** @var list<DossierTransition> $r */
        $r = $this->createQueryBuilder('t')
            ->andWhere('t.dossier = :d')->setParameter('d', $dossier)
            ->orderBy('t.le', 'DESC')
            ->getQuery()->getResult();

        return $r;
    }
}
