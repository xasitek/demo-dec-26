<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\ExtractionPiece;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtractionPiece>
 */
class ExtractionPieceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtractionPiece::class);
    }

    /**
     * Extractions d'un dossier (les plus recentes d'abord).
     *
     * @return list<ExtractionPiece>
     */
    public function pourDossier(Dossier $dossier): array
    {
        /** @var list<ExtractionPiece> $r */
        $r = $this->createQueryBuilder('e')
            ->andWhere('e.dossier = :d')->setParameter('d', $dossier)
            ->orderBy('e.id', 'DESC')
            ->getQuery()->getResult();

        return $r;
    }

    /** Supprime les extractions existantes d'un dossier (avant une re-analyse). */
    public function purgerDossier(Dossier $dossier): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.dossier = :d')->setParameter('d', $dossier)
            ->getQuery()->execute();
    }
}
