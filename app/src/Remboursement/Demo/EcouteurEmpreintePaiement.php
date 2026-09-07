<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Service\GenerationFichiersComptables;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION.
 *
 * Pose l'empreinte du paiement au moment ou le fichier SEPA est attache au
 * dossier. Le module historique n'est pas modifie : il attache sa piece, et
 * l'ecouteur en prend l'empreinte.
 *
 * Pourquoi ici, et pas dans le service de generation : ce service est `final`,
 * et le renforcement ne doit pas devenir un pretexte a le rouvrir. Le fichier
 * atterrit toujours comme DossierPiece, quel que soit le chemin appele -- le
 * worker, la commande, une reprise apres erreur. L'empreinte est donc posee
 * dans tous les cas.
 */
#[AsEntityListener(event: Events::postPersist, entity: DossierPiece::class)]
final readonly class EcouteurEmpreintePaiement
{
    public function __construct(
        private GardeAntiRejeu $garde,
        private \App\Remboursement\Repository\DossierPieceRepository $pieces,
    ) {
    }

    public function postPersist(DossierPiece $piece, PostPersistEventArgs $evenement): void
    {
        if (GenerationFichiersComptables::TYPE_SEPA !== $piece->getType()) {
            return;
        }

        // Le CSV d'ecriture est attache AVANT le SEPA, dans le meme flush : sa
        // ligne est donc deja visible pour cette connexion. On la lit plutot que
        // d'attendre un second evenement, et on se passe de son contenu si elle
        // n'est pas encore la -- l'empreinte du paiement, c'est le SEPA.
        // On lit l'OD PAR L'ENTITE : la colonne est un bytea, et son contenu
        // brut n'est pas le CSV -- c'est getContenu() qui le rend.
        $od = null;
        foreach ($this->pieces->pourDossier($piece->getDossier()) as $autre) {
            if (GenerationFichiersComptables::TYPE_OD === $autre->getType()) {
                $od = $autre;
            }
        }

        $this->garde->enregistrerSepa(
            $piece->getDossier(),
            $piece,
            $od?->getNomFichier(),
            $od?->getContenu(),
        );
    }
}
