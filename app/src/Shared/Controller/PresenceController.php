<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\User;
use App\Shared\Service\PresenceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints de presence temps reel (heartbeat + liste). Voir docs/REALTIME.md.
 */
#[Route('/presence')]
#[IsGranted('ROLE_USER')]
final class PresenceController extends AbstractController
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Bascule "forcer ma presence en ligne" pour l'utilisateur courant.
     */
    #[Route('/forcer-en-ligne', name: 'app_presence_forcer', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function forcerEnLigne(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('presence_forcer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setPresenceForceeEnLigne(!$user->isPresenceForceeEnLigne());
        $this->em->flush();

        // Republie immediatement la presence pour refleter le changement.
        $this->presence->ping($user, true);

        return $this->redirect($request->headers->get('referer') ?: '/');
    }

    /**
     * Heartbeat : signale la presence et l'activite de l'utilisateur courant.
     */
    #[Route('/ping', name: 'app_presence_ping', methods: ['POST'])]
    public function ping(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $active = $request->getPayload()->getBoolean('active', true);

        return new JsonResponse(['status' => $this->presence->ping($user, $active)]);
    }

    /**
     * Etat initial des presences visibles par l'utilisateur courant.
     */
    #[Route('/list', name: 'app_presence_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse($this->presence->listeVisible($user));
    }

    /**
     * Marque l'utilisateur hors-ligne (fermeture d'onglet, best-effort).
     */
    #[Route('/disconnect', name: 'app_presence_disconnect', methods: ['POST'])]
    public function disconnect(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->presence->deconnecter($user);

        return new JsonResponse(['ok' => true]);
    }
}
