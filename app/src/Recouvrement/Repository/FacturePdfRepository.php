<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\FacturePdf;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FacturePdf>
 */
final class FacturePdfRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FacturePdf::class);
    }

    /**
     * PDF televerse pour une ligne Progiciel (ecriture_id), ou null si aucun.
     */
    public function findParEcriture(string $ecritureId): ?FacturePdf
    {
        return $this->findOneBy(['ecritureId' => $ecritureId]);
    }

    /**
     * ecriture_id qui ont deja un PDF televerse (pour exclure de la liste "sans PDF").
     *
     * @param list<string> $ecritureIds
     *
     * @return list<string>
     */
    public function ecrituresAvecPdf(array $ecritureIds): array
    {
        if ([] === $ecritureIds) {
            return [];
        }

        /** @var list<array{ecritureId: string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.ecritureId')
            ->where('f.ecritureId IN (:ids)')
            ->setParameter('ids', $ecritureIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => $r['ecritureId'], $rows);
    }
}
