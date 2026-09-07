<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\NotificationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification in-app destinee a un utilisateur. Socle generique : n'importe quel
 * module appelle NotificationService::notifier(...). Voir docs/REALTIME.md.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification', schema: 'shared')]
#[ORM\Index(name: 'idx_notification_dest', columns: ['destinataire_id', 'created_at'])]
#[ORM\Index(name: 'idx_notification_non_lue', columns: ['destinataire_id', 'lu_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $destinataire;

    /** Tonalite : info | succes | alerte (pour l'icone et la couleur). */
    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $titre;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $url;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $luAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(User $destinataire, string $titre, ?string $message = null, ?string $url = null, string $type = 'info')
    {
        $this->destinataire = $destinataire;
        $this->titre = $titre;
        $this->message = $message;
        $this->url = $url;
        $this->type = $type;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinataire(): User
    {
        return $this->destinataire;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getLuAt(): ?DateTimeImmutable
    {
        return $this->luAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isLue(): bool
    {
        return null !== $this->luAt;
    }

    public function marquerLue(): void
    {
        $this->luAt ??= new DateTimeImmutable();
    }
}
