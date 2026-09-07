<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\DemandeSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeSite>
 */
class DemandeSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeSite::class);
    }

    /**
     * Trouve ou crée le dossier (compte, site). Un dossier clos est rouvert. Le
     * dossier est persisté (flush) et retourné, prêt à recevoir des factures.
     */
    public function ouvrirPour(string $compteCode, string $codeSite, ?string $par = null): DemandeSite
    {
        $dossier = $this->findOneBy(['compteCode' => $compteCode, 'codeSite' => $codeSite]);
        if (null === $dossier) {
            $dossier = new DemandeSite($compteCode, $codeSite, self::genererToken(), $par);
        } elseif ($dossier->estClos()) {
            $dossier->setStatut(DemandeSite::STATUT_OUVERT, $par);
        }
        $this->save($dossier);

        return $dossier;
    }

    public function parToken(string $token): ?DemandeSite
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Cadence courante par site pour un compte : code_site => cadence ('2s', ...).
     * Sert à pré-remplir le sélecteur de cadence du modal "relance site".
     *
     * @return array<string, string>
     */
    public function cadencesParCompte(string $compteCode): array
    {
        $out = [];
        foreach ($this->findBy(['compteCode' => $compteCode]) as $dossier) {
            $out[$dossier->getCodeSite()] = $dossier->getCadenceIntervalle();
        }

        return $out;
    }

    public function save(DemandeSite $dossier, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($dossier);
        if ($flush) {
            $em->flush();
        }
    }

    private static function genererToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
