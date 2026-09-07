<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Repository\FactureSiteRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Etat d'une facture (ecriture) vis-a-vis du « gel » de relance :
 *   - `relance_site`    : la facture est transferee au SITE (l'etablissement doit agir) ;
 *   - `ne_pas_relancer` : la facture est simplement mise en pause.
 *
 * Tant que la ligne est ACTIVE, la facture est EXCLUE de la relance CLIENT (le moteur
 * de selection filtre dessus). Les autres factures du compte continuent. Pivot =
 * `ecriture_id` (meme cle que recouvrement.relance_envoi). Voir
 * docs/RECOUVREMENT_RELANCE_SITE.md (Phase 1).
 */
#[ORM\Entity(repositoryClass: FactureSiteRepository::class)]
#[ORM\Table(name: 'facture_site', schema: 'recouvrement')]
#[ORM\UniqueConstraint(name: 'uniq_facture_site_ecriture', columns: ['ecriture_id'])]
#[ORM\Index(name: 'idx_facture_site_compte', columns: ['compte_code'])]
class FactureSite
{
    public const TYPE_SITE = 'relance_site';
    public const TYPE_NE_PAS_RELANCER = 'ne_pas_relancer';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_SITE => 'Relance site',
        self::TYPE_NE_PAS_RELANCER => 'Ne pas relancer',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'ecriture_id', length: 64)]
    private string $ecritureId;

    #[ORM\Column(name: 'compte_code', length: 64)]
    private string $compteCode;

    /** Etablissement destinataire (codeetab de la facture) ; null pour ne_pas_relancer. */
    #[ORM\Column(name: 'code_site', length: 8, nullable: true)]
    private ?string $codeSite = null;

    /** Dossier de relance site auquel la facture est rattachee (null si ne_pas_relancer). */
    #[ORM\Column(name: 'demande_site_id', nullable: true)]
    private ?int $demandeSiteId = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column]
    private bool $actif = true;

    // Snapshot au moment du gel (la facture peut etre remplacee/corrigee dans Progiciel).
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $montant = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'date_echeance', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dateEcheance = null;

    /** Message libre de la comptable au SITE (contexte / action attendue), repris dans l'e-mail de relance site et la page de reponse. */
    #[ORM\Column(name: 'note_site', type: Types::TEXT, nullable: true)]
    private ?string $noteSite = null;

    // Prochaine relance (reserve : rempli a la resolution, Phases suivantes).
    #[ORM\Column(name: 'prochaine_relance_cible', length: 10, nullable: true)]
    private ?string $prochaineRelanceCible = null;

    #[ORM\Column(name: 'prochaine_relance_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $prochaineRelanceDate = null;

    #[ORM\Column(name: 'retour_id', nullable: true)]
    private ?int $retourId = null;

    #[ORM\Column(name: 'cree_par', length: 150, nullable: true)]
    private ?string $creePar = null;

    #[ORM\Column(name: 'cree_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_par', length: 150, nullable: true)]
    private ?string $modifiePar = null;

    #[ORM\Column(name: 'modifie_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    public function __construct(string $ecritureId, string $compteCode, string $type, ?string $creePar = null)
    {
        $this->ecritureId = $ecritureId;
        $this->compteCode = $compteCode;
        $this->type = \array_key_exists($type, self::TYPES) ? $type : self::TYPE_NE_PAS_RELANCER;
        $this->creePar = $creePar;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEcritureId(): string
    {
        return $this->ecritureId;
    }

    public function getCompteCode(): string
    {
        return $this->compteCode;
    }

    public function getCodeSite(): ?string
    {
        return $this->codeSite;
    }

    public function setCodeSite(?string $codeSite): void
    {
        $this->codeSite = $codeSite;
    }

    public function getDemandeSiteId(): ?int
    {
        return $this->demandeSiteId;
    }

    public function setDemandeSiteId(?int $demandeSiteId): void
    {
        $this->demandeSiteId = $demandeSiteId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTypeLibelle(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function isSite(): bool
    {
        return self::TYPE_SITE === $this->type;
    }

    /** (Re)gele la facture avec le type donne. */
    public function geler(string $type, ?string $codeSite, ?string $par): void
    {
        $this->type = \array_key_exists($type, self::TYPES) ? $type : self::TYPE_NE_PAS_RELANCER;
        $this->codeSite = self::TYPE_SITE === $this->type ? $codeSite : null;
        $this->actif = true;
        $this->touch($par);
    }

    /** Degele : la facture repart en relance client. */
    public function reactiver(?string $par): void
    {
        $this->actif = false;
        $this->touch($par);
    }

    public function setSnapshot(?string $montant, ?string $reference, ?DateTimeImmutable $dateEcheance): void
    {
        $this->montant = $montant;
        $this->reference = $reference;
        $this->dateEcheance = $dateEcheance;
    }

    public function getMontant(): ?string
    {
        return $this->montant;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getDateEcheance(): ?DateTimeImmutable
    {
        return $this->dateEcheance;
    }

    public function getNoteSite(): ?string
    {
        return $this->noteSite;
    }

    public function setNoteSite(?string $noteSite): void
    {
        $noteSite = null !== $noteSite ? trim($noteSite) : '';
        $this->noteSite = '' !== $noteSite ? $noteSite : null;
    }

    public function getProchaineRelanceCible(): ?string
    {
        return $this->prochaineRelanceCible;
    }

    public function getProchaineRelanceDate(): ?DateTimeImmutable
    {
        return $this->prochaineRelanceDate;
    }

    public function definirProchaineRelance(?string $cible, ?DateTimeImmutable $date, ?string $par): void
    {
        $this->prochaineRelanceCible = $cible;
        $this->prochaineRelanceDate = $date;
        $this->touch($par);
    }

    public function getRetourId(): ?int
    {
        return $this->retourId;
    }

    public function setRetourId(?int $retourId): void
    {
        $this->retourId = $retourId;
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
