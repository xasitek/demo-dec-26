<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\DossierStatut;
use App\Creances\Enum\DossierType;
use App\Creances\Repository\DossierRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conteneur metier qui regroupe des ecritures et historise une action longue
 * (echeancier de paiement, litige, contentieux). Le type determine les
 * comportements specifiques cote service / UI.
 */
#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Table(name: 'dossier', schema: 'creances')]
class Dossier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50)]
    private string $compteCode;

    #[ORM\Column(length: 20, enumType: DossierType::class)]
    private DossierType $type;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'responsable_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $responsable = null;

    #[ORM\Column(name: 'date_debut', type: 'date_immutable')]
    private DateTimeImmutable $dateDebut;

    #[ORM\Column(name: 'date_resolution_cible', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $dateResolutionCible = null;

    #[ORM\Column(name: 'date_resolution', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $dateResolution = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $resultat = null;

    #[ORM\Column(name: 'commentaire_resolution', type: 'text', nullable: true)]
    private ?string $commentaireResolution = null;

    #[ORM\Column(length: 20, enumType: DossierStatut::class)]
    private DossierStatut $statut = DossierStatut::Ouvert;

    #[ORM\Column(name: 'montant_total', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $montantTotal = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    /**
     * @var Collection<int, DossierEcriture>
     */
    #[ORM\OneToMany(targetEntity: DossierEcriture::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ecritures;

    /**
     * @var Collection<int, Echeance>
     */
    #[ORM\OneToMany(targetEntity: Echeance::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['numero' => 'ASC'])]
    private Collection $echeances;

    public function __construct(
        string $compteCode,
        DossierType $type,
        string $libelle,
        DateTimeImmutable $dateDebut,
        ?User $auteur,
        ?User $responsable = null,
        ?string $description = null,
        ?DateTimeImmutable $dateResolutionCible = null,
    ) {
        $this->compteCode = $compteCode;
        $this->type = $type;
        $this->libelle = $libelle;
        $this->dateDebut = $dateDebut;
        $this->auteur = $auteur;
        $this->responsable = $responsable;
        $this->description = $description;
        $this->dateResolutionCible = $dateResolutionCible;
        $this->ecritures = new ArrayCollection();
        $this->echeances = new ArrayCollection();
        $now = new DateTimeImmutable();
        $this->creeLe = $now;
        $this->modifieLe = $now;
    }

    public function cloturer(string $resultat, ?string $commentaire = null): void
    {
        $this->resultat = $resultat;
        $this->commentaireResolution = $commentaire;
        $this->statut = DossierStatut::Clos;
        $this->dateResolution = new DateTimeImmutable();
        $this->modifieLe = $this->dateResolution;
    }

    public function rouvrir(): void
    {
        $this->resultat = null;
        $this->commentaireResolution = null;
        $this->statut = DossierStatut::Ouvert;
        $this->dateResolution = null;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setMontantTotal(?string $montant): void
    {
        $this->montantTotal = $montant;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setResponsable(?User $responsable): void
    {
        $this->responsable = $responsable;
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

    public function getType(): DossierType
    {
        return $this->type;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getResponsable(): ?User
    {
        return $this->responsable;
    }

    public function getDateDebut(): DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateResolutionCible(): ?DateTimeImmutable
    {
        return $this->dateResolutionCible;
    }

    public function getDateResolution(): ?DateTimeImmutable
    {
        return $this->dateResolution;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function getCommentaireResolution(): ?string
    {
        return $this->commentaireResolution;
    }

    public function getStatut(): DossierStatut
    {
        return $this->statut;
    }

    public function getMontantTotal(): ?string
    {
        return $this->montantTotal;
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
     * @return Collection<int, DossierEcriture>
     */
    public function getEcritures(): Collection
    {
        return $this->ecritures;
    }

    /**
     * @return Collection<int, Echeance>
     */
    public function getEcheances(): Collection
    {
        return $this->echeances;
    }
}
