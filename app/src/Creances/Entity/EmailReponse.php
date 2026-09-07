<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\EmailReponseRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reponse recue suite a une relance (e-mail, webhook, ou saisie manuelle).
 */
#[ORM\Entity(repositoryClass: EmailReponseRepository::class)]
#[ORM\Table(name: 'email_reponse', schema: 'creances')]
class EmailReponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RelanceEnvoi::class)]
    #[ORM\JoinColumn(name: 'relance_envoi_id', nullable: true, onDelete: 'SET NULL')]
    private ?RelanceEnvoi $relanceEnvoi = null;

    #[ORM\Column(name: 'compte_code', length: 50)]
    private string $compteCode;

    #[ORM\Column(name: 'ecriture_numero', length: 50, nullable: true)]
    private ?string $ecritureNumero = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $expediteur = null;

    #[ORM\Column(name: 'corps_texte', type: 'text', nullable: true)]
    private ?string $corpsTexte = null;

    #[ORM\Column(name: 'corps_html', type: 'text', nullable: true)]
    private ?string $corpsHtml = null;

    #[ORM\Column(length: 20)]
    private string $source = 'manuel';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $categorie = null;

    #[ORM\Column(name: 'recu_le')]
    private DateTimeImmutable $recuLe;

    #[ORM\Column]
    private bool $traite = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'traite_par_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $traitePar = null;

    #[ORM\Column(name: 'traite_le', nullable: true)]
    private ?DateTimeImmutable $traiteLe = null;

    #[ORM\Column(name: 'commentaire_traitement', type: 'text', nullable: true)]
    private ?string $commentaireTraitement = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(
        string $compteCode,
        string $source = 'manuel',
        ?DateTimeImmutable $recuLe = null,
    ) {
        $this->compteCode = $compteCode;
        $this->source = $source;
        $this->recuLe = $recuLe ?? new DateTimeImmutable();
        $this->creeLe = new DateTimeImmutable();
    }

    public function setRelanceEnvoi(?RelanceEnvoi $envoi): void
    {
        $this->relanceEnvoi = $envoi;
    }

    public function setEcritureNumero(?string $numero): void
    {
        $this->ecritureNumero = $numero;
    }

    public function setSujet(?string $sujet): void
    {
        $this->sujet = $sujet;
    }

    public function setExpediteur(?string $expediteur): void
    {
        $this->expediteur = $expediteur;
    }

    public function setCorpsTexte(?string $texte): void
    {
        $this->corpsTexte = $texte;
    }

    public function setCorpsHtml(?string $html): void
    {
        $this->corpsHtml = $html;
    }

    public function setCategorie(?string $categorie): void
    {
        $this->categorie = $categorie;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): void
    {
        $this->payload = $payload;
    }

    public function traiter(?User $par, ?string $commentaire = null): void
    {
        $this->traite = true;
        $this->traitePar = $par;
        $this->traiteLe = new DateTimeImmutable();
        $this->commentaireTraitement = $commentaire;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelanceEnvoi(): ?RelanceEnvoi
    {
        return $this->relanceEnvoi;
    }

    public function getCompteCode(): string
    {
        return $this->compteCode;
    }

    public function getEcritureNumero(): ?string
    {
        return $this->ecritureNumero;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function getExpediteur(): ?string
    {
        return $this->expediteur;
    }

    public function getCorpsTexte(): ?string
    {
        return $this->corpsTexte;
    }

    public function getCorpsHtml(): ?string
    {
        return $this->corpsHtml;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function getRecuLe(): DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function isTraite(): bool
    {
        return $this->traite;
    }

    public function getTraitePar(): ?User
    {
        return $this->traitePar;
    }

    public function getTraiteLe(): ?DateTimeImmutable
    {
        return $this->traiteLe;
    }

    public function getCommentaireTraitement(): ?string
    {
        return $this->commentaireTraitement;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
