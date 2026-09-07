<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\ModeleFormat;
use App\Creances\Repository\ModeleCourrierRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modele de courrier (email ou postal). Le corps est en HTML Twig avec
 * variables `tiers`, `compte`, `ecritures`, `montant_total`, etc. resolues
 * au moment du rendu.
 */
#[ORM\Entity(repositoryClass: ModeleCourrierRepository::class)]
#[ORM\Table(name: 'modele_courrier', schema: 'creances')]
class ModeleCourrier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: ModeleFormat::class)]
    private ModeleFormat $format = ModeleFormat::Email;

    #[ORM\Column(length: 5)]
    private string $langue = 'fr';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(name: 'corps_html', type: 'text')]
    private string $corpsHtml;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    public function __construct(
        string $code,
        string $libelle,
        string $corpsHtml,
        ?User $auteur,
        ModeleFormat $format = ModeleFormat::Email,
        ?string $sujet = null,
        string $langue = 'fr',
    ) {
        $this->code = $code;
        $this->libelle = $libelle;
        $this->corpsHtml = $corpsHtml;
        $this->auteur = $auteur;
        $this->format = $format;
        $this->sujet = $sujet;
        $this->langue = $langue;
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

    public function setSujet(?string $sujet): void
    {
        $this->sujet = $sujet;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setCorpsHtml(string $corps): void
    {
        $this->corpsHtml = $corps;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setFormat(ModeleFormat $format): void
    {
        $this->format = $format;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setLangue(string $langue): void
    {
        $this->langue = $langue;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getFormat(): ModeleFormat
    {
        return $this->format;
    }

    public function getLangue(): string
    {
        return $this->langue;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function getCorpsHtml(): string
    {
        return $this->corpsHtml;
    }

    public function isActif(): bool
    {
        return $this->actif;
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
