<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\ActiviteJour;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiviteJour>
 */
class ActiviteJourRepository extends ServiceEntityRepository
{
    /** Plafond d'un intervalle compte (ping = 20 s, marge a 30 s). */
    private const PLAFOND_INTERVALLE_S = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiviteJour::class);
    }

    /**
     * Accumule le temps actif du jour pour l'utilisateur (upsert atomique : zero
     * race, pas de lecture-modif-ecriture). Sur un ping actif, ajoute l'intervalle
     * ecoule depuis le dernier ping, plafonne pour ne pas compter les trous
     * (veille, onglet ferme). Le jour est borne sur le fuseau Europe/Paris.
     */
    public function accumuler(int $userId, bool $active): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO shared.activite_jour (user_id, jour, secondes_actives, derniere_activite)
                VALUES (:uid, (NOW() AT TIME ZONE 'Europe/Paris')::date, 0, NOW())
                ON CONFLICT (user_id, jour) DO UPDATE SET
                    secondes_actives = shared.activite_jour.secondes_actives +
                        CASE WHEN :active
                             THEN LEAST(GREATEST(EXTRACT(EPOCH FROM (NOW() - shared.activite_jour.derniere_activite))::int, 0), :plafond)
                             ELSE 0 END,
                    derniere_activite = NOW()
                SQL,
            ['uid' => $userId, 'active' => $active, 'plafond' => self::PLAFOND_INTERVALLE_S],
            ['uid' => ParameterType::INTEGER, 'active' => ParameterType::BOOLEAN, 'plafond' => ParameterType::INTEGER],
        );
    }

    /**
     * Detail jour par jour pour un utilisateur (du plus recent au plus ancien).
     *
     * @return list<array{jour: DateTimeImmutable, secondes: int}>
     */
    public function detailParJour(User $user, DateTimeImmutable $depuis): array
    {
        /** @var list<array{jour: DateTimeImmutable, secondes: numeric-string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.jour AS jour', 'a.secondesActives AS secondes')
            ->where('a.user = :user')
            ->andWhere('a.jour >= :depuis')
            ->setParameter('user', $user)
            ->setParameter('depuis', $depuis, Types::DATE_IMMUTABLE)
            ->orderBy('a.jour', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'jour' => $r['jour'],
            'secondes' => (int) $r['secondes'],
        ], $rows);
    }

    /**
     * Supprime les jours d'activite anterieurs a la date (purge RGPD). Renvoie
     * le nombre de lignes supprimees.
     */
    public function purgerAvant(DateTimeImmutable $avant): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.jour < :avant')
            ->setParameter('avant', $avant, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
