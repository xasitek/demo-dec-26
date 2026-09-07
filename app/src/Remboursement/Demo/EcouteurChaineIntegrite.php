<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Entity\DossierTransition;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION.
 *
 * Le module historique ecrit sa transition ; cet ecouteur ajoute le maillon
 * correspondant a la chaine d'integrite. Le module ne sait pas qu'il existe, et
 * c'est voulu : on ne modifie pas WorkflowRemboursement pour ajouter une
 * garantie qui n'etait pas la sienne.
 *
 * Pourquoi postPersist, et pas prePersist : le maillon couvre l'identifiant de
 * la transition, qui n'existe qu'apres l'insertion.
 */
#[AsEntityListener(event: Events::postPersist, entity: DossierTransition::class)]
final readonly class EcouteurChaineIntegrite
{
    public function __construct(private ChaineIntegrite $chaine)
    {
    }

    public function postPersist(DossierTransition $transition, PostPersistEventArgs $evenement): void
    {
        $this->chaine->ajouter($transition);
    }
}
