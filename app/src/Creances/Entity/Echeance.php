<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\EcheanceStatut;
use App\Creances\Repository\EcheanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Echeance d'un dossier de type echeancier : plan de paiement negocie avec
 * le client. Chaque echeance porte une date et un montant ; on suit son
 * reglement (partiel/integral) via montantRegle + dateReglement.
 */
#[ORM\Entity(repositoryClass: EcheanceRepository::class)]
#[ORM\Table(name: 'echeance', schema: 'creances')]
class Echeance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'echeances')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    #[ORM\Column(type: 'integer')]
    private int $numero;

    #[ORM\Column(name: 'date_prevue', type: 'date_immutable')]
    private DateTimeImmutable $datePrevue;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montant;

    #[ORM\Column(name: 'montant_regle', type: 'decimal', precision: 12, scale: 2)]
    private string $montantRegle = '0.00';

    #[ORM\Column(name: 'date_reglement', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $dateReglement = null;

    #[ORM\Column(length: 20, enumType: EcheanceStatut::class)]
    private EcheanceStatut $statut = EcheanceStatut::AVenir;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le')]
    private DateTimeImmutable $modifieLe;

    public function __construct(
        Dossier $dossier,
        int $numero,
        DateTimeImmutable $datePrevue,
        string $montant,
        ?string $commentaire = null,
    ) {
        $this->dossier = $dossier;
        $this->numero = $numero;
        $this->datePrevue = $datePrevue;
        $this->montant = $montant;
        $this->commentaire = $commentaire;
        $now = new DateTimeImmutable();
        $this->creeLe = $now;
        $this->modifieLe = $now;
    }

    /**
     * Recalcule le statut a partir du montant regle et de la date prevue.
     * Appele apres chaque modification de montantRegle / dateReglement.
     */
    public function recalculerStatut(): void
    {
        $regle = (float) $this->montantRegle;
        $du = (float) $this->montant;

        if ($regle >= $du) {
            $this->statut = EcheanceStatut::Regle;
        } elseif ($regle > 0) {
            $this->statut = EcheanceStatut::Partiel;
        } elseif ($this->datePrevue < new DateTimeImmutable('today')) {
            $this->statut = EcheanceStatut::EnRetard;
        } else {
            $this->statut = EcheanceStatut::AVenir;
        }

        $this->modifieLe = new DateTimeImmutable();
    }

    public function regler(string $montant, ?DateTimeImmutable $date = null): void
    {
        $this->montantRegle = $montant;
        $this->dateReglement = $date ?? new DateTimeImmutable();
        $this->recalculerStatut();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function getDatePrevue(): DateTimeImmutable
    {
        return $this->datePrevue;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function getMontantRegle(): string
    {
        return $this->montantRegle;
    }

    public function getMontantRestant(): string
    {
        $restant = (float) $this->montant - (float) $this->montantRegle;

        return number_format(max(0.0, $restant), 2, '.', '');
    }

    public function getDateReglement(): ?DateTimeImmutable
    {
        return $this->dateReglement;
    }

    public function getStatut(): EcheanceStatut
    {
        return $this->statut;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): DateTimeImmutable
    {
        return $this->modifieLe;
    }
}
