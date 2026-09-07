<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\UserPresenceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Presence temps reel d'un utilisateur : dernier heartbeat et derniere activite.
 * Table volontairement separee de users (ecritures frequentes). Voir docs/REALTIME.md.
 */
#[ORM\Entity(repositoryClass: UserPresenceRepository::class)]
#[ORM\Table(name: 'user_presence', schema: 'shared')]
class UserPresence
{
    /** En ligne / inactif / hors ligne (en secondes). */
    public const SEUIL_EN_LIGNE = 70;
    public const SEUIL_ACTIVITE = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private DateTimeImmutable $lastPingAt;

    #[ORM\Column]
    private DateTimeImmutable $lastActivityAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $now = new DateTimeImmutable();
        $this->lastPingAt = $now;
        $this->lastActivityAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLastPingAt(): DateTimeImmutable
    {
        return $this->lastPingAt;
    }

    public function touch(bool $active): void
    {
        $now = new DateTimeImmutable();
        $this->lastPingAt = $now;
        if ($active) {
            $this->lastActivityAt = $now;
        }
    }

    /**
     * Retourne 'online', 'idle' ou 'offline' selon la fraicheur du ping/activite.
     */
    public function statut(): string
    {
        $now = time();

        if ($now - $this->lastPingAt->getTimestamp() > self::SEUIL_EN_LIGNE) {
            return 'offline';
        }

        if ($now - $this->lastActivityAt->getTimestamp() > self::SEUIL_ACTIVITE) {
            return 'idle';
        }

        return 'online';
    }
}
