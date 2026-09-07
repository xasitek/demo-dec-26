<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Shared\Enum\Module;
use App\Shared\Service\NotificationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Notifie l'arrivee d'un dossier dans la file "a verifier" (comptable). Un seul
 * point d'entree pour l'evenement metier "nouveau dossier a verifier", qui declenche :
 *   1. la cloche : une notification in-app par comptable RATTACHEE au module
 *      Remboursement (intersection role + module ; les autres n'en recoivent pas) ;
 *      cette meme notification alimente aussi la notification navigateur (bureau) ;
 *   2. l'atelier comptable : insertion de la ligne en direct via un topic partage.
 *
 * Ne bloque jamais le pipeline IA : voir l'appel best-effort dans AnalyseDossierService.
 */
final class RemboursementNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly RemboursementRealtime $realtime,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function signalerAVerifier(Dossier $dossier): void
    {
        // 1. Cloche (+ notification navigateur) : seules les comptables du module.
        $this->notifications->notifierRoleEtModule(
            'ROLE_COMPTABLE',
            Module::REMBOURSEMENT,
            'Nouveau dossier à vérifier',
            $this->resume($dossier),
            $this->urls->generate('app_remboursement_accueil'),
            'info',
        );

        // 2. Atelier : la nouvelle ligne apparait en direct dans la file "a verifier".
        $this->realtime->signalerNouveauAVerifier($dossier);
    }

    private function resume(Dossier $dossier): string
    {
        $parts = array_values(array_filter([
            $dossier->getReference(),
            '' !== trim((string) $dossier->getNomClient()) ? trim((string) $dossier->getNomClient()) : null,
            $dossier->getMotif()->libelle(),
            number_format((float) $dossier->getMontant(), 2, ',', ' ').' €',
        ]));

        return implode(' · ', $parts);
    }
}
