<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\RegleRelance;
use App\Recouvrement\Regle\MoteurFiltre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Accès aux règles de relance + aperçu des volumes qu'une règle attrape dans
 * v_impayes (pour la vue Stratégies : "cette règle concerne N comptes / X €").
 *
 * @extends ServiceEntityRepository<RegleRelance>
 */
final class RegleRelanceRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MoteurFiltre $moteurFiltre,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct($registry, RegleRelance::class);
    }

    /**
     * Toutes les règles, dans l'ordre d'affichage / d'évaluation (priorité puis id).
     *
     * @return list<RegleRelance>
     */
    public function findToutesOrdonnees(): array
    {
        /** @var list<RegleRelance> $rows */
        $rows = $this->createQueryBuilder('r')
            ->orderBy('r.priorite', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Règles actives, dans l'ordre d'évaluation (la 1re qui matche un compte gagne).
     *
     * @return list<RegleRelance>
     */
    public function findActivesOrdonnees(): array
    {
        /** @var list<RegleRelance> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.actif = true')
            ->orderBy('r.priorite', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Aperçu des volumes attrapés par une règle (via ses filtres).
     *
     * @return array{comptes: int, factures: int, encours: string}
     */
    public function apercuVolumes(RegleRelance $regle): array
    {
        return $this->apercuPourFiltres($regle->getFiltres());
    }

    /**
     * Aperçu : combien de comptes / factures / encours des filtres attrapent parmi
     * les factures échues impayées (montant_solde > 0 et en retard). Utilisé aussi
     * en direct par le constructeur de filtres (avant enregistrement). Les filtres
     * sont traduits en SQL sûr par MoteurFiltre (champs en liste blanche, valeurs
     * liées) -> aucune injection même en saisie libre.
     *
     * @param list<array<string, mixed>> $filtres
     *
     * @return array{comptes: int, factures: int, encours: string}
     */
    public function apercuPourFiltres(array $filtres): array
    {
        [$where, $params] = $this->moteurFiltre->versSql($filtres);

        // Agrégat sur toute la vue (~100k lignes) : lourd, et rejoué pour CHAQUE règle
        // au rendu de la vue Stratégies. Le résultat ne bouge qu'au REFRESH nocturne de
        // v_impayes -> cache 10 min, clé = SQL normalisé + params (change si les filtres
        // changent, donc l'aperçu live du constructeur reste juste).
        $cle = 'recouvrement_apercu_'.sha1($where.'|'.(string) json_encode($params));

        return $this->cache->get($cle, function (ItemInterface $item) use ($where, $params): array {
            $item->expiresAfter(600);

            $sql = <<<SQL
                SELECT count(DISTINCT v.compte) AS comptes,
                       count(*) AS factures,
                       COALESCE(SUM(v.montant_solde), 0) AS encours
                FROM recouvrement.v_impayes v
                WHERE v.montant_solde > 0 AND v.jours_retard > 0 AND ({$where})
                SQL;

            /** @var array<string, mixed>|false $row */
            $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, $params);
            $row = false === $row ? [] : $row;

            return [
                'comptes' => (int) ($row['comptes'] ?? 0),
                'factures' => (int) ($row['factures'] ?? 0),
                'encours' => (string) ($row['encours'] ?? '0'),
            ];
        });
    }

    /**
     * Priorité à attribuer à une nouvelle règle (après la dernière existante).
     */
    public function prochainePriorite(): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.priorite)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 10 : ((int) $max + 10);
    }

    public function sauvegarder(RegleRelance $regle): void
    {
        $this->getEntityManager()->persist($regle);
        $this->getEntityManager()->flush();
    }

    public function supprimer(RegleRelance $regle): void
    {
        $this->getEntityManager()->remove($regle);
        $this->getEntityManager()->flush();
    }
}
