<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Suggestion;
use App\Shared\Entity\User;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Suggestion>
 */
class SuggestionRepository extends ServiceEntityRepository
{
    /** Taille de page du mur d'idees (scroll infini). */
    public const PAR_PAGE = 12;

    /** Taille de page de l'ecran d'administration. */
    public const PAR_PAGE_ADMIN = 25;

    /** Tris proposes sur le mur. */
    public const TRI_POPULAIRES = 'populaires';

    public const TRI_RECENTES = 'recentes';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Suggestion::class);
    }

    public function save(Suggestion $suggestion, bool $flush = true): void
    {
        $this->getEntityManager()->persist($suggestion);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Garde-fou anti-abus : combien de remontees cet auteur a-t-il envoyees depuis
     * `$depuis` ? Couvert par idx_suggestion_auteur.
     */
    public function compterDepuis(User $auteur, DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.auteur = :a')
            ->setParameter('a', $auteur)
            ->andWhere('s.createdAt >= :d')
            ->setParameter('d', $depuis, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les remontees de l'utilisateur, tous types confondus (onglet « Mes idees »).
     *
     * @return list<Suggestion>
     */
    public function mesSuggestions(User $auteur, int $limit = 10): array
    {
        /** @var list<Suggestion> $resultat */
        $resultat = $this->createQueryBuilder('s')
            ->where('s.auteur = :a')
            ->setParameter('a', $auteur)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $resultat;
    }

    /**
     * Une page du mur public : idees uniquement (cf. TypeSuggestion::surLeMur()).
     * L'auteur est joint en une passe (addSelect) : pas de N+1 sur l'avatar.
     *
     * @return list<Suggestion>
     */
    public function pageDuMur(?StatutSuggestion $statut, string $tri, int $page, int $perPage = self::PAR_PAGE): array
    {
        $qb = $this->murQueryBuilder($statut)
            ->leftJoin('s.auteur', 'a')
            ->addSelect('a')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (self::TRI_POPULAIRES === $tri) {
            $qb->orderBy('s.nbVotes', 'DESC')->addOrderBy('s.createdAt', 'DESC');
        } else {
            $qb->orderBy('s.createdAt', 'DESC');
        }

        /** @var list<Suggestion> $resultat */
        $resultat = $qb->getQuery()->getResult();

        return $resultat;
    }

    public function compterMur(?StatutSuggestion $statut): int
    {
        return (int) $this->murQueryBuilder($statut)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Page de l'ecran d'administration : tous les types, tous les statuts.
     *
     * @return list<Suggestion>
     */
    public function pageAdmin(?StatutSuggestion $statut, ?TypeSuggestion $type, int $page, int $perPage = self::PAR_PAGE_ADMIN): array
    {
        /** @var list<Suggestion> $resultat */
        $resultat = $this->adminQueryBuilder($statut, $type)
            ->leftJoin('s.auteur', 'a')
            ->addSelect('a')
            // Les non traitees d'abord, puis les plus soutenues, puis les recentes.
            ->orderBy('s.traiteAt', 'ASC')
            ->addOrderBy('s.nbVotes', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $resultat;
    }

    public function compterAdmin(?StatutSuggestion $statut, ?TypeSuggestion $type): int
    {
        return (int) $this->adminQueryBuilder($statut, $type)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Badge de la barre laterale (administrateurs) : remontees jamais regardees. */
    public function compterNouvelles(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.statut = :s')
            ->setParameter('s', StatutSuggestion::NOUVELLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ajuste le compteur denormalise en une instruction atomique : deux votes
     * simultanes ne peuvent pas s'ecraser (ce que ferait un read-modify-write ORM).
     * GREATEST protege le compteur d'une derive negative.
     *
     * @return int le nombre de votes apres ajustement
     */
    public function ajusterVotes(int $suggestionId, int $delta): int
    {
        $sql = 'UPDATE shared.suggestion SET nb_votes = GREATEST(0, nb_votes + :delta) WHERE id = :id RETURNING nb_votes';

        $votes = $this->getEntityManager()->getConnection()
            ->fetchOne($sql, ['delta' => $delta, 'id' => $suggestionId]);

        return false === $votes ? 0 : (int) $votes;
    }

    private function murQueryBuilder(?StatutSuggestion $statut): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.type = :type')
            ->setParameter('type', TypeSuggestion::IDEE);

        if (null !== $statut) {
            $qb->andWhere('s.statut = :statut')->setParameter('statut', $statut);
        }

        return $qb;
    }

    private function adminQueryBuilder(?StatutSuggestion $statut, ?TypeSuggestion $type): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s');

        if (null !== $statut) {
            $qb->andWhere('s.statut = :statut')->setParameter('statut', $statut);
        }

        if (null !== $type) {
            $qb->andWhere('s.type = :type')->setParameter('type', $type);
        }

        return $qb;
    }
}
