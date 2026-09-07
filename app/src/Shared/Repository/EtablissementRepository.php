<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Etablissement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Etablissement>
 */
class EtablissementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etablissement::class);
    }

    /**
     * Tous les etablissements, triables pour la vue d'admin. Recherche facultative
     * sur code / libelle / societe.
     *
     * @return list<Etablissement>
     */
    public function rechercher(?string $q = null): array
    {
        $qb = $this->createQueryBuilder('e')->orderBy('e.libelle', 'ASC');

        $q = null !== $q ? trim($q) : '';
        if ('' !== $q) {
            $qb->andWhere('LOWER(e.codeEtab) LIKE :q OR LOWER(e.libelle) LIKE :q OR LOWER(e.societe) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Etablissements ACTIFS seuls : listes de SAISIE (depot d'un dossier). Desactiver un
     * etablissement doit le retirer des choix proposes.
     *
     * A ne pas utiliser pour AFFICHER un dossier existant : les ecrans comptables ont
     * besoin du referentiel complet, sinon le libelle d'un etablissement depuis desactive
     * disparaitrait de l'historique. Ceux-la passent par rechercher().
     *
     * @return list<Etablissement>
     */
    public function actifs(): array
    {
        /** @var list<Etablissement> $r */
        $r = $this->createQueryBuilder('e')
            ->andWhere('e.actif = true')
            ->orderBy('e.libelle', 'ASC')
            ->getQuery()->getResult();

        return $r;
    }

    /**
     * Compteurs du referentiel (en-tete de la vue d'admin), en UNE requete.
     *
     * @return array{total: int, actifs: int}
     */
    public function compteurs(): array
    {
        /** @var array{total: int|string, actifs: int|string} $row */
        $row = $this->createQueryBuilder('e')
            ->select('COUNT(e.codeEtab) AS total', 'SUM(CASE WHEN e.actif = true THEN 1 ELSE 0 END) AS actifs')
            ->getQuery()->getSingleResult();

        return ['total' => (int) $row['total'], 'actifs' => (int) $row['actifs']];
    }

    public function save(Etablissement $etablissement, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($etablissement);
        if ($flush) {
            $em->flush();
        }
    }
}
