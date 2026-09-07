<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Enum\StatutPreparation;
use App\Recouvrement\Repository\PreparationRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Suivi d'un lancement manuel de preparation de relances (bouton "Lancer
 * maintenant" d'une strategie). Permet d'afficher une barre de progression
 * persistante (survit au changement de page) : total, nombre traite, statut.
 */
#[ORM\Entity(repositoryClass: PreparationRunRepository::class)]
#[ORM\Table(name: 'preparation_run', schema: 'recouvrement')]
#[ORM\Index(name: 'idx_preparation_run_statut', columns: ['statut'])]
class PreparationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'regle_id', type: 'bigint', nullable: true)]
    private ?int $regleId = null;

    #[ORM\Column(name: 'regle_nom', length: 120)]
    private string $regleNom;

    #[ORM\Column(enumType: StatutPreparation::class)]
    private StatutPreparation $statut = StatutPreparation::EN_COURS;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $total = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $traites = 0;

    #[ORM\Column(name: 'lance_par', length: 255, nullable: true)]
    private ?string $lancePar = null;

    #[ORM\Column(name: 'lance_le')]
    private DateTimeImmutable $lanceLe;

    #[ORM\Column(name: 'termine_le', nullable: true)]
    private ?DateTimeImmutable $termineLe = null;

    /**
     * Heartbeat : dernier signe de vie du worker sur ce run. Un run EN_COURS dont
     * le heartbeat est trop vieux est un "zombie" (worker mort avant terminer()).
     */
    #[ORM\Column(name: 'dernier_signe_le', nullable: true)]
    private ?DateTimeImmutable $dernierSigneLe = null;

    public function __construct(?int $regleId, string $regleNom, ?string $lancePar)
    {
        $this->regleId = $regleId;
        $this->regleNom = $regleNom;
        $this->lancePar = $lancePar;
        $this->lanceLe = new DateTimeImmutable();
        $this->dernierSigneLe = $this->lanceLe;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRegleId(): ?int
    {
        return $this->regleId;
    }

    public function getRegleNom(): string
    {
        return $this->regleNom;
    }

    public function getStatut(): StatutPreparation
    {
        return $this->statut;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getTraites(): int
    {
        return $this->traites;
    }

    public function incrementerTraites(int $de = 1): self
    {
        $this->traites += $de;

        return $this;
    }

    public function getLancePar(): ?string
    {
        return $this->lancePar;
    }

    public function getLanceLe(): DateTimeImmutable
    {
        return $this->lanceLe;
    }

    public function getTermineLe(): ?DateTimeImmutable
    {
        return $this->termineLe;
    }

    public function getDernierSigneLe(): ?DateTimeImmutable
    {
        return $this->dernierSigneLe;
    }

    public function terminer(StatutPreparation $statut = StatutPreparation::TERMINE): self
    {
        $this->statut = $statut;
        $this->termineLe = new DateTimeImmutable();

        return $this;
    }

    /**
     * Pourcentage d'avancement (0-100), 100 si aucun element a traiter.
     */
    public function pourcentage(): int
    {
        if ($this->total <= 0) {
            return 100;
        }

        return (int) min(100, floor(100 * $this->traites / $this->total));
    }
}
