<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Recouvrement\Service\RecouvrementRealtime;
use App\Remboursement\Service\RemboursementRealtime;
use App\Shared\Entity\User;
use App\Shared\Service\NotificationService;
use App\Shared\Service\PresenceService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Mercure\Authorization;
use Throwable;

/**
 * Pose le cookie "mercureAuthorization" a chaque page HTML pour un utilisateur
 * connecte : il autorise l'abonnement Mercure aux topics PRIVES de cet
 * utilisateur (ses notifications). Sans ce cookie, le hub ne livre que les
 * topics publics -> les notifications de la cloche n'arrivaient jamais en direct.
 *
 * Les topics correspondent a ceux souscrits par le controleur "realtime" (layout).
 * L'EventSource doit etre ouvert avec withCredentials pour envoyer ce cookie au
 * hub (cross-origin app:8000 -> hub:3000). Best-effort : jamais bloquant.
 *
 * Priorite negative INDISPENSABLE : on lit le Content-Type de la reponse pour ne
 * poser le cookie que sur les pages HTML. Or c'est ResponseListener (priorite 0,
 * via Response::prepare()) qui renseigne ce header. En priorite >= 0 on passait
 * AVANT lui : le Content-Type etait vide, le test echouait, le cookie n'etait
 * jamais pose -> les topics prives (notifications de la cloche) restaient non
 * autorises et n'arrivaient jamais en direct. En -16 on passe apres lui.
 */
#[AsEventListener(event: ResponseEvent::class, priority: -16)]
final class MercureAuthorizationListener
{
    public function __construct(
        private readonly Authorization $authorization,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Uniquement sur les pages HTML (pas les fragments AJAX, JSON, telechargements).
        $contentType = (string) $event->getResponse()->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        $id = $user->getId();
        if (null === $id) {
            return;
        }

        $topics = [
            PresenceService::TOPIC_PRESENCE,
            NotificationService::topicUtilisateur($id),
        ];
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $topics[] = NotificationService::TOPIC_ADMIN_DEMANDES;
        }
        if ($this->security->isGranted('ROLE_COMPTABLE') || $this->security->isGranted('ROLE_MANAGER')) {
            $topics[] = RecouvrementRealtime::TOPIC_RETOURS;
        }
        if ($this->security->isGranted('MODULE_REMBOURSEMENT')) {
            // Topic prive "mes dossiers" du deposant : suivi de statut en direct.
            $topics[] = RemboursementRealtime::topicSecretaire($user->getUserIdentifier());
        }

        try {
            $cookie = $this->authorization->createCookie($event->getRequest(), $topics);
            $event->getResponse()->headers->setCookie($cookie);
        } catch (Throwable) {
            // Hub mal configure / secret absent : pas de temps reel plutot qu'une erreur.
        }
    }
}
