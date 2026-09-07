<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\NoteRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Annotation libre attachee a un compte (compte_code) et optionnellement a
 * une ecriture precise (ecriture_numero).
 *
 * Les comptes et ecritures vivent dans mirror.* (lecture seule) — on ne
 * tient pas de relation Doctrine, juste l'identifiant Progiciel.
 */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'note', schema: 'creances')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50)]
    private string $compteCode;

    #[ORM\Column(name: 'ecriture_numero', length: 50, nullable: true)]
    private ?string $ecritureNumero;

    #[ORM\Column(type: 'text')]
    private string $contenu;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(string $compteCode, string $contenu, ?User $auteur, ?string $ecritureNumero = null)
    {
        $this->compteCode = $compteCode;
        $this->contenu = $contenu;
        $this->auteur = $auteur;
        $this->ecritureNumero = $ecritureNumero;
        $this->creeLe = new DateTimeImmutable();
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

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
