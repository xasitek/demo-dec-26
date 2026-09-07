<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\EtablissementContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EtablissementContact>
 */
class EtablissementContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EtablissementContact::class);
    }

    /**
     * Contacts d'un etablissement (pour la vue d'admin), directeurs d'abord.
     *
     * @return list<EtablissementContact>
     */
    public function pourEtablissement(string $codeEtab): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.etablissement = :code')
            ->setParameter('code', $codeEtab)
            ->orderBy('c.role', 'ASC')
            ->addOrderBy('c.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * E-mails ACTIFS d'un etablissement (destinataires par defaut d'une relance site).
     * Filtre facultatif sur le role.
     *
     * @return list<string>
     */
    public function emailsActifs(string $codeEtab, ?string $role = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.email')
            ->andWhere('c.etablissement = :code')
            ->andWhere('c.actif = true')
            ->setParameter('code', $codeEtab);

        if (null !== $role) {
            $qb->andWhere('c.role = :role')->setParameter('role', $role);
        }

        /** @var list<string> $emails */
        $emails = $qb->getQuery()->getSingleColumnResult();

        return $emails;
    }

    /**
     * Carte code etablissement -> nombre de contacts, en UNE requete. La vue d'admin
     * liste une centaine d'etablissements : compter ligne par ligne serait un N+1.
     *
     * @return array<string, int>
     */
    public function nbParEtablissement(): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.etablissement) AS code', 'COUNT(c.id) AS nb')
            ->groupBy('c.etablissement')
            ->getQuery()->getArrayResult();

        $map = [];
        foreach ($lignes as $ligne) {
            $map[(string) $ligne['code']] = (int) $ligne['nb'];
        }

        return $map;
    }

    /**
     * Carte code etablissement -> e-mail du DIRECTEUR (contact actif role "directeur"),
     * pour afficher le directeur dans les listes sans requete par ligne (pas de N+1).
     * S'il y a plusieurs directeurs, on retient le premier (ordre e-mail).
     *
     * @return array<string, string>
     */
    public function directeursParEtablissement(): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.etablissement) AS code', 'c.email AS email')
            ->andWhere('c.role = :role')->setParameter('role', 'directeur')
            ->andWhere('c.actif = true')
            ->orderBy('c.email', 'ASC')
            ->getQuery()->getArrayResult();

        $map = [];
        foreach ($lignes as $ligne) {
            $code = (string) $ligne['code'];
            if ('' !== $code && !isset($map[$code])) {
                $map[$code] = (string) $ligne['email'];
            }
        }

        return $map;
    }

    public function save(EtablissementContact $contact, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($contact);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(EtablissementContact $contact, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($contact);
        if ($flush) {
            $em->flush();
        }
    }
}
