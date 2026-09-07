<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\DemandeAccesRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'accès créée automatiquement à la première connexion d'un utilisateur
 * non habilité. Un administrateur l'approuve (en attribuant des rôles) ou la refuse.
 * Voir docs/SECURITY.md.
 */
#[ORM\Entity(repositoryClass: DemandeAccesRepository::class)]
#[ORM\Table(name: 'demande_acces', schema: 'shared')]
class DemandeAcces
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $demandeur;

    #[ORM\Column(length: 20, enumType: StatutDemande::class)]
    private StatutDemande $statut = StatutDemande::EnAttente;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $decideur = null;

    /**
     * Rôles attribués au moment de l'approbation.
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $rolesAttribues = [];

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    public function __construct(User $demandeur)
    {
        $this->demandeur = $demandeur;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemandeur(): User
    {
        return $this->demandeur;
    }

    public function getStatut(): StatutDemande
    {
        return $this->statut;
    }

    public function getDecideur(): ?User
    {
        return $this->decideur;
    }

    /**
     * @return list<string>
     */
    public function getRolesAttribues(): array
    {
        return $this->rolesAttribues;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function isEnAttente(): bool
    {
        return StatutDemande::EnAttente === $this->statut;
    }

    /**
     * Approuve la demande et fige les rôles attribués.
     *
     * @param list<string> $roles
     */
    public function approuver(User $decideur, array $roles): void
    {
        $this->statut = StatutDemande::Approuvee;
        $this->decideur = $decideur;
        $this->rolesAttribues = $roles;
        $this->decidedAt = new DateTimeImmutable();
    }

    public function refuser(User $decideur): void
    {
        $this->statut = StatutDemande::Refusee;
        $this->decideur = $decideur;
        $this->decidedAt = new DateTimeImmutable();
    }
}
