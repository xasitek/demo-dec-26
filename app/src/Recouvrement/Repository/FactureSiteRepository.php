<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\FactureSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FactureSite>
 */
class FactureSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FactureSite::class);
    }

    /**
     * Etat d'une facture par sa cle ecriture (au plus un, contrainte d'unicite),
     * qu'il soit actif ou non.
     */
    public function parEcriture(string $ecritureId): ?FactureSite
    {
        return $this->findOneBy(['ecritureId' => $ecritureId]);
    }

    /**
     * Etats ACTIFS (factures gelees) d'un compte, indexes par `ecriture_id` — pour
     * afficher le statut par facture (modal, fiche client).
     *
     * @return array<string, FactureSite>
     */
    public function actifsParCompteIndexes(string $compteCode): array
    {
        $map = [];
        foreach ($this->findBy(['compteCode' => $compteCode, 'actif' => true]) as $etat) {
            $map[$etat->getEcritureId()] = $etat;
        }

        return $map;
    }

    public function save(FactureSite $etat, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($etat);
        if ($flush) {
            $em->flush();
        }
    }
}
