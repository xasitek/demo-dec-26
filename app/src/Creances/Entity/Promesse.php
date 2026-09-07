<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\PromesseStatut;
use App\Creances\Repository\PromesseRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Promesse de paiement simple sur une ecriture Progiciel. Pour un echeancier
 * complet (plusieurs traites datees), utiliser un Dossier de type echeancier
 * avec sa table d'echeances.
 */
#[ORM\Entity(repositoryClass: PromesseRepository::class)]
#[ORM\Table(name: 'promesse', schema: 'creances')]
class Promesse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50)]
    private string $compteCode;

    #[ORM\Column(name: 'ecriture_numero', length: 50)]
    private string $ecritureNumero;

    #[ORM\Column(name: 'date_promesse', type: 'date_immutable')]
    private DateTimeImmutable $datePromesse;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $montant = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(length: 20, enumType: PromesseStatut::class)]
    private PromesseStatut $statut = PromesseStatut::EnCours;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    public function __construct(
        string $compteCode,
        string $ecritureNumero,
        DateTimeImmutable $datePromesse,
        ?User $auteur,
        ?string $montant = null,
        ?string $commentaire = null,
    ) {
        $this->compteCode = $compteCode;
        $this->ecritureNumero = $ecritureNumero;
        $this->datePromesse = $datePromesse;
        $this->auteur = $auteur;
        $this->montant = $montant;
        $this->commentaire = $commentaire;
        $now = new DateTimeImmutable();
        $this->creeLe = $now;
        $this->modifieLe = $now;
    }

    public function changerStatut(PromesseStatut $statut): void
    {
        $this->statut = $statut;
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

    public function getEcritureNumero(): string
    {
        return $this->ecritureNumero;
    }

    public function getDatePromesse(): DateTimeImmutable
    {
        return $this->datePromesse;
    }

    public function getMontant(): ?string
    {
        return $this->montant;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getStatut(): PromesseStatut
    {
        return $this->statut;
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

    public function isEchue(): bool
    {
        if (PromesseStatut::EnCours !== $this->statut) {
            return false;
        }

        return $this->datePromesse < new DateTimeImmutable('today');
    }
}
