<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Repository\RetourPieceJointeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Piece jointe d'un retour client (justificatif de paiement, courrier scanne...).
 * Le contenu binaire est stocke en base (BYTEA) ; volume faible (quelques Mo).
 * Supprimee en cascade avec le retour (FK ON DELETE CASCADE).
 */
#[ORM\Entity(repositoryClass: RetourPieceJointeRepository::class)]
#[ORM\Table(name: 'retour_piece_jointe', schema: 'recouvrement')]
#[ORM\Index(name: 'idx_retour_piece_retour', columns: ['retour_id'])]
class RetourPieceJointe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RetourClient::class)]
    #[ORM\JoinColumn(name: 'retour_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RetourClient $retour;

    #[ORM\Column(length: 255)]
    private string $nom;

    #[ORM\Column(name: 'type_mime', length: 180, nullable: true)]
    private ?string $typeMime = null;

    #[ORM\Column(type: 'integer')]
    private int $taille;

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private mixed $contenu;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(RetourClient $retour, string $nom, ?string $typeMime, string $contenu)
    {
        $this->retour = $retour;
        $this->nom = $nom;
        $this->typeMime = $typeMime;
        $this->contenu = $contenu;
        $this->taille = \strlen($contenu);
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRetour(): RetourClient
    {
        return $this->retour;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getTypeMime(): ?string
    {
        return $this->typeMime;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    /**
     * Contenu binaire en chaine (Doctrine hydrate un blob en resource a la lecture).
     */
    public function getContenuBinaire(): string
    {
        if (\is_resource($this->contenu)) {
            $contenu = stream_get_contents($this->contenu);

            return false === $contenu ? '' : $contenu;
        }

        return (string) $this->contenu;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
