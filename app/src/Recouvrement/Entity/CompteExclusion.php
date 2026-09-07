<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Enum\CompteExclusionEtat;
use App\Recouvrement\Repository\CompteExclusionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Decision de curation d'un compte client pour la relance automatique.
 *
 * Une ligne par compte (cle metier = compte_code, unique). Semee automatiquement
 * (origine AUTO) par app:recouvrement:seed-exclusions, ou posee a la main
 * (origine MANUEL) via app:recouvrement:ecarter / :reactiver. Une decision
 * manuelle prime : le re-semis ne l'ecrase pas.
 */
#[ORM\Entity(repositoryClass: CompteExclusionRepository::class)]
#[ORM\Table(name: 'compte_exclusion', schema: 'recouvrement')]
#[ORM\UniqueConstraint(name: 'uniq_compte_exclusion_compte', columns: ['compte_code'])]
#[ORM\Index(name: 'idx_compte_exclusion_etat', columns: ['etat'])]
class CompteExclusion
{
    public const ORIGINE_AUTO = 'auto';
    public const ORIGINE_MANUEL = 'manuel';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 64)]
    private string $compteCode;

    #[ORM\Column(enumType: CompteExclusionEtat::class)]
    private CompteExclusionEtat $etat;

    /** "auto" (regle de semis) ou "manuel" (decision humaine). */
    #[ORM\Column(length: 16)]
    private string $origine;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motif = null;

    /** Nom de l'operateur ayant pose une decision manuelle (null si auto). */
    #[ORM\Column(name: 'decide_par', length: 255, nullable: true)]
    private ?string $decidePar = null;

    #[ORM\Column(name: 'decide_le', nullable: true)]
    private ?DateTimeImmutable $decideLe = null;

    #[ORM\Column(name: 'modifie_le', nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(string $compteCode, CompteExclusionEtat $etat, string $origine)
    {
        $this->compteCode = $compteCode;
        $this->etat = $etat;
        $this->origine = $origine;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompteCode(): string
    {
        return $this->compteCode;
    }

    public function getEtat(): CompteExclusionEtat
    {
        return $this->etat;
    }

    public function setEtat(CompteExclusionEtat $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): self
    {
        $this->origine = $origine;

        return $this;
    }

    public function estManuel(): bool
    {
        return self::ORIGINE_MANUEL === $this->origine;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getDecidePar(): ?string
    {
        return $this->decidePar;
    }

    public function setDecidePar(?string $decidePar): self
    {
        $this->decidePar = $decidePar;

        return $this;
    }

    public function getDecideLe(): ?DateTimeImmutable
    {
        return $this->decideLe;
    }

    public function setDecideLe(?DateTimeImmutable $decideLe): self
    {
        $this->decideLe = $decideLe;

        return $this;
    }

    public function getModifieLe(): ?DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function setModifieLe(?DateTimeImmutable $modifieLe): self
    {
        $this->modifieLe = $modifieLe;

        return $this;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
