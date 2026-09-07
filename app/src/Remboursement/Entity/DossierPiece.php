<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Repository\DossierPieceRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Piece justificative d'un dossier (RIB, facture VO, carte grise, releve ICAR...),
 * STOCKEE PAR L'APPLICATION (fin de Google Drive, cf. decision PO). Le fichier vit
 * sur le stockage applicatif (chemin relatif) ; la table porte les metadonnees +
 * l'empreinte (integrite). `type` est une cle de DossierMotif::piecesRequises().
 */
#[ORM\Entity(repositoryClass: DossierPieceRepository::class)]
#[ORM\Table(name: 'dossier_piece', schema: 'remboursement')]
#[ORM\Index(name: 'idx_remb_piece_dossier', columns: ['dossier_id'])]
class DossierPiece
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class)]
    #[ORM\JoinColumn(name: 'dossier_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    /** Cle de type (cf. DossierMotif::piecesRequises), ex. 'rib', 'carte_grise'. */
    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(name: 'nom_fichier', length: 255)]
    private string $nomFichier;

    /**
     * Contenu binaire de la piece, stocke EN BASE (bytea) : le worker et le web sont
     * des services Render distincts sans disque partage. Doctrine renvoie une RESSOURCE
     * stream en lecture -> normalisee en string par getContenu().
     *
     * @var resource|string
     */
    #[ORM\Column(type: Types::BLOB)]
    private $contenu;

    #[ORM\Column(name: 'mime_type', length: 100)]
    private string $mimeType;

    #[ORM\Column(name: 'taille_octets', type: 'integer')]
    private int $tailleOctets;

    /** Empreinte SHA-256 du contenu (integrite / anti-doublon de piece). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(name: 'uploade_par', length: 150, nullable: true)]
    private ?string $uploadePar = null;

    #[ORM\Column(name: 'uploade_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $uploadeLe;

    public function __construct(
        Dossier $dossier,
        string $type,
        string $nomFichier,
        string $contenu,
        string $mimeType,
        int $tailleOctets,
        ?string $hash = null,
        ?string $uploadePar = null,
    ) {
        $this->dossier = $dossier;
        $this->type = $type;
        $this->nomFichier = $nomFichier;
        $this->contenu = $contenu;
        $this->mimeType = $mimeType;
        $this->tailleOctets = $tailleOctets;
        $this->hash = $hash;
        $this->uploadePar = $uploadePar;
        $this->uploadeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getNomFichier(): string
    {
        return $this->nomFichier;
    }

    /** Contenu binaire, normalise en string (Doctrine peut renvoyer une ressource stream). */
    public function getContenu(): string
    {
        if (\is_resource($this->contenu)) {
            rewind($this->contenu);

            return (string) stream_get_contents($this->contenu);
        }

        return (string) $this->contenu;
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

    public function getUploadePar(): ?string
    {
        return $this->uploadePar;
    }

    public function getUploadeLe(): DateTimeImmutable
    {
        return $this->uploadeLe;
    }
}
