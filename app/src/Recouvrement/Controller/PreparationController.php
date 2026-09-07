<?php

declare(strict_types=1);

namespace App\Recouvrement\Controller;

use App\Recouvrement\Repository\PreparationRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Etat du lancement manuel de strategie en cours : alimente la barre de
 * progression GLOBALE (bandeau) au chargement d'une page (le temps reel Mercure
 * prend ensuite le relais). Lecture seule, accessible a tout membre du module.
 */
#[Route('/recouvrement/preparation')]
final class PreparationController extends AbstractController
{
    public function __construct(
        private readonly PreparationRunRepository $runs,
    ) {
    }

    #[Route('/etat', name: 'app_recouvrement_preparation_etat', methods: ['GET'])]
    public function etat(): JsonResponse
    {
        // L'acces au module est deja verifie par le firewall (^/recouvrement ->
        // MODULE_RECOUVREMENT) ; garde-fou explicite par securite.
        if (!$this->isGranted('MODULE_RECOUVREMENT')) {
            throw $this->createAccessDeniedException();
        }

        $run = $this->runs->enCours();
        if (null === $run) {
            return $this->json(['en_cours' => false]);
        }

        return $this->json([
            'en_cours' => true,
            'run_id' => (string) $run->getId(),
            'regle_nom' => $run->getRegleNom(),
            'total' => $run->getTotal(),
            'traites' => $run->getTraites(),
            'pct' => $run->pourcentage(),
            'statut' => $run->getStatut()->value,
        ]);
    }
}
