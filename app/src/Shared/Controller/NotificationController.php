<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\User;
use App\Shared\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Notifications in-app de l'utilisateur courant : ouverture (marque lue + suit le
 * lien) et "tout marquer lu". Voir docs/REALTIME.md.
 */
#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Page de notifications (fragment), pour le "Voir plus" de la cloche.
     */
    #[Route('', name: 'app_notification_list', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('notifications/_rows.html.twig', [
            'notifications' => $this->notifications->pageDe($user, $page, NotificationRepository::PAR_PAGE),
        ]);
    }

    /**
     * Marque la notification comme lue puis redirige vers son lien (ou l'accueil).
     */
    #[Route('/{id}/ouvrir', name: 'app_notification_ouvrir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ouvrir(int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $notification = $this->notifications->find($id);

        if (null === $notification || $notification->getDestinataire() !== $user) {
            throw $this->createNotFoundException();
        }

        if (!$notification->isLue()) {
            $notification->marquerLue();
            $this->em->flush();
        }

        return $this->redirect($notification->getUrl() ?: '/');
    }

    /**
     * Marque toutes les notifications de l'utilisateur comme lues.
     */
    #[Route('/lues', name: 'app_notification_tout_lu', methods: ['POST'])]
    public function toutMarquerLu(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notifications_lues', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->notifications->marquerToutesLues($user);

        // Ouverture de la cloche (fetch AJAX) : pas de redirection, juste un 204.
        // Le formulaire "Tout marquer lu" sans JS garde, lui, la redirection.
        if ($request->isXmlHttpRequest()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->redirect($request->headers->get('referer') ?: '/');
    }
}
