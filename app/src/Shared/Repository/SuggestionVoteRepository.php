<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Suggestion;
use App\Shared\Entity\SuggestionVote;
use App\Shared\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SuggestionVote>
 */
class SuggestionVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuggestionVote::class);
    }

    public function save(SuggestionVote $vote, bool $flush = true): void
    {
        $this->getEntityManager()->persist($vote);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SuggestionVote $vote, bool $flush = true): void
    {
        $this->getEntityManager()->remove($vote);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function trouver(Suggestion $suggestion, User $votant): ?SuggestionVote
    {
        return $this->findOneBy(['suggestion' => $suggestion, 'votant' => $votant]);
    }

    /**
     * Idees deja soutenues par l'utilisateur, parmi celles affichees : une seule
     * requete pour toute la page (l'etat des boutons de vote).
     *
     * @param list<int> $suggestionIds
     *
     * @return list<int>
     */
    public function idsVotesPar(User $votant, array $suggestionIds): array
    {
        if ([] === $suggestionIds) {
            return [];
        }

        /** @var list<array{id: int|string}> $lignes */
        $lignes = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.suggestion) AS id')
            ->where('v.votant = :u')
            ->setParameter('u', $votant)
            ->andWhere('v.suggestion IN (:ids)')
            ->setParameter('ids', $suggestionIds)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $ligne): int => (int) $ligne['id'], $lignes);
    }
}
