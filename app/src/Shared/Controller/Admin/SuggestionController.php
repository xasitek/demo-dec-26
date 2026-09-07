<?php

declare(strict_types=1);

namespace App\Shared\Controller\Admin;

use App\Shared\Entity\Suggestion;
use App\Shared\Entity\User;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use App\Shared\Repository\SuggestionRepository;
use App\Shared\Service\SuggestionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Traitement des remontees de la boite a idees. C'est ici que se ferme la boucle :
 * un statut et un mot d'explication, et l'auteur est prevenu (cf. SuggestionService).
 */
#[Route('/admin/suggestions')]
#[IsGranted('ROLE_ADMIN')]
final class SuggestionController extends AbstractController
{
    #[Route('', name: 'app_admin_suggestions', methods: ['GET'])]
    public function index(Request $request, SuggestionRepository $suggestions): Response
    {
        $statut = StatutSuggestion::tryFrom((string) $request->query->get('statut', ''));
        $type = TypeSuggestion::tryFrom((string) $request->query->get('type', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $total = $suggestions->compterAdmin($statut, $type);

        return $this->render('admin/suggestions/index.html.twig', [
            'suggestions' => $suggestions->pageAdmin($statut, $type, $page),
            'statut' => $statut,
            'type' => $type,
            'page' => $page,
            'total' => $total,
            'pages' => (int) ceil($total / SuggestionRepository::PAR_PAGE_ADMIN),
            'statuts' => StatutSuggestion::choix(),
            'types' => TypeSuggestion::choix(),
        ]);
    }

    #[Route('/{id}/statut', name: 'app_admin_suggestions_statut', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function statut(
        Request $request,
        Suggestion $suggestion,
        SuggestionService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('suggestion_statut'.$suggestion->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $statut = StatutSuggestion::tryFrom((string) $request->request->get('statut', ''));
        if (null === $statut) {
            $this->addFlash('error', 'Statut inconnu.');

            return $this->redirectToRoute('app_admin_suggestions');
        }

        /** @var User $administrateur */
        $administrateur = $this->getUser();

        $service->traiter(
            $suggestion,
            $statut,
            (string) $request->request->get('reponse', ''),
            $administrateur,
        );

        $this->addFlash('success', sprintf(
            'Remontée de %s marquée « %s »%s.',
            $suggestion->getAuteurNom(),
            $statut->libelle(),
            $statut->meriteNotification() && null !== $suggestion->getAuteur() ? ' — auteur prévenu' : '',
        ));

        return $this->redirectToRoute('app_admin_suggestions', array_filter([
            'statut' => $request->request->get('filtre_statut'),
            'type' => $request->request->get('filtre_type'),
        ]));
    }
}
