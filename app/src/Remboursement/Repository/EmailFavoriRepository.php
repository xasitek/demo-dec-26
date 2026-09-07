<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\EmailFavori;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Carnet d'adresses personnel d'une secretaire : les adresses qu'elle a deja mises en
 * copie d'une attestation.
 *
 * @extends ServiceEntityRepository<EmailFavori>
 */
class EmailFavoriRepository extends ServiceEntityRepository
{
    /** Assez pour un menu deroulant ; au-dela, l'autocompletion fait le tri. */
    private const LIMITE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailFavori::class);
    }

    /**
     * Les adresses de CETTE secretaire, les plus utilisees d'abord.
     *
     * Une seule colonne remontee et un `LIMIT` : la liste part dans le gabarit a chaque
     * affichage du formulaire, elle ne doit ni hydrater des entites ni grossir avec
     * l'anciennete du compte.
     *
     * @return list<string>
     */
    public function pourSecretaire(string $secretaire): array
    {
        $secretaire = strtolower(trim($secretaire));
        if ('' === $secretaire) {
            return [];
        }

        /** @var list<string> $r */
        $r = $this->createQueryBuilder('f')
            ->select('f.email')
            ->andWhere('f.secretaire = :s')->setParameter('s', $secretaire)
            ->orderBy('f.nbUsages', 'DESC')
            ->addOrderBy('f.dernierUsageLe', 'DESC')
            ->setMaxResults(self::LIMITE)
            ->getQuery()
            ->getSingleColumnResult();

        return $r;
    }

    /**
     * Retient l'adresse pour cette secretaire, ou compte un usage de plus.
     *
     * UPSERT et non lecture-puis-ecriture : deux depots simultanes de la meme
     * secretaire vers la meme adresse se resoudraient sinon en violation de l'index
     * unique. `ON CONFLICT` laisse la base trancher, en une seule requete.
     */
    public function memoriser(string $secretaire, string $email): void
    {
        $secretaire = strtolower(trim($secretaire));
        $email = strtolower(trim($email));
        if ('' === $secretaire || '' === $email) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO remboursement.email_favori (secretaire, email, nb_usages, dernier_usage_le)
                VALUES (:secretaire, :email, 1, now())
                ON CONFLICT (secretaire, email) DO UPDATE
                    SET nb_usages = remboursement.email_favori.nb_usages + 1,
                        dernier_usage_le = now()
                SQL,
            ['secretaire' => $secretaire, 'email' => $email],
        );
    }
}
