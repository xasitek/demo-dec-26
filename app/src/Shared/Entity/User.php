<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Enum\Module;
use App\Shared\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Utilisateur de l'application, authentifie exclusivement via Google OAuth
 * (aucun mot de passe local). Voir docs/SECURITY.md.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users', schema: 'shared')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Rempli a la 1ere connexion Google. Null pour un compte pre-cree par
     * l'admin avant que la personne se soit connectee. Voir docs/SECURITY.md.
     */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $avatarUrl = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Modules metier auxquels l'utilisateur est rattache (valeurs de Module).
     * Determine l'acces aux modules (sauf admin) et le perimetre des notifications.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $modules = [];

    /**
     * Pole d'affectation, uniquement pour les comptables (ROLE_COMPTABLE).
     * Null pour les autres roles.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: PoleComptable::class)]
    private ?PoleComptable $poleComptable = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    /** Apparait dans la pile d'avatars de presence (reglable par le manager). */
    #[ORM\Column(options: ['default' => true])]
    private bool $presenceVisible = true;

    /** Affiche toujours "en ligne", meme deconnecte (reglage personnel). */
    #[ORM\Column(options: ['default' => false])]
    private bool $presenceForceeEnLigne = false;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * Modules metier auxquels l'utilisateur est rattache.
     *
     * @return list<string>
     */
    public function getModules(): array
    {
        return array_values(array_unique($this->modules));
    }

    /**
     * @param list<string> $modules
     */
    public function setModules(array $modules): static
    {
        $this->modules = array_values(array_unique($modules));

        return $this;
    }

    public function aModule(Module $module): bool
    {
        return \in_array($module->value, $this->modules, true);
    }

    public function getPoleComptable(): ?PoleComptable
    {
        return $this->poleComptable;
    }

    public function setPoleComptable(?PoleComptable $poleComptable): static
    {
        $this->poleComptable = $poleComptable;

        return $this;
    }

    /**
     * Vrai si l'utilisateur possede au moins un role metier (au-dela de ROLE_USER),
     * donc s'il est habilite a acceder aux vues de l'application.
     */
    public function isHabilite(): bool
    {
        return [] !== array_diff($this->getRoles(), ['ROLE_USER']);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function isPresenceVisible(): bool
    {
        return $this->presenceVisible;
    }

    public function setPresenceVisible(bool $presenceVisible): static
    {
        $this->presenceVisible = $presenceVisible;

        return $this;
    }

    public function isPresenceForceeEnLigne(): bool
    {
        return $this->presenceForceeEnLigne;
    }

    public function setPresenceForceeEnLigne(bool $presenceForceeEnLigne): static
    {
        $this->presenceForceeEnLigne = $presenceForceeEnLigne;

        return $this;
    }

    /**
     * Identifiant unique de l'utilisateur pour le composant Security.
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new LogicException('Utilisateur sans adresse email.');
        }

        return $this->email;
    }

    /**
     * Aucun secret sensible stocke en clair : rien a effacer.
     */
    public function eraseCredentials(): void
    {
    }
}
