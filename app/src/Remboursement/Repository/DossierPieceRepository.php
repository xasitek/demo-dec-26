<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierPiece>
 */
class DossierPieceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierPiece::class);
    }

    /**
     * @return list<DossierPiece>
     */
    public function pourDossier(Dossier $dossier): array
    {
        /** @var list<DossierPiece> $r */
        $r = $this->createQueryBuilder('p')
            ->andWhere('p.dossier = :d')->setParameter('d', $dossier)
            ->orderBy('p.uploadeLe', 'ASC')
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Pieces de PLUSIEURS dossiers en une seule requete (evite le N+1), regroupees par
     * id de dossier. Utilise par le journal des paiements (affichage des PDF).
     *
     * @param list<Dossier> $dossiers
     *
     * @return array<int, list<DossierPiece>>
     */
    public function pourDossiers(array $dossiers): array
    {
        if ([] === $dossiers) {
            return [];
        }

        /** @var list<DossierPiece> $pieces */
        $pieces = $this->createQueryBuilder('p')
            ->andWhere('p.dossier IN (:d)')->setParameter('d', $dossiers)
            ->orderBy('p.uploadeLe', 'ASC')
            ->getQuery()->getResult();

        $map = [];
        foreach ($pieces as $piece) {
            $map[(int) $piece->getDossier()->getId()][] = $piece;
        }

        return $map;
    }
}
