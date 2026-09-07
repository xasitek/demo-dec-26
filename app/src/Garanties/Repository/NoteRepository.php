<?php

declare(strict_types=1);

namespace App\Garanties\Repository;

use App\Garanties\Entity\Note;
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

    /**
     * @return list<Note>
     */
    public function findByDossier(int $dossierId): array
    {
        /** @var list<Note> $notes */
        $notes = $this->createQueryBuilder('n')
            ->andWhere('n.dossierId = :id')
            ->setParameter('id', $dossierId)
            ->orderBy('n.creeLe', 'DESC')
            ->getQuery()
            ->getResult();

        return $notes;
    }

    /**
     * Notes ancrees sur une ecriture Progiciel (aucune DG rapprochee). Le couple
     * (cle, oidech) identifie la ligne : la cle seule en designe plusieurs.
     *
     * @return list<Note>
     */
    public function findByEcriture(string $cleEcriture, ?string $oidech): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.cleEcriture = :cle')
            ->setParameter('cle', $cleEcriture)
            ->orderBy('n.creeLe', 'DESC');

        if (null === $oidech || '' === $oidech) {
            $qb->andWhere('n.oidech IS NULL');
        } else {
            $qb->andWhere('n.oidech = :oidech')->setParameter('oidech', $oidech);
        }

        /** @var list<Note> $notes */
        $notes = $qb->getQuery()->getResult();

        return $notes;
    }
}
