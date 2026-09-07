<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\LegalAcceptance;
use App\Shared\Entity\User;
use App\Shared\Legal\LegalDocuments;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LegalAcceptance>
 */
class LegalAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalAcceptance::class);
    }

    /**
     * Documents dont la version courante n'a pas encore ete acceptee par l'utilisateur.
     *
     * @return list<string>
     */
    public function documentsManquants(User $user): array
    {
        /** @var list<array{document: string, version: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.document AS document', 'l.version AS version')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $acceptes = [];
        foreach ($rows as $r) {
            $acceptes[$r['document'].'@'.$r['version']] = true;
        }

        $manquants = [];
        foreach (LegalDocuments::VERSIONS as $document => $version) {
            if (!isset($acceptes[$document.'@'.$version])) {
                $manquants[] = $document;
            }
        }

        return $manquants;
    }

    /**
     * Enregistre l'acceptation des versions courantes des documents encore
     * manquants pour l'utilisateur (idempotent).
     */
    public function enregistrerCourantes(User $user, ?string $ip): void
    {
        $em = $this->getEntityManager();
        foreach ($this->documentsManquants($user) as $document) {
            $em->persist(new LegalAcceptance($user, $document, LegalDocuments::VERSIONS[$document], $ip));
        }
        $em->flush();
    }
}
