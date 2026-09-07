<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Enum\Module;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use App\Shared\Repository\SuggestionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Remontee d'un utilisateur depuis l'ampoule presente sur toutes les pages
 * (boite a idees). Socle transverse : aucun module ne la possede.
 *
 * Le contexte (module, route, url) est capte automatiquement a l'envoi : la
 * valeur d'une remontee tient autant a « depuis quel ecran » qu'a son texte.
 */
#[ORM\Entity(repositoryClass: SuggestionRepository::class)]
#[ORM\Table(name: 'suggestion', schema: 'shared')]
#[ORM\Index(name: 'idx_suggestion_statut', columns: ['statut', 'created_at'])]
#[ORM\Index(name: 'idx_suggestion_auteur', columns: ['auteur_id', 'created_at'])]
#[ORM\Index(name: 'idx_suggestion_mur', columns: ['type', 'nb_votes'])]
class Suggestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Nullable a dessein : ON DELETE SET NULL. Le depart d'un collaborateur ne
     * doit pas emporter le backlog produit avec lui.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    /** Instantane du nom : l'affichage survit a la suppression du compte. */
    #[ORM\Column(length: 180)]
    private string $auteurNom;

    #[ORM\Column(length: 20, enumType: TypeSuggestion::class)]
    private TypeSuggestion $type;

    #[ORM\Column(length: 20, enumType: StatutSuggestion::class)]
    private StatutSuggestion $statut = StatutSuggestion::NOUVELLE;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** Module deduit de l'URL d'envoi (null hors module : tableau de bord, admin). */
    #[ORM\Column(length: 20, nullable: true, enumType: Module::class)]
    private ?Module $module = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $url = null;

    /**
     * Compteur denormalise des votes : le mur se trie par popularite sans un
     * COUNT par ligne. Maintenu en SQL atomique (cf. SuggestionRepository).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $nbVotes = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reponse = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $traiteAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $traitePar = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        User $auteur,
        TypeSuggestion $type,
        string $message,
        ?Module $module = null,
        ?string $route = null,
        ?string $url = null,
    ) {
        $this->auteur = $auteur;
        $this->auteurNom = $auteur->getFullName();
        $this->type = $type;
        $this->message = $message;
        $this->module = $module;
        $this->route = $route;
        $this->url = $url;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getAuteurNom(): string
    {
        return $this->auteurNom;
    }

    public function getType(): TypeSuggestion
    {
        return $this->type;
    }

    public function getStatut(): StatutSuggestion
    {
        return $this->statut;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getModule(): ?Module
    {
        return $this->module;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getNbVotes(): int
    {
        return $this->nbVotes;
    }

    public function getReponse(): ?string
    {
        return $this->reponse;
    }

    public function getTraiteAt(): ?DateTimeImmutable
    {
        return $this->traiteAt;
    }

    public function getTraitePar(): ?string
    {
        return $this->traitePar;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function estSurLeMur(): bool
    {
        return $this->type->surLeMur();
    }

    /**
     * Decision d'un administrateur : nouveau statut, mot d'explication optionnel.
     * Le traitant est fige en texte (comme l'auteur) pour survivre a son compte.
     */
    public function traiter(StatutSuggestion $statut, ?string $reponse, User $administrateur): void
    {
        $this->statut = $statut;
        $this->reponse = '' === trim((string) $reponse) ? null : trim((string) $reponse);
        $this->traiteAt = new DateTimeImmutable();
        $this->traitePar = $administrateur->getFullName();
    }
}
