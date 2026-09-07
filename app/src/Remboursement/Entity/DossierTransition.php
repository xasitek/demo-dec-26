<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierTransitionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal INVIOLABLE des changements d'etat d'un dossier (audit). Une ligne par
 * transition franchie : qui, quand, depuis/vers quel etat, via quelle transition.
 * En ecriture seule (jamais modifie/supprime). Remplace la colonne "Historique"
 * du Sheet et trace la responsabilite (validation directeur, confirmation, etc.).
 */
#[ORM\Entity(repositoryClass: DossierTransitionRepository::class)]
#[ORM\Table(name: 'dossier_transition', schema: 'remboursement')]
#[ORM\Index(name: 'idx_remb_transition_dossier', columns: ['dossier_id'])]
class DossierTransition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class)]
    #[ORM\JoinColumn(name: 'dossier_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    #[ORM\Column(name: 'de_statut', length: 24, nullable: true, enumType: DossierStatut::class)]
    private ?DossierStatut $deStatut;

    #[ORM\Column(name: 'vers_statut', length: 24, enumType: DossierStatut::class)]
    private DossierStatut $versStatut;

    #[ORM\Column(length: 64)]
    private string $transition;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $par;

    #[ORM\Column(name: 'le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $le;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire;

    public function __construct(
        Dossier $dossier,
        ?DossierStatut $deStatut,
        DossierStatut $versStatut,
        string $transition,
        ?string $par = null,
        ?string $commentaire = null,
    ) {
        $this->dossier = $dossier;
        $this->deStatut = $deStatut;
        $this->versStatut = $versStatut;
        $this->transition = $transition;
        $this->par = $par;
        $this->commentaire = $commentaire;
        $this->le = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getDeStatut(): ?DossierStatut
    {
        return $this->deStatut;
    }

    public function getVersStatut(): DossierStatut
    {
        return $this->versStatut;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getPar(): ?string
    {
        return $this->par;
    }

    public function getLe(): DateTimeImmutable
    {
        return $this->le;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }
}
