<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Enum\StatutExtraction;
use App\Remboursement\Repository\ExtractionPieceRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'une extraction IA d'une piece (audit immuable + telemetrie cout/latence).
 * Une ligne par piece analysee. Voir docs/MODULE_REMBOURSEMENT.md (4.4.3).
 */
#[ORM\Entity(repositoryClass: ExtractionPieceRepository::class)]
#[ORM\Table(name: 'extraction_piece', schema: 'remboursement')]
#[ORM\Index(name: 'idx_remb_extraction_dossier', columns: ['dossier_id'])]
class ExtractionPiece
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class)]
    #[ORM\JoinColumn(name: 'dossier_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    #[ORM\ManyToOne(targetEntity: DossierPiece::class)]
    #[ORM\JoinColumn(name: 'piece_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DossierPiece $piece;

    #[ORM\Column(name: 'type_piece', length: 32)]
    private string $typePiece;

    #[ORM\Column(length: 20, enumType: StatutExtraction::class)]
    private StatutExtraction $statut = StatutExtraction::EN_ATTENTE;

    #[ORM\Column(name: 'piece_hash', length: 64, nullable: true)]
    private ?string $pieceHash = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $modele = null;

    #[ORM\Column(name: 'gabarit_version', length: 40)]
    private string $gabaritVersion;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'resultat_brut', type: Types::JSON, nullable: true)]
    private ?array $resultatBrut = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'champs_extraits', type: Types::JSON, nullable: true)]
    private ?array $champsExtraits = null;

    #[ORM\Column(name: 'message_erreur', type: Types::TEXT, nullable: true)]
    private ?string $messageErreur = null;

    #[ORM\Column(name: 'tokens_entree', type: Types::INTEGER, nullable: true)]
    private ?int $tokensEntree = null;

    #[ORM\Column(name: 'tokens_sortie', type: Types::INTEGER, nullable: true)]
    private ?int $tokensSortie = null;

    #[ORM\Column(name: 'latence_ms', type: Types::INTEGER, nullable: true)]
    private ?int $latenceMs = null;

    #[ORM\Column(name: 'cree_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'termine_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $termineLe = null;

    public function __construct(Dossier $dossier, ?DossierPiece $piece, string $typePiece, string $gabaritVersion)
    {
        $this->dossier = $dossier;
        $this->piece = $piece;
        $this->typePiece = $typePiece;
        $this->gabaritVersion = $gabaritVersion;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypePiece(): string
    {
        return $this->typePiece;
    }

    public function getStatut(): StatutExtraction
    {
        return $this->statut;
    }

    /** @return array<string, mixed>|null */
    public function getChampsExtraits(): ?array
    {
        return $this->champsExtraits;
    }

    /**
     * @param array<string, mixed> $champs
     * @param array<string, mixed> $brut
     */
    public function reussir(array $champs, array $brut, ?string $provider, ?string $modele, ?int $tokensEntree, ?int $tokensSortie, ?int $latenceMs): void
    {
        $this->statut = StatutExtraction::REUSSIE;
        $this->champsExtraits = $champs;
        $this->resultatBrut = $brut;
        $this->provider = $provider;
        $this->modele = $modele;
        $this->tokensEntree = $tokensEntree;
        $this->tokensSortie = $tokensSortie;
        $this->latenceMs = $latenceMs;
        $this->termineLe = new DateTimeImmutable();
    }

    public function echouer(StatutExtraction $statut, string $message): void
    {
        $this->statut = $statut;
        $this->messageErreur = $message;
        $this->termineLe = new DateTimeImmutable();
    }

    public function definirHash(?string $hash): void
    {
        $this->pieceHash = $hash;
    }
}
