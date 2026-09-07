<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\BuyBackVehicule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuyBackVehicule>
 */
class BuyBackVehiculeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuyBackVehicule::class);
    }

    /**
     * Vehicule Buy Back pour une immat CANONIQUE (maj, alphanumerique). Si une plaque
     * a plusieurs contrats, on retient le plus recent (echeance la plus lointaine).
     */
    public function trouverParImmat(string $immatCanonique): ?BuyBackVehicule
    {
        if ('' === $immatCanonique) {
            return null;
        }

        return $this->createQueryBuilder('v')
            ->andWhere('v.immat = :immat')
            ->setParameter('immat', $immatCanonique)
            ->orderBy('v.dateEcheance', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Engagement de reprise TTC (chaine decimale) pour une immat canonique, ou null si
     * la plaque est inconnue / sans montant. Utilise par le controle anti-surpaiement.
     */
    public function erTtcPourImmat(string $immatCanonique): ?string
    {
        $vehicule = $this->trouverParImmat($immatCanonique);

        return null !== $vehicule ? $vehicule->getErTtc() : null;
    }
}
