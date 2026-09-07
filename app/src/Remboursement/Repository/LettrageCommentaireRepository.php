<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use App\Remboursement\Entity\LettrageCommentaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LettrageCommentaire>
 */
class LettrageCommentaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LettrageCommentaire::class);
    }

    /**
     * Pose, met a jour ou retire le commentaire d'une ligne. Un texte vide EFFACE
     * l'annotation (meme contrat que l'ancien dashboard : commentaire vide = annulation
     * du marquage). Renvoie le commentaire courant, ou null s'il a ete retire.
     */
    public function definir(int $cleEcriture, string $commentaire, ?string $par): ?LettrageCommentaire
    {
        $em = $this->getEntityManager();
        $existant = $this->find($cleEcriture);
        $commentaire = trim($commentaire);

        if ('' === $commentaire) {
            if (null !== $existant) {
                $em->remove($existant);
                $em->flush();
            }

            return null;
        }

        if (null === $existant) {
            $existant = new LettrageCommentaire($cleEcriture, $commentaire, $par);
            $em->persist($existant);
        } else {
            $existant->mettreAJour($commentaire, $par);
        }

        $em->flush();

        return $existant;
    }
}
