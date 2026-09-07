<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\Chiffrement;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dossier de remboursement client. Agregat central du module, pilote par la
 * machine a etats (WorkflowRemboursement). Remplace une ligne de l'onglet
 * "Validation" du Google Sheet. Voir docs/MODULE_REMBOURSEMENT.md.
 *
 * Deux jeux de champs coexistent :
 *   - BRUTS : saisis par la secretaire (nomClient, ibanClient, montant, immat, icar) ;
 *   - CONTROLES : remplis par l'extraction IA, corrigeables par le comptable.
 * Les IBAN/BIC clients (brut ET controle) sont CHIFFRES au repos (RGPD) via
 * les accesseurs. L'anti-doublon passe par `cleDoublon` (en clair, indexee).
 */
#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Table(name: 'dossier', schema: 'remboursement')]
#[ORM\UniqueConstraint(name: 'uniq_remb_dossier_reference', columns: ['reference'])]
#[ORM\Index(name: 'idx_remb_dossier_statut', columns: ['statut'])]
#[ORM\Index(name: 'idx_remb_dossier_cle_doublon', columns: ['motif', 'cle_doublon'])]
#[ORM\Index(name: 'idx_remb_dossier_etab', columns: ['etablissement_code'])]
// Colonnes chaudes de filtre/tri (journal, virements, mes dossiers, badge secretaire).
#[ORM\Index(name: 'idx_remb_dossier_sepa_tel', columns: ['sepa_telecharge_le'])]
#[ORM\Index(name: 'idx_remb_dossier_cree_par', columns: ['cree_par'])]
#[ORM\Index(name: 'idx_remb_dossier_valide_dir', columns: ['valide_directeur_le'])]
// Index aveugle : detection "meme compte bancaire" (meme IBAN) entre dossiers.
#[ORM\Index(name: 'idx_remb_dossier_iban_hash', columns: ['iban_hash'])]
class Dossier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: 16, enumType: DossierMotif::class)]
    private DossierMotif $motif;

    #[ORM\Column(length: 24, enumType: DossierStatut::class)]
    private DossierStatut $statut = DossierStatut::BROUILLON;

    /** Etablissement debiteur (shared.etablissement.code_etab). */
    #[ORM\Column(name: 'etablissement_code', length: 8, nullable: true)]
    private ?string $etablissementCode = null;

    // --- Champs BRUTS (saisie secretaire) ---

    #[ORM\Column(length: 180)]
    private string $nomClient = '';

    /** IBAN client — CHIFFRE au repos (accesseurs). */
    #[ORM\Column(name: 'iban_client', type: Types::TEXT, nullable: true)]
    private ?string $ibanClient = null;

    /** BIC client — CHIFFRE au repos. */
    #[ORM\Column(name: 'bic_client', type: Types::TEXT, nullable: true)]
    private ?string $bicClient = null;

    /**
     * Adresse FACULTATIVE mise en copie de l'attestation de paiement (saisie au depot).
     *
     * EN CLAIR, contrairement a l'IBAN et au BIC voisins : les memes adresses vivent
     * dans le carnet de la secretaire (remboursement.email_favori), ou elles doivent
     * rester comparables et filtrables en SQL. Les chiffrer ici ne protegerait donc
     * rien. La table porte deja `cree_par`, l'adresse de la secretaire, en clair.
     */
    #[ORM\Column(name: 'email_copie', length: 190, nullable: true)]
    private ?string $emailCopie = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $immatriculation = null;

    #[ORM\Column(name: 'code_icar', length: 32, nullable: true)]
    private ?string $codeIcar = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $montant = '0.00';

    // --- Champs CONTROLES (extraction IA, corrigeables par le comptable) ---

    #[ORM\Column(name: 'controle_nom', length: 180, nullable: true)]
    private ?string $controleNom = null;

    #[ORM\Column(name: 'controle_iban', type: Types::TEXT, nullable: true)]
    private ?string $controleIban = null;

    #[ORM\Column(name: 'controle_bic', type: Types::TEXT, nullable: true)]
    private ?string $controleBic = null;

    #[ORM\Column(name: 'controle_montant', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $controleMontant = null;

    #[ORM\Column(name: 'controle_immatriculation', length: 32, nullable: true)]
    private ?string $controleImmatriculation = null;

    #[ORM\Column(name: 'controle_icar', length: 32, nullable: true)]
    private ?string $controleIcar = null;

    /** Extractions IA complementaires par piece (carte grise, releve ICAR, etc.). */
    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'controle_extra', type: Types::JSON, nullable: true)]
    private ?array $controleExtra = null;

    /** Verdict global indicatif de l'IA : 'valide' / 'invalide' / null. Ne debloque JAMAIS seul un paiement. */
    #[ORM\Column(name: 'verdict_ia', length: 16, nullable: true)]
    private ?string $verdictIa = null;

    #[ORM\Column(name: 'verdict_info', type: Types::TEXT, nullable: true)]
    private ?string $verdictInfo = null;

    /** Cle anti-doublon EN CLAIR (rachat = immat normalisee ; TP = ICAR normalise). Cf. arbitrage PO. */
    #[ORM\Column(name: 'cle_doublon', length: 64, nullable: true)]
    private ?string $cleDoublon = null;

    /** Empreinte DETERMINISTE de l'IBAN retenu (index aveugle) : detecte "meme compte
     *  bancaire" entre dossiers sans stocker l'IBAN en clair. Recalculee a chaque changement. */
    #[ORM\Column(name: 'iban_hash', length: 64, nullable: true)]
    private ?string $ibanHash = null;

    #[ORM\Column(name: 'refus_motif', type: Types::TEXT, nullable: true)]
    private ?string $refusMotif = null;

    // --- Champs VALIDES (valeur validee par le comptable ; base du paiement SEPA) ---

    #[ORM\Column(name: 'valide_nom', length: 180, nullable: true)]
    private ?string $valideNom = null;

    /** IBAN valide — CHIFFRE au repos. */
    #[ORM\Column(name: 'valide_iban', type: Types::TEXT, nullable: true)]
    private ?string $valideIban = null;

    /** BIC valide — CHIFFRE au repos. */
    #[ORM\Column(name: 'valide_bic', type: Types::TEXT, nullable: true)]
    private ?string $valideBic = null;

    #[ORM\Column(name: 'valide_montant', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $valideMontant = null;

    #[ORM\Column(name: 'valide_immatriculation', length: 32, nullable: true)]
    private ?string $valideImmatriculation = null;

    #[ORM\Column(name: 'valide_icar', length: 32, nullable: true)]
    private ?string $valideIcar = null;

    #[ORM\Column(name: 'valide_libelle', length: 255, nullable: true)]
    private ?string $valideLibelle = null;

    #[ORM\Column(name: 'valide_code_comptable', length: 32, nullable: true)]
    private ?string $valideCodeComptable = null;

    #[ORM\Column(name: 'valide_role_tiers', length: 32, nullable: true)]
    private ?string $valideRoleTiers = null;

    #[ORM\Column(name: 'valide_par', length: 150, nullable: true)]
    private ?string $validePar = null;

    /** Types de pieces demandees en correction par le comptable (facultatif). */
    /** @var list<string>|null */
    #[ORM\Column(name: 'correction_pieces', type: Types::JSON, nullable: true)]
    private ?array $correctionPieces = null;

    /** Champs CLIENT modifies par la secretaire lors du dernier renvoi (repere comptable). */
    /** @var list<string>|null */
    #[ORM\Column(name: 'correction_champs', type: Types::JSON, nullable: true)]
    private ?array $correctionChamps = null;

    // --- Audit / jalons ---

    #[ORM\Column(name: 'cree_par', length: 150, nullable: true)]
    private ?string $creePar = null;

    #[ORM\Column(name: 'cree_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_par', length: 150, nullable: true)]
    private ?string $modifiePar = null;

    #[ORM\Column(name: 'modifie_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    #[ORM\Column(name: 'depose_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deposeLe = null;

    #[ORM\Column(name: 'valide_directeur_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $valideDirecteurLe = null;

    #[ORM\Column(name: 'confirme_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmeLe = null;

    #[ORM\Column(name: 'paye_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $payeLe = null;

    /** Recuperation du fichier SEPA par le manager (pour la banque) : quand et par qui. */
    #[ORM\Column(name: 'sepa_telecharge_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $sepaTelechargeLe = null;

    #[ORM\Column(name: 'sepa_telecharge_par', length: 150, nullable: true)]
    private ?string $sepaTelechargePar = null;

    public function __construct(DossierMotif $motif, ?string $creePar = null)
    {
        $this->motif = $motif;
        $this->creePar = $creePar;
        $this->creeLe = new DateTimeImmutable();
        $this->reference = 'REMB-'.strtoupper(bin2hex(random_bytes(4)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getMotif(): DossierMotif
    {
        return $this->motif;
    }

    public function getStatut(): DossierStatut
    {
        return $this->statut;
    }

    /** Positionnement d'etat : reserve a WorkflowRemboursement (garde les transitions). */
    public function definirStatut(DossierStatut $statut): void
    {
        $this->statut = $statut;
    }

    public function getEtablissementCode(): ?string
    {
        return $this->etablissementCode;
    }

    public function setEtablissementCode(?string $etablissementCode): void
    {
        $this->etablissementCode = $etablissementCode;
    }

    public function getNomClient(): string
    {
        return $this->nomClient;
    }

    public function setNomClient(string $nomClient): void
    {
        $this->nomClient = $nomClient;
    }

    public function getIbanClient(): ?string
    {
        return Chiffrement::dechiffrer($this->ibanClient);
    }

    public function setIbanClient(?string $ibanClient): void
    {
        $this->ibanClient = Chiffrement::chiffrer(null === $ibanClient ? null : strtoupper(str_replace(' ', '', $ibanClient)));
        $this->rafraichirIbanHash();
    }

    /** IBAN masque pour l'affichage par defaut (RGPD). */
    public function getIbanClientMasque(): string
    {
        return Chiffrement::masquer($this->getIbanClient());
    }

    public function getBicClient(): ?string
    {
        return Chiffrement::dechiffrer($this->bicClient);
    }

    public function setBicClient(?string $bicClient): void
    {
        $this->bicClient = Chiffrement::chiffrer($bicClient);
    }

    public function getEmailCopie(): ?string
    {
        return $this->emailCopie;
    }

    /** Normalise a l'ecriture : le carnet de favoris doit reconnaitre la meme adresse. */
    public function setEmailCopie(?string $emailCopie): void
    {
        $adresse = strtolower(trim((string) $emailCopie));
        $this->emailCopie = '' !== $adresse ? $adresse : null;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function setImmatriculation(?string $immatriculation): void
    {
        $this->immatriculation = $immatriculation;
    }

    public function getCodeIcar(): ?string
    {
        return $this->codeIcar;
    }

    public function setCodeIcar(?string $codeIcar): void
    {
        $this->codeIcar = $codeIcar;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): void
    {
        $this->montant = $montant;
    }

    public function getControleNom(): ?string
    {
        return $this->controleNom;
    }

    public function setControleNom(?string $controleNom): void
    {
        $this->controleNom = $controleNom;
    }

    public function getControleIban(): ?string
    {
        return Chiffrement::dechiffrer($this->controleIban);
    }

    public function setControleIban(?string $controleIban): void
    {
        $this->controleIban = Chiffrement::chiffrer(null === $controleIban ? null : strtoupper(str_replace(' ', '', $controleIban)));
        $this->rafraichirIbanHash();
    }

    /** IBAN controle masque pour l'affichage par defaut (RGPD). */
    public function getControleIbanMasque(): string
    {
        return Chiffrement::masquer($this->getControleIban());
    }

    public function getControleBic(): ?string
    {
        return Chiffrement::dechiffrer($this->controleBic);
    }

    public function setControleBic(?string $controleBic): void
    {
        $this->controleBic = Chiffrement::chiffrer($controleBic);
    }

    public function getControleMontant(): ?string
    {
        return $this->controleMontant;
    }

    public function setControleMontant(?string $controleMontant): void
    {
        $this->controleMontant = $controleMontant;
    }

    public function getControleImmatriculation(): ?string
    {
        return $this->controleImmatriculation;
    }

    public function setControleImmatriculation(?string $controleImmatriculation): void
    {
        $this->controleImmatriculation = $controleImmatriculation;
    }

    public function getControleIcar(): ?string
    {
        return $this->controleIcar;
    }

    public function setControleIcar(?string $controleIcar): void
    {
        $this->controleIcar = $controleIcar;
    }

    // --- Accesseurs VALIDES (comptable) ---

    public function getValideNom(): ?string
    {
        return $this->valideNom;
    }

    public function getValideIban(): ?string
    {
        return Chiffrement::dechiffrer($this->valideIban);
    }

    /** IBAN retenu (valide) masque pour l'affichage (RGPD). */
    public function getValideIbanMasque(): string
    {
        return Chiffrement::masquer($this->getValideIban());
    }

    public function getValideBic(): ?string
    {
        return Chiffrement::dechiffrer($this->valideBic);
    }

    public function getValideMontant(): ?string
    {
        return $this->valideMontant;
    }

    public function getValideImmatriculation(): ?string
    {
        return $this->valideImmatriculation;
    }

    public function getValideIcar(): ?string
    {
        return $this->valideIcar;
    }

    public function getValideLibelle(): ?string
    {
        return $this->valideLibelle;
    }

    public function getValideCodeComptable(): ?string
    {
        return $this->valideCodeComptable;
    }

    public function getValideRoleTiers(): ?string
    {
        return $this->valideRoleTiers;
    }

    public function getValidePar(): ?string
    {
        return $this->validePar;
    }

    /** @return list<string>|null */
    public function getCorrectionPieces(): ?array
    {
        return $this->correctionPieces;
    }

    /**
     * Enregistre les valeurs VALIDEES par le comptable (colonne "Valeur validée" +
     * imputation). IBAN/BIC chiffres. Sert au controle -> directeur et au repli d'une
     * correction (la comptable ne repart pas de zero).
     *
     * @param array{nom?: ?string, iban?: ?string, bic?: ?string, montant?: ?string, immatriculation?: ?string, code_icar?: ?string, libelle?: ?string, code_comptable?: ?string, role_tiers?: ?string} $d
     */
    public function enregistrerValidation(array $d, ?string $par): void
    {
        $this->valideNom = self::vide($d['nom'] ?? null);
        $this->valideIban = Chiffrement::chiffrer(null !== ($d['iban'] ?? null) && '' !== trim((string) $d['iban']) ? strtoupper(str_replace(' ', '', (string) $d['iban'])) : null);
        $this->valideBic = Chiffrement::chiffrer(self::vide($d['bic'] ?? null));
        $this->valideMontant = self::vide($d['montant'] ?? null);
        $this->valideImmatriculation = self::vide($d['immatriculation'] ?? null);
        $this->valideIcar = self::vide($d['code_icar'] ?? null);
        $this->valideLibelle = self::vide($d['libelle'] ?? null);
        $this->valideCodeComptable = self::vide($d['code_comptable'] ?? null);
        $this->valideRoleTiers = self::vide($d['role_tiers'] ?? null);
        $this->validePar = $par;
        $this->rafraichirIbanHash();
    }

    public function getIbanHash(): ?string
    {
        return $this->ibanHash;
    }

    /**
     * Recalcule l'empreinte (index aveugle) de l'IBAN RETENU (valide ?: controle ?: saisie),
     * pour la detection "meme compte bancaire" sans stocker l'IBAN en clair.
     */
    private function rafraichirIbanHash(): void
    {
        $brut = trim((string) ($this->getValideIban() ?: $this->getControleIban() ?: $this->getIbanClient()));
        $norme = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $brut));
        $this->ibanHash = '' === $norme ? null : Chiffrement::empreinte($norme);
    }

    /** @param list<string>|null $types */
    public function setCorrectionPieces(?array $types): void
    {
        $this->correctionPieces = [] === $types ? null : $types;
    }

    /** @return list<string>|null */
    public function getCorrectionChamps(): ?array
    {
        return $this->correctionChamps;
    }

    /** @param list<string>|null $champs */
    public function setCorrectionChamps(?array $champs): void
    {
        $this->correctionChamps = [] === $champs ? null : $champs;
    }

    private static function vide(?string $v): ?string
    {
        $v = null === $v ? null : trim($v);

        return null === $v || '' === $v ? null : $v;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getControleExtra(): ?array
    {
        return $this->controleExtra;
    }

    /**
     * @param array<string, mixed>|null $controleExtra
     */
    public function setControleExtra(?array $controleExtra): void
    {
        $this->controleExtra = $controleExtra;
    }

    public function getVerdictIa(): ?string
    {
        return $this->verdictIa;
    }

    public function setVerdictIa(?string $verdictIa): void
    {
        $this->verdictIa = $verdictIa;
    }

    public function getVerdictInfo(): ?string
    {
        return $this->verdictInfo;
    }

    public function setVerdictInfo(?string $verdictInfo): void
    {
        $this->verdictInfo = $verdictInfo;
    }

    public function getCleDoublon(): ?string
    {
        return $this->cleDoublon;
    }

    public function setCleDoublon(?string $cleDoublon): void
    {
        $this->cleDoublon = $cleDoublon;
    }

    public function getRefusMotif(): ?string
    {
        return $this->refusMotif;
    }

    public function setRefusMotif(?string $refusMotif): void
    {
        $this->refusMotif = $refusMotif;
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

    public function toucher(?string $par): void
    {
        $this->modifiePar = $par;
        $this->modifieLe = new DateTimeImmutable();
    }

    public function getDeposeLe(): ?DateTimeImmutable
    {
        return $this->deposeLe;
    }

    public function setDeposeLe(?DateTimeImmutable $deposeLe): void
    {
        $this->deposeLe = $deposeLe;
    }

    public function getValideDirecteurLe(): ?DateTimeImmutable
    {
        return $this->valideDirecteurLe;
    }

    public function setValideDirecteurLe(?DateTimeImmutable $valideDirecteurLe): void
    {
        $this->valideDirecteurLe = $valideDirecteurLe;
    }

    public function getConfirmeLe(): ?DateTimeImmutable
    {
        return $this->confirmeLe;
    }

    public function setConfirmeLe(?DateTimeImmutable $confirmeLe): void
    {
        $this->confirmeLe = $confirmeLe;
    }

    public function getPayeLe(): ?DateTimeImmutable
    {
        return $this->payeLe;
    }

    public function setPayeLe(?DateTimeImmutable $payeLe): void
    {
        $this->payeLe = $payeLe;
    }

    public function getSepaTelechargeLe(): ?DateTimeImmutable
    {
        return $this->sepaTelechargeLe;
    }

    public function getSepaTelechargePar(): ?string
    {
        return $this->sepaTelechargePar;
    }

    public function sepaTelecharge(): bool
    {
        return null !== $this->sepaTelechargeLe;
    }

    /** Marque le fichier SEPA comme recupere par le manager (horodatage + auteur). */
    public function marquerSepaTelecharge(?string $par): void
    {
        $this->sepaTelechargeLe = new DateTimeImmutable();
        $this->sepaTelechargePar = $par;
    }
}
