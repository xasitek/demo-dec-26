<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\ScoreIaRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cache du score enrichi par IA (1 ligne par compte). Le scoring
 * deterministe reste calcule a la volee par IndicateursService ; ici on
 * stocke uniquement l'analyse LLM.
 */
#[ORM\Entity(repositoryClass: ScoreIaRepository::class)]
#[ORM\Table(name: 'score_ia', schema: 'creances')]
class ScoreIa
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50, unique: true)]
    private string $compteCode;

    #[ORM\Column(type: 'decimal', precision: 3, scale: 1)]
    private string $score;

    #[ORM\Column(type: 'decimal', precision: 3, scale: 2, nullable: true)]
    private ?string $confiance = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $facteurs = null;

    #[ORM\Column(length: 20)]
    private string $provider = 'anthropic';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $modele = null;

    #[ORM\Column(name: 'tokens_utilises', type: 'integer', nullable: true)]
    private ?int $tokensUtilises = null;

    #[ORM\Column(name: 'calcule_le')]
    private DateTimeImmutable $calculeLe;

    #[ORM\Column(name: 'expire_le', nullable: true)]
    private ?DateTimeImmutable $expireLe = null;

    public function __construct(string $compteCode, string $score, string $provider = 'anthropic')
    {
        $this->compteCode = $compteCode;
        $this->score = $score;
        $this->provider = $provider;
        $this->calculeLe = new DateTimeImmutable();
        $this->expireLe = $this->calculeLe->modify('+30 days');
    }

    public function setScore(string $score): void
    {
        $this->score = $score;
        $this->calculeLe = new DateTimeImmutable();
        $this->expireLe = $this->calculeLe->modify('+30 days');
    }

    public function setConfiance(?string $confiance): void
    {
        $this->confiance = $confiance;
    }

    public function setCommentaire(?string $commentaire): void
    {
        $this->commentaire = $commentaire;
    }

    /**
     * @param list<string>|null $facteurs
     */
    public function setFacteurs(?array $facteurs): void
    {
        $this->facteurs = $facteurs;
    }

    public function setModele(?string $modele): void
    {
        $this->modele = $modele;
    }

    public function setTokensUtilises(?int $tokens): void
    {
        $this->tokensUtilises = $tokens;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompteCode(): string
    {
        return $this->compteCode;
    }

    public function getScore(): string
    {
        return $this->score;
    }

    public function getConfiance(): ?string
    {
        return $this->confiance;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    /**
     * @return list<string>|null
     */
    public function getFacteurs(): ?array
    {
        return $this->facteurs;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModele(): ?string
    {
        return $this->modele;
    }

    public function getTokensUtilises(): ?int
    {
        return $this->tokensUtilises;
    }

    public function getCalculeLe(): DateTimeImmutable
    {
        return $this->calculeLe;
    }

    public function getExpireLe(): ?DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function isExpire(): bool
    {
        if (null === $this->expireLe) {
            return false;
        }

        return $this->expireLe < new DateTimeImmutable();
    }
}
