<?php

declare(strict_types=1);

namespace App\Livraison\Entity;

use App\Livraison\Enum\TypePiece;
use App\Livraison\Repository\PieceRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Piece jointe a une declaration de livraison : PV de livraison, bon de commande, CPI.
 *
 * Contenu stocke EN BASE (bytea) et non sur disque : sur Render le web et le worker
 * sont deux services sans disque partage, la base est le seul stockage commun, et le
 * disque d'un service Render est ephemere. Meme choix que le module Remboursement.
 */
#[ORM\Entity(repositoryClass: PieceRepository::class)]
#[ORM\Table(name: 'piece', schema: 'livraison')]
#[ORM\UniqueConstraint(name: 'uniq_livr_piece_type', columns: ['declaration_id', 'type'])]
#[ORM\Index(name: 'idx_livr_piece_decl', columns: ['declaration_id'])]
class Piece
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Declaration::class, inversedBy: 'pieces')]
    #[ORM\JoinColumn(name: 'declaration_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Declaration $declaration;

    #[ORM\Column(length: 24, enumType: TypePiece::class)]
    private TypePiece $type;

    /**
     * Contenu binaire. Doctrine renvoie une RESSOURCE stream en lecture, normalisee
     * en chaine par getContenu().
     *
     * @var resource|string
     */
    #[ORM\Column(type: Types::BLOB)]
    private $contenu;

    #[ORM\Column(name: 'nom_fichier', length: 255)]
    private string $nomFichier;

    #[ORM\Column(name: 'mime_type', length: 100)]
    private string $mimeType;

    #[ORM\Column(name: 'taille_octets', type: 'integer')]
    private int $tailleOctets;

    /** Empreinte SHA-256 du contenu : integrite, et detection de piece identique. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(name: 'uploade_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $uploadeLe;

    public function __construct(
        Declaration $declaration,
        TypePiece $type,
        string $contenu,
        string $nomFichier,
        string $mimeType,
    ) {
        $this->declaration = $declaration;
        $this->type = $type;
        $this->contenu = $contenu;
        $this->nomFichier = $nomFichier;
        $this->mimeType = $mimeType;
        $this->tailleOctets = \strlen($contenu);
        $this->hash = hash('sha256', $contenu);
        $this->uploadeLe = new DateTimeImmutable();
        $declaration->ajouterPiece($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeclaration(): Declaration
    {
        return $this->declaration;
    }

    public function getType(): TypePiece
    {
        return $this->type;
    }

    /** Contenu binaire, normalise en chaine (Doctrine peut renvoyer une ressource). */
    public function getContenu(): string
    {
        if (\is_resource($this->contenu)) {
            rewind($this->contenu);

            return (string) stream_get_contents($this->contenu);
        }

        return (string) $this->contenu;
    }

    public function getNomFichier(): string
    {
        return $this->nomFichier;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getTailleOctets(): int
    {
        return $this->tailleOctets;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function getUploadeLe(): DateTimeImmutable
    {
        return $this->uploadeLe;
    }
}
