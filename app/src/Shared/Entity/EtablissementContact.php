<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\EtablissementContactRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contact e-mail d'un etablissement (directeur, secretaire, autre). Un etablissement
 * peut avoir plusieurs contacts (plusieurs secretaires). Destinataires par defaut des
 * « relances site » (voir docs/RECOUVREMENT_RELANCE_SITE.md). Champs d'audit :
 * qui a cree/modifie et quand.
 */
#[ORM\Entity(repositoryClass: EtablissementContactRepository::class)]
#[ORM\Table(name: 'etablissement_contact', schema: 'shared')]
#[ORM\UniqueConstraint(name: 'uniq_etab_contact_email', columns: ['etablissement_code', 'email'])]
#[ORM\Index(name: 'idx_etab_contact_etab', columns: ['etablissement_code'])]
class EtablissementContact
{
    /** Roles possibles (cle stockee => libelle). */
    public const ROLES = [
        'directeur' => 'Directeur',
        'secretaire' => 'Secrétaire',
        'autre' => 'Autre',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'etablissement_code', referencedColumnName: 'code_etab', nullable: false, onDelete: 'CASCADE')]
    private Etablissement $etablissement;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $role = 'secretaire';

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $creePar = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $modifiePar = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    public function __construct(Etablissement $etablissement, string $email, string $role = 'secretaire', ?string $creePar = null)
    {
        $this->etablissement = $etablissement;
        $this->email = self::normaliserEmail($email);
        $this->role = \array_key_exists($role, self::ROLES) ? $role : 'autre';
        $this->creePar = $creePar;
        $this->creeLe = new DateTimeImmutable();
    }

    public static function normaliserEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEtablissement(): Etablissement
    {
        return $this->etablissement;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email, ?string $par = null): void
    {
        $this->email = self::normaliserEmail($email);
        $this->touch($par);
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getRoleLibelle(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function setRole(string $role, ?string $par = null): void
    {
        $this->role = \array_key_exists($role, self::ROLES) ? $role : 'autre';
        $this->touch($par);
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif, ?string $par = null): void
    {
        $this->actif = $actif;
        $this->touch($par);
    }

    public function getCreePar(): ?string
    {
        return $this->creePar;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifiePar(): ?string
    {
        return $this->modifiePar;
    }

    public function getModifieLe(): ?DateTimeImmutable
    {
        return $this->modifieLe;
    }

    private function touch(?string $par): void
    {
        $this->modifiePar = $par;
        $this->modifieLe = new DateTimeImmutable();
    }
}
