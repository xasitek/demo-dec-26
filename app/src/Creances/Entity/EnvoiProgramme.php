<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\EnvoiProgrammeRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapport recurrent envoye automatiquement par e-mail a une liste de
 * destinataires (DSO mensuel, balance agee hebdo, etc.). L'execution est
 * declenchee par Symfony Scheduler / cron.
 */
#[ORM\Entity(repositoryClass: EnvoiProgrammeRepository::class)]
#[ORM\Table(name: 'envoi_programme', schema: 'creances')]
class EnvoiProgramme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $libelle;

    /**
     * Type de rapport : balance_agee, top10, dso, indicateurs_globaux,
     * custom (filtres libres).
     */
    #[ORM\Column(length: 30)]
    private string $type;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $configuration = [];

    /**
     * Frequence : quotidien, hebdomadaire, mensuel, trimestriel.
     */
    #[ORM\Column(length: 20)]
    private string $frequence;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $planification = [];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $destinataires = [];

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

    public function __construct(string $libelle, string $type, string $frequence, ?User $auteur)
    {
        $this->libelle = $libelle;
        $this->type = $type;
        $this->frequence = $frequence;
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

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setFrequence(string $frequence): void
    {
        $this->frequence = $frequence;
        $this->modifieLe = new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
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

    /**
     * @param list<string> $destinataires
     */
    public function setDestinataires(array $destinataires): void
    {
        $this->destinataires = $destinataires;
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

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
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

    /**
     * @return list<string>
     */
    public function getDestinataires(): array
    {
        return $this->destinataires;
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
