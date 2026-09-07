<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Repository\DemandeSiteRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dossier de « relance au site » : regroupe, pour un couple (compte, établissement),
 * les factures transférées au site (facture_site.type = relance_site). Porte la
 * cadence de rappel, le jeton de la page de réponse, les destinataires réels et le
 * fil d'échange. Un seul dossier par (compte, site) — réouvert au besoin.
 * Voir docs/RECOUVREMENT_RELANCE_SITE.md (Phase 2).
 */
#[ORM\Entity(repositoryClass: DemandeSiteRepository::class)]
#[ORM\Table(name: 'demande_site', schema: 'recouvrement')]
#[ORM\UniqueConstraint(name: 'uniq_demande_site_compte_site', columns: ['compte_code', 'code_site'])]
#[ORM\UniqueConstraint(name: 'uniq_demande_site_token', columns: ['token'])]
class DemandeSite
{
    public const STATUT_OUVERT = 'ouvert';
    public const STATUT_ENVOYE = 'envoye';
    public const STATUT_REPONDU = 'repondu';
    public const STATUT_CLOS = 'clos';

    /** Cadence de rappel (ancrée au lundi, cf. cron Phase 2C). */
    public const CADENCES = [
        '1s' => 'Chaque lundi',
        '2s' => 'Un lundi sur deux',
        '3s' => 'Un lundi sur trois',
        '4s' => 'Un lundi sur quatre',
        'mois' => 'Premier lundi du mois',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 64)]
    private string $compteCode;

    #[ORM\Column(name: 'code_site', length: 8)]
    private string $codeSite;

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_OUVERT;

    #[ORM\Column(name: 'cadence_intervalle', length: 8)]
    private string $cadenceIntervalle = '2s';

    #[ORM\Column(length: 64)]
    private string $token;

    /**
     * Destinataires réels retenus à l'envoi (snapshot) : {to: [...], cc: [...]}.
     *
     * @var array{to: list<string>, cc: list<string>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $destinataires = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(name: 'dernier_envoi_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dernierEnvoiLe = null;

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

    public function __construct(string $compteCode, string $codeSite, string $token, ?string $creePar = null)
    {
        $this->compteCode = $compteCode;
        $this->codeSite = $codeSite;
        $this->token = $token;
        $this->creePar = $creePar;
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

    public function getCodeSite(): string
    {
        return $this->codeSite;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut, ?string $par = null): void
    {
        $this->statut = $statut;
        $this->touch($par);
    }

    public function estClos(): bool
    {
        return self::STATUT_CLOS === $this->statut;
    }

    public function getCadenceIntervalle(): string
    {
        return $this->cadenceIntervalle;
    }

    public function setCadenceIntervalle(string $cadence, ?string $par = null): void
    {
        $this->cadenceIntervalle = \array_key_exists($cadence, self::CADENCES) ? $cadence : '2s';
        $this->touch($par);
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return array{to: list<string>, cc: list<string>}|null
     */
    public function getDestinataires(): ?array
    {
        return $this->destinataires;
    }

    /**
     * @param array{to: list<string>, cc: list<string>} $destinataires
     */
    public function setDestinataires(array $destinataires): void
    {
        $this->destinataires = $destinataires;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): void
    {
        $this->message = $message;
    }

    public function getDernierEnvoiLe(): ?DateTimeImmutable
    {
        return $this->dernierEnvoiLe;
    }

    public function marquerEnvoye(?string $par = null): void
    {
        $this->dernierEnvoiLe = new DateTimeImmutable();
        $this->statut = self::STATUT_ENVOYE;
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
        if (null !== $par) {
            $this->modifiePar = $par;
        }
        $this->modifieLe = new DateTimeImmutable();
    }
}
