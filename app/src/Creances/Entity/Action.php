<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\ActionResultat;
use App\Creances\Enum\ActionType;
use App\Creances\Repository\ActionRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une action de recouvrement : point de contact a faire (telephone, e-mail,
 * courrier, sms, visite) avec un client. Optionnellement attachee a une
 * ecriture precise du mirror Progiciel.
 *
 * Lorsque realisee = true, on stocke le resultat + un commentaire de cloture.
 */
#[ORM\Entity(repositoryClass: ActionRepository::class)]
#[ORM\Table(name: 'action', schema: 'creances')]
class Action
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50)]
    private string $compteCode;

    #[ORM\Column(name: 'ecriture_numero', length: 50, nullable: true)]
    private ?string $ecritureNumero = null;

    #[ORM\Column(length: 30, enumType: ActionType::class)]
    private ActionType $type;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $echeance;

    #[ORM\Column]
    private bool $realisee = false;

    #[ORM\Column(length: 30, enumType: ActionResultat::class, nullable: true)]
    private ?ActionResultat $resultat = null;

    #[ORM\Column(name: 'commentaire_resultat', type: 'text', nullable: true)]
    private ?string $commentaireResultat = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'destinataire_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $destinataire = null;

    #[ORM\Column(name: 'realisee_le', nullable: true)]
    private ?DateTimeImmutable $realiseeLe = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    public function __construct(
        string $compteCode,
        ActionType $type,
        string $libelle,
        DateTimeImmutable $echeance,
        ?User $auteur,
        ?User $destinataire = null,
        ?string $ecritureNumero = null,
        ?string $description = null,
    ) {
        $this->compteCode = $compteCode;
        $this->type = $type;
        $this->libelle = $libelle;
        $this->echeance = $echeance;
        $this->auteur = $auteur;
        $this->destinataire = $destinataire;
        $this->ecritureNumero = $ecritureNumero;
        $this->description = $description;
        $now = new DateTimeImmutable();
        $this->creeLe = $now;
        $this->modifieLe = $now;
    }

    public function cloturer(ActionResultat $resultat, ?string $commentaire = null): void
    {
        $this->resultat = $resultat;
        $this->commentaireResultat = $commentaire;
        $this->realisee = true;
        $this->realiseeLe = new DateTimeImmutable();
        $this->modifieLe = $this->realiseeLe;
    }

    public function rouvrir(): void
    {
        $this->resultat = null;
        $this->commentaireResultat = null;
        $this->realisee = false;
        $this->realiseeLe = null;
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

    public function getEcritureNumero(): ?string
    {
        return $this->ecritureNumero;
    }

    public function getType(): ActionType
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

    public function getEcheance(): DateTimeImmutable
    {
        return $this->echeance;
    }

    public function isRealisee(): bool
    {
        return $this->realisee;
    }

    public function getResultat(): ?ActionResultat
    {
        return $this->resultat;
    }

    public function getCommentaireResultat(): ?string
    {
        return $this->commentaireResultat;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getDestinataire(): ?User
    {
        return $this->destinataire;
    }

    public function getRealiseeLe(): ?DateTimeImmutable
    {
        return $this->realiseeLe;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function isEnRetard(): bool
    {
        if ($this->realisee) {
            return false;
        }

        return $this->echeance < new DateTimeImmutable('today');
    }
}
