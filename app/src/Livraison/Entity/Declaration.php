<?php

declare(strict_types=1);

namespace App\Livraison\Entity;

use App\Livraison\Enum\TypePiece;
use App\Livraison\Repository\DeclarationRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Declaration de livraison : une secretaire atteste qu'un vehicule a ete livre et
 * joint les pieces du dossier.
 *
 * C'est le seul apport humain du circuit. Tout le reste — le loueur, l'etablissement,
 * les factures, les soldes — est derive de `livraison.v_a_livrer`, donc de la
 * comptabilite. Les champs `loueur`, `codePayeur` et `codeEtab` sont recopies ici a
 * la declaration : ce sont des faits datant du jour du depot, qu'une ecriture
 * disparue de Progiciel ne doit pas effacer.
 *
 * Pas de cle etrangere vers `mirror.*` : le miroir est en soft-delete, une ecriture
 * peut disparaitre sans que la declaration cesse d'exister.
 */
#[ORM\Entity(repositoryClass: DeclarationRepository::class)]
#[ORM\Table(name: 'declaration', schema: 'livraison')]
#[ORM\UniqueConstraint(name: 'uniq_livr_decl_vehicule', columns: ['identifiant_vehicule'])]
#[ORM\Index(name: 'idx_livr_decl_etab', columns: ['code_etab'])]
#[ORM\Index(name: 'idx_livr_decl_par', columns: ['declaree_par'])]
#[ORM\Index(name: 'idx_livr_decl_statut', columns: ['statut'])]
class Declaration
{
    /** Deposee par la secretaire, en attente du controle comptable. */
    public const STATUT_DEPOSEE = 'deposee';

    /** Controlee et jugee conforme : le dossier peut partir chez le loueur. */
    public const STATUT_CONFORME = 'conforme';

    /**
     * Controlee, des anomalies ont ete retenues : le dossier ne part pas et la
     * secretaire doit corriger. Le circuit precedent laissait par defaut
     * « Conforme » en l'absence d'anomalie connue — ici l'etat est toujours explicite.
     */
    public const STATUT_ANOMALIE = 'anomalie';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * Cle de rapprochement avec la vue : l'immatriculation, ou les 8 derniers
     * caracteres du VIN quand le vehicule n'est pas encore immatricule.
     */
    #[ORM\Column(name: 'identifiant_vehicule', length: 32)]
    private string $identifiantVehicule;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $immatriculation = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(length: 64)]
    private string $loueur;

    #[ORM\Column(name: 'code_payeur', length: 32)]
    private string $codePayeur;

    #[ORM\Column(name: 'code_etab', length: 8)]
    private string $codeEtab;

    #[ORM\Column(name: 'declaree_par', length: 180)]
    private string $declareePar;

    #[ORM\Column(name: 'declaree_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $declareeLe;

    #[ORM\Column(length: 24)]
    private string $statut = self::STATUT_DEPOSEE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(name: 'controlee_par', length: 180, nullable: true)]
    private ?string $controleePar = null;

    #[ORM\Column(name: 'controlee_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $controleeLe = null;

    /** @var Collection<int, Piece> */
    #[ORM\OneToMany(targetEntity: Piece::class, mappedBy: 'declaration', cascade: ['persist', 'remove'])]
    private Collection $pieces;

    public function __construct(
        string $identifiantVehicule,
        string $loueur,
        string $codePayeur,
        string $codeEtab,
        string $declareePar,
        ?string $immatriculation = null,
        ?string $vin = null,
    ) {
        $this->identifiantVehicule = $identifiantVehicule;
        $this->loueur = $loueur;
        $this->codePayeur = $codePayeur;
        $this->codeEtab = $codeEtab;
        $this->declareePar = $declareePar;
        $this->immatriculation = $immatriculation;
        $this->vin = $vin;
        $this->declareeLe = new DateTimeImmutable();
        $this->pieces = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifiantVehicule(): string
    {
        return $this->identifiantVehicule;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function getVin(): ?string
    {
        return $this->vin;
    }

    public function getLoueur(): string
    {
        return $this->loueur;
    }

    public function getCodePayeur(): string
    {
        return $this->codePayeur;
    }

    public function getCodeEtab(): string
    {
        return $this->codeEtab;
    }

    public function getDeclareePar(): string
    {
        return $this->declareePar;
    }

    public function getDeclareeLe(): DateTimeImmutable
    {
        return $this->declareeLe;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): void
    {
        $this->commentaire = '' === $commentaire ? null : $commentaire;
    }

    /** @return Collection<int, Piece> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function ajouterPiece(Piece $piece): void
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
        }
    }

    /** La piece d'un type donne, ou null si elle n'a pas ete jointe. */
    public function piece(TypePiece $type): ?Piece
    {
        foreach ($this->pieces as $piece) {
            if ($piece->getType() === $type) {
                return $piece;
            }
        }

        return null;
    }

    public function getControleePar(): ?string
    {
        return $this->controleePar;
    }

    public function getControleeLe(): ?DateTimeImmutable
    {
        return $this->controleeLe;
    }

    public function estControlee(): bool
    {
        return self::STATUT_DEPOSEE !== $this->statut;
    }

    /** Le dossier est conforme : il peut partir chez le loueur. */
    public function marquerConforme(string $par): void
    {
        $this->statut = self::STATUT_CONFORME;
        $this->controleePar = $par;
        $this->controleeLe = new DateTimeImmutable();
    }

    /** Des anomalies ont ete retenues : le dossier ne part pas en l'etat. */
    public function marquerAnomalie(string $par): void
    {
        $this->statut = self::STATUT_ANOMALIE;
        $this->controleePar = $par;
        $this->controleeLe = new DateTimeImmutable();
    }

    /** Remet le dossier en attente de controle (annulation d'un arbitrage). */
    public function remettreEnAttente(): void
    {
        $this->statut = self::STATUT_DEPOSEE;
        $this->controleePar = null;
        $this->controleeLe = null;
    }

    /**
     * Ce que la secretaire voit dans « Mes declarations » : l'identifiant lisible du
     * vehicule. L'immatriculation quand elle existe, sinon l'identifiant technique.
     */
    public function libelleVehicule(): string
    {
        return $this->immatriculation ?? $this->identifiantVehicule;
    }
}
