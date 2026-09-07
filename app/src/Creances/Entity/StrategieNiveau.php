<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\ActionType;
use App\Creances\Repository\StrategieNiveauRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une etape d'une strategie de relance. Le delai est exprime en jours
 * depuis la date d'echeance de l'ecriture (negatif = pre-relance).
 */
#[ORM\Entity(repositoryClass: StrategieNiveauRepository::class)]
#[ORM\Table(name: 'strategie_niveau', schema: 'creances')]
class StrategieNiveau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Strategie::class, inversedBy: 'niveaux')]
    #[ORM\JoinColumn(name: 'strategie_id', nullable: false, onDelete: 'CASCADE')]
    private Strategie $strategie;

    #[ORM\Column(type: 'integer')]
    private int $ordre;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(name: 'jours_delai', type: 'integer')]
    private int $joursDelai = 0;

    #[ORM\Column(name: 'type_action', length: 30, enumType: ActionType::class)]
    private ActionType $typeAction;

    /**
     * Pointe (logiquement) vers `creances.modele_courrier.id`. La FK sera
     * ajoutee par la migration de la phase 7 ; pour l'instant on garde un
     * simple BIGINT pour ne pas bloquer le module.
     */
    #[ORM\Column(name: 'modele_courrier_id', type: 'bigint', nullable: true)]
    private ?int $modeleCourrierId = null;

    #[ORM\Column(name: 'condition_libelle', length: 255, nullable: true)]
    private ?string $conditionLibelle = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(
        Strategie $strategie,
        int $ordre,
        string $libelle,
        ActionType $typeAction,
        int $joursDelai = 0,
        ?string $conditionLibelle = null,
    ) {
        $this->strategie = $strategie;
        $this->ordre = $ordre;
        $this->libelle = $libelle;
        $this->typeAction = $typeAction;
        $this->joursDelai = $joursDelai;
        $this->conditionLibelle = $conditionLibelle;
        $this->creeLe = new DateTimeImmutable();
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function setJoursDelai(int $jours): void
    {
        $this->joursDelai = $jours;
    }

    public function setTypeAction(ActionType $type): void
    {
        $this->typeAction = $type;
    }

    public function setConditionLibelle(?string $libelle): void
    {
        $this->conditionLibelle = $libelle;
    }

    public function setModeleCourrierId(?int $id): void
    {
        $this->modeleCourrierId = $id;
    }

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStrategie(): Strategie
    {
        return $this->strategie;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getJoursDelai(): int
    {
        return $this->joursDelai;
    }

    public function getTypeAction(): ActionType
    {
        return $this->typeAction;
    }

    public function getModeleCourrierId(): ?int
    {
        return $this->modeleCourrierId;
    }

    public function getConditionLibelle(): ?string
    {
        return $this->conditionLibelle;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
