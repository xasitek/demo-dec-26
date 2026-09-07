<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Entity\Notification;
use App\Shared\Entity\User;
use App\Shared\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose a Twig le compteur de notifications non lues et les plus recentes de
 * l'utilisateur courant (pour la cloche). Memoize par requete.
 */
final class NotificationExtension extends AbstractExtension
{
    private ?int $nonLues = null;

    private ?int $total = null;

    /** @var list<Notification>|null */
    private ?array $recentes = null;

    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notifications_non_lues', $this->nonLues(...)),
            new TwigFunction('notifications_total', $this->total(...)),
            new TwigFunction('notifications_recentes', $this->recentes(...)),
        ];
    }

    public function total(): int
    {
        if (null !== $this->total) {
            return $this->total;
        }

        $user = $this->security->getUser();
        $this->total = $user instanceof User ? $this->notifications->compterToutes($user) : 0;

        return $this->total;
    }

    public function nonLues(): int
    {
        if (null !== $this->nonLues) {
            return $this->nonLues;
        }

        $user = $this->security->getUser();
        $this->nonLues = $user instanceof User ? $this->notifications->compterNonLues($user) : 0;

        return $this->nonLues;
    }

    /**
     * @return list<Notification>
     */
    public function recentes(): array
    {
        if (null !== $this->recentes) {
            return $this->recentes;
        }

        $user = $this->security->getUser();
        $this->recentes = $user instanceof User ? $this->notifications->recentes($user, NotificationRepository::PAR_PAGE) : [];

        return $this->recentes;
    }
}
