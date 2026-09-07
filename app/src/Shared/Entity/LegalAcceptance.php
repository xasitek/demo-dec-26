<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\LegalAcceptanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'acceptation / prise de connaissance d'un document legal par un
 * utilisateur, a une version donnee. Sert de preuve d'information (RGPD). Une
 * ligne par (user, document, version). Voir docs/SECURITY.md.
 */
#[ORM\Entity(repositoryClass: LegalAcceptanceRepository::class)]
#[ORM\Table(name: 'legal_acceptance', schema: 'shared')]
#[ORM\UniqueConstraint(name: 'uniq_legal_user_doc_version', columns: ['user_id', 'document', 'version'])]
class LegalAcceptance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 50)]
    private string $document;

    #[ORM\Column(length: 30)]
    private string $version;

    #[ORM\Column]
    private DateTimeImmutable $acceptedAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    public function __construct(User $user, string $document, string $version, ?string $ip = null)
    {
        $this->user = $user;
        $this->document = $document;
        $this->version = $version;
        $this->ip = $ip;
        $this->acceptedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDocument(): string
    {
        return $this->document;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getAcceptedAt(): DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }
}
