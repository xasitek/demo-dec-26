<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\ScoreIa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScoreIa>
 */
final class ScoreIaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScoreIa::class);
    }

    public function save(ScoreIa $s): void
    {
        $em = $this->getEntityManager();
        $em->persist($s);
        $em->flush();
    }

    public function findByCompte(string $compteCode): ?ScoreIa
    {
        return $this->findOneBy(['compteCode' => $compteCode]);
    }
}
