<?php

declare(strict_types=1);

namespace App\Shared\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute les en-tetes de securite HTTP a chaque reponse. Voir docs/SECURITY.md.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        // Deny par defaut, mais opt-in possible : une route qui doit etre affichable dans
        // une iframe de l'app (ex. apercu PDF same-origin) pose elle-meme X-Frame-Options
        // (SAMEORIGIN) ; on ne l'ecrase pas alors.
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // HSTS uniquement sur HTTPS (production).
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
