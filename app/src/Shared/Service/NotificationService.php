<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Shared\Entity\Notification;
use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use App\Shared\Repository\NotificationRepository;
use App\Shared\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Publie les notifications temps réel via Mercure. La publication est
 * best-effort : un hub indisponible ne doit jamais bloquer l'action métier.
 * Voir docs/REALTIME.md.
 */
final class NotificationService
{
    /**
     * Topic des notifications d'administration (demandes d'accès).
     * Public : ne transporte qu'un compteur, aucune donnée sensible. Les topics
     * financiers (garanties, etc.) seront privés. Voir docs/REALTIME.md.
     */
    public const TOPIC_ADMIN_DEMANDES = 'fc-finance:admin:demandes';

    /** Prefixe du topic prive de notifications, suffixe par l'id utilisateur. */
    public const TOPIC_NOTIF_PREFIX = 'fc-finance:notif:user:';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function topicUtilisateur(int $userId): string
    {
        return self::TOPIC_NOTIF_PREFIX.$userId;
    }

    /**
     * Cree une notification in-app pour un utilisateur et la pousse en temps reel
     * (topic prive). Point d'entree unique : appelable depuis n'importe quel module.
     */
    public function notifier(
        User $destinataire,
        string $titre,
        ?string $message = null,
        ?string $url = null,
        string $type = 'info',
    ): Notification {
        $notification = new Notification($destinataire, $titre, $message, $url, $type);
        $this->notifications->save($notification);

        $id = $destinataire->getId();
        if (null !== $id) {
            $this->publierNotif($id, $notification, $this->notifications->compterNonLues($destinataire));
        }

        return $notification;
    }

    /**
     * Notifie tous les utilisateurs (actifs, habilites) possedant un role.
     * Fan-out : une notification par destinataire (etat lu/non-lu propre a chacun).
     *
     * @return int nombre de destinataires notifies
     */
    public function notifierRole(
        string $role,
        string $titre,
        ?string $message = null,
        ?string $url = null,
        string $type = 'info',
    ): int {
        $destinataires = $this->users->avecRole($role);
        foreach ($destinataires as $user) {
            $this->notifier($user, $titre, $message, $url, $type);
        }

        return \count($destinataires);
    }

    /**
     * Notifie tous les utilisateurs actifs rattaches a un module. Fan-out : une
     * notification par destinataire (etat lu/non-lu propre a chacun).
     *
     * @return int nombre de destinataires notifies
     */
    public function notifierModule(
        Module $module,
        string $titre,
        ?string $message = null,
        ?string $url = null,
        string $type = 'info',
    ): int {
        $destinataires = $this->users->avecModule($module);
        foreach ($destinataires as $user) {
            $this->notifier($user, $titre, $message, $url, $type);
        }

        return \count($destinataires);
    }

    /**
     * Notifie les utilisateurs ayant A LA FOIS le role ET le module. Fan-out : une
     * notification par destinataire. Ex. seules les comptables rattachees au module
     * Remboursement sont averties d'un dossier a verifier.
     *
     * @return int nombre de destinataires notifies
     */
    public function notifierRoleEtModule(
        string $role,
        Module $module,
        string $titre,
        ?string $message = null,
        ?string $url = null,
        string $type = 'info',
    ): int {
        $destinataires = $this->users->avecRoleEtModule($role, $module);
        foreach ($destinataires as $user) {
            $this->notifier($user, $titre, $message, $url, $type);
        }

        return \count($destinataires);
    }

    private function publierNotif(int $userId, Notification $notification, int $nonLues): void
    {
        try {
            $this->hub->publish(new Update(
                self::topicUtilisateur($userId),
                json_encode([
                    'type' => 'notification',
                    'id' => $notification->getId(),
                    'titre' => $notification->getTitre(),
                    'message' => $notification->getMessage(),
                    'url' => $notification->getUrl(),
                    'notifType' => $notification->getType(),
                    'nonLues' => $nonLues,
                ], \JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication notification Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Notifie les administrateurs qu'une demande d'accès est en attente.
     */
    public function notifierDemandesEnAttente(int $count, ?string $demandeur = null): void
    {
        try {
            $this->hub->publish(new Update(
                self::TOPIC_ADMIN_DEMANDES,
                json_encode([
                    'type' => 'demande_acces',
                    'count' => $count,
                    'demandeur' => $demandeur,
                ], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }
}
