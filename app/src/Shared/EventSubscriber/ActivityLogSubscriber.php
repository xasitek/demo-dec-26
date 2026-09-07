<?php

declare(strict_types=1);

namespace App\Shared\EventSubscriber;

use App\Shared\Entity\ActivityAction;
use App\Shared\Entity\ActivityLog;
use App\Shared\Entity\User;
use App\Shared\Repository\ActivityLogRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Journalise les connexions et deconnexions des utilisateurs. Voir docs/SECURITY.md.
 */
final class ActivityLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ActivityLogRepository $logs,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLogin',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->enregistrer($user, ActivityAction::Login, $event->getRequest());
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->enregistrer($user, ActivityAction::Logout, $event->getRequest());
        }
    }

    private function enregistrer(User $user, ActivityAction $action, ?Request $request): void
    {
        $this->logs->save(new ActivityLog(
            $user,
            $action,
            $request?->getClientIp(),
            mb_substr((string) $request?->headers->get('User-Agent'), 0, 255),
        ));
    }
}
