<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Note>
 */
final class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    public function save(Note $note): void
    {
        $em = $this->getEntityManager();
        $em->persist($note);
        $em->flush();
    }

    public function remove(Note $note): void
    {
        $em = $this->getEntityManager();
        $em->remove($note);
        $em->flush();
    }

    /**
     * @return list<Note>
     */
    public function findByCompte(string $compteCode): array
    {
        /** @var list<Note> $notes */
        $notes = $this->createQueryBuilder('n')
            ->andWhere('n.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('n.creeLe', 'DESC')
            ->getQuery()
            ->getResult();

        return $notes;
    }

    /**
     * @return list<Note>
     */
    public function findByEcriture(string $ecritureNumero): array
    {
        /** @var list<Note> $notes */
        $notes = $this->createQueryBuilder('n')
            ->andWhere('n.ecritureNumero = :num')
            ->setParameter('num', $ecritureNumero)
            ->orderBy('n.creeLe', 'DESC')
            ->getQuery()
            ->getResult();

        return $notes;
    }
}
