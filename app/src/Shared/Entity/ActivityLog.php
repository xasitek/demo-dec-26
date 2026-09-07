<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\ActivityLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'activite : connexions et deconnexions des utilisateurs, consultable
 * par les managers (suivi du teletravail). Donnee de surveillance : conservation
 * limitee, information des salaries obligatoire. Voir docs/SECURITY.md.
 */
#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Table(name: 'activity_log', schema: 'shared')]
#[ORM\Index(name: 'idx_activity_user_date', columns: ['user_id', 'occurred_at'])]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: ActivityAction::class)]
    private ActivityAction $action;

    #[ORM\Column]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    public function __construct(User $user, ActivityAction $action, ?string $ip = null, ?string $userAgent = null)
    {
        $this->user = $user;
        $this->action = $action;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->occurredAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAction(): ActivityAction
    {
        return $this->action;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
