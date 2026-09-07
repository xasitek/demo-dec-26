<?php

declare(strict_types=1);

namespace App\Creances\Repository;

use App\Creances\Entity\DossierEcriture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierEcriture>
 */
final class DossierEcritureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierEcriture::class);
    }

    public function save(DossierEcriture $row): void
    {
        $em = $this->getEntityManager();
        $em->persist($row);
        $em->flush();
    }

    public function remove(DossierEcriture $row): void
    {
        $em = $this->getEntityManager();
        $em->remove($row);
        $em->flush();
    }

    /**
     * Renvoie la liste des numeros d'ecriture deja attaches a un dossier
     * litige/contentieux/echeancier actif. Permet de masquer les ecritures
     * deja prises en charge dans les selections.
     *
     * @return list<string>
     */
    public function listerEcrituresLieesAuCompte(string $compteCode): array
    {
        $sql = $this->createQueryBuilder('de')
            ->select('DISTINCT de.ecritureNumero')
            ->innerJoin('de.dossier', 'd')
            ->andWhere('d.compteCode = :code')
            ->setParameter('code', $compteCode);

        /** @var list<array{ecritureNumero: string}> $rows */
        $rows = $sql->getQuery()->getResult();

        return array_map(static fn (array $r) => $r['ecritureNumero'], $rows);
    }
}
