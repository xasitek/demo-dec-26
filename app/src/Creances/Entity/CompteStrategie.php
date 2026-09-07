<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\CompteStrategieEtat;
use App\Creances\Repository\CompteStrategieRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lien compte_code <-> strategie de relance. Un compte est rattache a au
 * plus une strategie active a la fois (unique sur compte_code).
 */
#[ORM\Entity(repositoryClass: CompteStrategieRepository::class)]
#[ORM\Table(name: 'compte_strategie', schema: 'creances')]
class CompteStrategie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50, unique: true)]
    private string $compteCode;

    #[ORM\ManyToOne(targetEntity: Strategie::class)]
    #[ORM\JoinColumn(name: 'strategie_id', nullable: true, onDelete: 'SET NULL')]
    private ?Strategie $strategie;

    #[ORM\Column(length: 20, enumType: CompteStrategieEtat::class)]
    private CompteStrategieEtat $etat = CompteStrategieEtat::ARelancer;

    #[ORM\Column(name: 'repositionne_jusqu_au', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $repositionneJusquAu = null;

    #[ORM\Column(name: 'date_derniere_relance', nullable: true)]
    private ?DateTimeImmutable $dateDerniereRelance = null;

    #[ORM\Column(name: 'niveau_courant', type: 'integer')]
    private int $niveauCourant = 0;

    #[ORM\Column(name: 'erreur_message', type: 'text', nullable: true)]
    private ?string $erreurMessage = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    public function __construct(string $compteCode, ?Strategie $strategie = null)
    {
        $this->compteCode = $compteCode;
        $this->strategie = $strategie;
        $now = new DateTimeImmutable();
        $this->creeLe = $now;
        $this->modifieLe = $now;
    }

    public function affecterStrategie(?Strategie $strategie): void
    {
        $this->strategie = $strategie;
        $this->niveauCourant = 0;
        $this->etat = null !== $strategie ? CompteStrategieEtat::ARelancer : CompteStrategieEtat::Desactive;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function changerEtat(CompteStrategieEtat $etat): void
    {
        $this->etat = $etat;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function repositionner(?DateTimeImmutable $jusquAu): void
    {
        $this->repositionneJusquAu = $jusquAu;
        $this->etat = null !== $jusquAu ? CompteStrategieEtat::Repositionne : CompteStrategieEtat::ARelancer;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function enregistrerRelance(int $niveauTraite): void
    {
        $this->niveauCourant = $niveauTraite;
        $this->dateDerniereRelance = new DateTimeImmutable();
        $this->etat = CompteStrategieEtat::ARelancer;
        $this->modifieLe = $this->dateDerniereRelance;
    }

    public function signalerErreur(string $message): void
    {
        $this->etat = CompteStrategieEtat::Erreur;
        $this->erreurMessage = $message;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function effacerErreur(): void
    {
        if (CompteStrategieEtat::Erreur === $this->etat) {
            $this->etat = CompteStrategieEtat::ARelancer;
        }
        $this->erreurMessage = null;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompteCode(): string
    {
        return $this->compteCode;
    }

    public function getStrategie(): ?Strategie
    {
        return $this->strategie;
    }

    public function getEtat(): CompteStrategieEtat
    {
        return $this->etat;
    }

    public function getRepositionneJusquAu(): ?DateTimeImmutable
    {
        return $this->repositionneJusquAu;
    }

    public function getDateDerniereRelance(): ?DateTimeImmutable
    {
        return $this->dateDerniereRelance;
    }

    public function getNiveauCourant(): int
    {
        return $this->niveauCourant;
    }

    public function getErreurMessage(): ?string
    {
        return $this->erreurMessage;
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
