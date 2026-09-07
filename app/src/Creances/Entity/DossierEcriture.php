<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\DossierEcritureRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lien dossier <-> ecriture du mirror Progiciel. Pour un litige, on peut affecter
 * un montant partiel (le client conteste 200 EUR sur une facture de 1000 EUR)
 * et resoudre par ecriture individuellement.
 */
#[ORM\Entity(repositoryClass: DossierEcritureRepository::class)]
#[ORM\Table(name: 'dossier_ecriture', schema: 'creances')]
class DossierEcriture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'ecritures')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    #[ORM\Column(name: 'ecriture_numero', length: 50)]
    private string $ecritureNumero;

    #[ORM\Column(name: 'montant_partiel', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $montantPartiel = null;

    #[ORM\Column(name: 'date_resolution', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $dateResolution = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(
        Dossier $dossier,
        string $ecritureNumero,
        ?string $montantPartiel = null,
        ?string $commentaire = null,
    ) {
        $this->dossier = $dossier;
        $this->ecritureNumero = $ecritureNumero;
        $this->montantPartiel = $montantPartiel;
        $this->commentaire = $commentaire;
        $this->creeLe = new DateTimeImmutable();
    }

    public function resoudre(?DateTimeImmutable $date = null): void
    {
        $this->dateResolution = $date ?? new DateTimeImmutable();
    }

    public function annulerResolution(): void
    {
        $this->dateResolution = null;
    }

    public function setMontantPartiel(?string $montant): void
    {
        $this->montantPartiel = $montant;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getEcritureNumero(): string
    {
        return $this->ecritureNumero;
    }

    public function getMontantPartiel(): ?string
    {
        return $this->montantPartiel;
    }

    public function getDateResolution(): ?DateTimeImmutable
    {
        return $this->dateResolution;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function isResolue(): bool
    {
        return null !== $this->dateResolution;
    }
}
