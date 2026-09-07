<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\RetourPieceJointe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetourPieceJointe>
 */
final class RetourPieceJointeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetourPieceJointe::class);
    }

    public function save(RetourPieceJointe $piece, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($piece);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Metadonnees des pieces jointes d'un retour (SANS le contenu binaire, pour
     * l'affichage de la liste). Le contenu n'est charge qu'au telechargement.
     *
     * @return list<array{id: int, nom: string, typeMime: ?string, taille: int}>
     */
    public function metaParRetour(int $retourId): array
    {
        /** @var list<array{id: int|string, nom: string, typeMime: ?string, taille: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS id', 'p.nom AS nom', 'p.typeMime AS typeMime', 'p.taille AS taille')
            ->andWhere('p.retour = :retour')
            ->setParameter('retour', $retourId)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nom' => $r['nom'],
            'typeMime' => $r['typeMime'],
            'taille' => (int) $r['taille'],
        ], $rows);
    }

    /**
     * Metadonnees des pieces jointes de PLUSIEURS retours, en une seule requete
     * (anti N+1) : indispensable pour afficher les PJ de chaque message d'une
     * conversation regroupee. Indexe par id de retour. Sans le contenu binaire.
     *
     * @param list<int> $retourIds
     *
     * @return array<int, list<array{id: int, nom: string, typeMime: ?string, taille: int}>>
     */
    public function metaParRetours(array $retourIds): array
    {
        if ([] === $retourIds) {
            return [];
        }

        /** @var list<array{retourId: int|string, id: int|string, nom: string, typeMime: ?string, taille: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.retour) AS retourId', 'p.id AS id', 'p.nom AS nom', 'p.typeMime AS typeMime', 'p.taille AS taille')
            ->andWhere('p.retour IN (:ids)')
            ->setParameter('ids', $retourIds)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        $parRetour = [];
        foreach ($rows as $r) {
            $parRetour[(int) $r['retourId']][] = [
                'id' => (int) $r['id'],
                'nom' => $r['nom'],
                'typeMime' => $r['typeMime'],
                'taille' => (int) $r['taille'],
            ];
        }

        return $parRetour;
    }
}
