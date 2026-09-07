<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\CampagneRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Operation de relance en lot, reutilisable et eventuellement planifiee.
 * La selection (filtres) est evaluee dynamiquement a chaque execution.
 */
#[ORM\Entity(repositoryClass: CampagneRepository::class)]
#[ORM\Table(name: 'campagne', schema: 'creances')]
class Campagne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Filtres de selection (memes cles que CreancesRepository::Filtres).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $selection = [];

    #[ORM\ManyToOne(targetEntity: ModeleCourrier::class)]
    #[ORM\JoinColumn(name: 'modele_courrier_id', nullable: true, onDelete: 'SET NULL')]
    private ?ModeleCourrier $modeleCourrier = null;

    #[ORM\ManyToOne(targetEntity: StrategieNiveau::class)]
    #[ORM\JoinColumn(name: 'strategie_niveau_id', nullable: true, onDelete: 'SET NULL')]
    private ?StrategieNiveau $strategieNiveau = null;

    #[ORM\Column(length: 20)]
    private string $frequence = 'ponctuelle';

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $planification = [];

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'prochaine_execution', nullable: true)]
    private ?DateTimeImmutable $prochaineExecution = null;

    #[ORM\Column(name: 'derniere_execution', nullable: true)]
    private ?DateTimeImmutable $derniereExecution = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    public function __construct(string $libelle, ?User $auteur)
    {
        $this->libelle = $libelle;
        $this->auteur = $auteur;
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

    /**
     * @param array<string, mixed> $selection
     */
    public function setSelection(array $selection): void
    {
        $this->selection = $selection;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setModeleCourrier(?ModeleCourrier $modele): void
    {
        $this->modeleCourrier = $modele;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setStrategieNiveau(?StrategieNiveau $niveau): void
    {
        $this->strategieNiveau = $niveau;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setFrequence(string $frequence): void
    {
        $this->frequence = $frequence;
        $this->modifieLe = new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $planification
     */
    public function setPlanification(array $planification): void
    {
        $this->planification = $planification;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function enregistrerExecution(): void
    {
        $this->derniereExecution = new DateTimeImmutable();
        $this->modifieLe = $this->derniereExecution;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSelection(): array
    {
        return $this->selection;
    }

    public function getModeleCourrier(): ?ModeleCourrier
    {
        return $this->modeleCourrier;
    }

    public function getStrategieNiveau(): ?StrategieNiveau
    {
        return $this->strategieNiveau;
    }

    public function getFrequence(): string
    {
        return $this->frequence;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanification(): array
    {
        return $this->planification;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getProchaineExecution(): ?DateTimeImmutable
    {
        return $this->prochaineExecution;
    }

    public function getDerniereExecution(): ?DateTimeImmutable
    {
        return $this->derniereExecution;
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
}
