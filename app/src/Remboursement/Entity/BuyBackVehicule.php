<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Repository\BuyBackVehiculeRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * MIRROR local de la base "Buy Back" (engagements de reprise SYNTHAUTO) : copie en lecture
 * seule des vehicules, alimentee par `app:remboursement:import-buyback` depuis la base
 * externe. Sert au CONTROLE ANTI-SURPAIEMENT au depot d'un rachat sec : on compare le
 * montant saisi a l'engagement de reprise TTC (`erTtc`) de la plaque.
 *
 * `immat` = forme CANONIQUE (majuscules, alphanumerique seul, alignee sur
 * NormalisationSaisie / CleDoublon) : cle de recherche indexee, robuste au format saisi.
 */
#[ORM\Entity(repositoryClass: BuyBackVehiculeRepository::class)]
#[ORM\Table(name: 'buyback_vehicule', schema: 'remboursement')]
#[ORM\Index(name: 'idx_remb_buyback_immat', columns: ['immat'])]
class BuyBackVehicule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Immatriculation canonique (maj, alphanumerique) — cle de recherche. */
    #[ORM\Column(length: 32)]
    private string $immat;

    /** Immatriculation au format d'origine (affichage). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $immatriculation = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $marque = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $modele = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contrat = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $financeur = null;

    #[ORM\Column(name: 'type_fi', length: 64, nullable: true)]
    private ?string $typeFi = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $client = null;

    /** Engagement de reprise HT. */
    #[ORM\Column(name: 'er_ht', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $erHt = null;

    /** Engagement de reprise TTC — reference du controle anti-surpaiement. */
    #[ORM\Column(name: 'er_ttc', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $erTtc = null;

    #[ORM\Column(name: 'date_echeance', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dateEcheance = null;

    #[ORM\Column(name: 'km_contrat', nullable: true)]
    private ?int $kmContrat = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(name: 'importe_le', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $importeLe;

    public function __construct(string $immat)
    {
        $this->immat = $immat;
        $this->importeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImmat(): string
    {
        return $this->immat;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function getVin(): ?string
    {
        return $this->vin;
    }

    public function getMarque(): ?string
    {
        return $this->marque;
    }

    public function getModele(): ?string
    {
        return $this->modele;
    }

    public function getContrat(): ?string
    {
        return $this->contrat;
    }

    public function getFinanceur(): ?string
    {
        return $this->financeur;
    }

    public function getTypeFi(): ?string
    {
        return $this->typeFi;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function getErHt(): ?string
    {
        return $this->erHt;
    }

    public function getErTtc(): ?string
    {
        return $this->erTtc;
    }

    public function getDateEcheance(): ?DateTimeImmutable
    {
        return $this->dateEcheance;
    }

    public function getKmContrat(): ?int
    {
        return $this->kmContrat;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function getImporteLe(): DateTimeImmutable
    {
        return $this->importeLe;
    }
}
