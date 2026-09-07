<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\StrategieRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Scenario de relance reutilisable, applicable a plusieurs comptes via
 * `CompteStrategie`.
 */
#[ORM\Entity(repositoryClass: StrategieRepository::class)]
#[ORM\Table(name: 'strategie', schema: 'creances')]
class Strategie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    /**
     * @var Collection<int, StrategieNiveau>
     */
    #[ORM\OneToMany(targetEntity: StrategieNiveau::class, mappedBy: 'strategie', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $niveaux;

    public function __construct(string $code, string $libelle, ?User $auteur, ?string $description = null)
    {
        $this->code = $code;
        $this->libelle = $libelle;
        $this->auteur = $auteur;
        $this->description = $description;
        $this->niveaux = new ArrayCollection();
        $now = new DateTimeImmutable();
        $this->creeLe = $now;
        $this->modifieLe = $now;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): DateTimeImmutable
    {
        return $this->modifieLe;
    }

    /**
     * @return Collection<int, StrategieNiveau>
     */
    public function getNiveaux(): Collection
    {
        return $this->niveaux;
    }
}
