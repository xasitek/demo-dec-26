<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Enum\ProfilPaiement;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Enum\StatutCourrier;
use App\Recouvrement\Enum\TypeCourrier;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une relance preparee puis envoyee a un COMPTE client a un niveau donne.
 *
 * Le dispositif relance un compte (et non une facture isolee) : un envoi groupe
 * liste toutes les factures echues impayees du client (releve). La cle metier est
 * donc (compte_code, niveau) : on ne relance jamais deux fois le meme palier d'un
 * compte (idempotence stricte, cf. migration Version20260626140000). Le compte est
 * identifie par son code Progiciel ; pas de relation Doctrine vers la vue v_impayes
 * (lecture DBAL seule).
 *
 * ecriture_id et reference_facture restent presents mais nullables : ils n'ont
 * de sens qu'en envoi unitaire (historique) et valent null en envoi groupe.
 * montant_solde porte le TOTAL de l'encours relance ; nb_factures le nombre de
 * factures listees.
 */
#[ORM\Entity]
#[ORM\Table(name: 'relance_envoi', schema: 'recouvrement')]
#[ORM\UniqueConstraint(name: 'uniq_relance_token', columns: ['token'])]
#[ORM\Index(name: 'idx_relance_compte', columns: ['compte_code'])]
#[ORM\Index(name: 'idx_relance_statut', columns: ['statut'])]
class RelanceEnvoi
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 64)]
    private string $compteCode;

    #[ORM\Column(name: 'ecriture_id', length: 64, nullable: true)]
    private ?string $ecritureId = null;

    #[ORM\Column(name: 'reference_facture', length: 128, nullable: true)]
    private ?string $referenceFacture = null;

    #[ORM\Column(type: 'smallint')]
    private int $niveau;

    #[ORM\Column(name: 'nb_factures', type: 'integer', options: ['default' => 0])]
    private int $nbFactures = 0;

    // Profil de paiement : héritage de l'ancienne cadence par profil. Désormais
    // nullable (la cadence vient de la règle de relance). Conservé pour l'historique.
    #[ORM\Column(enumType: ProfilPaiement::class, nullable: true)]
    private ?ProfilPaiement $profil = null;

    // Règle de relance qui a produit cet envoi (moteur configurable). regle_id peut
    // devenir orphelin si la règle est supprimée ; regle_nom fige le nom (preuve).
    #[ORM\Column(name: 'regle_id', type: 'bigint', nullable: true)]
    private ?int $regleId = null;

    #[ORM\Column(name: 'regle_nom', length: 120, nullable: true)]
    private ?string $regleNom = null;

    // Mise en demeure figée à la préparation (le rendu courrier/relevé en dépend),
    // + seuil (jours) de la règle : évite de recalculer depuis la cadence.
    #[ORM\Column(name: 'mise_en_demeure', options: ['default' => false])]
    private bool $miseEnDemeure = false;

    #[ORM\Column(name: 'seuil_med', type: 'smallint', nullable: true)]
    private ?int $seuilMed = null;

    /**
     * PDF du courrier (relevé + factures) pré-fusionné par la machine interne
     * (seule à avoir l'accès Progiciel + Ghostscript) ; le web (Render) le sert tel quel.
     *
     * @var resource|string|null
     */
    #[ORM\Column(name: 'courrier_pdf', type: 'blob', nullable: true)]
    private mixed $courrierPdf = null;

    #[ORM\Column(enumType: RelanceVecteur::class)]
    private RelanceVecteur $vecteur;

    #[ORM\Column(enumType: RelanceStatut::class)]
    private RelanceStatut $statut;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $destinataire = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(name: 'corps_html', type: 'text', nullable: true)]
    private ?string $corpsHtml = null;

    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column(name: 'montant_solde', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $montantSolde = null;

    #[ORM\Column(name: 'erreur_message', type: 'text', nullable: true)]
    private ?string $erreurMessage = null;

    #[ORM\Column(name: 'prepare_le', nullable: true)]
    private ?DateTimeImmutable $prepareLe = null;

    #[ORM\Column(name: 'envoye_le', nullable: true)]
    private ?DateTimeImmutable $envoyeLe = null;

    /**
     * Nom complet de l'utilisateur ayant marque le courrier comme poste (vecteur
     * COURRIER, marquage manuel depuis la vue "Courriers a envoyer"). Null pour
     * les envois email automatiques (aucun operateur humain).
     */
    #[ORM\Column(name: 'envoye_par', length: 255, nullable: true)]
    private ?string $envoyePar = null;

    /**
     * Identifiant immuable de l'utilisateur ayant marque le courrier poste (valeur
     * probante : le nom envoye_par peut changer, pas cet id). Null pour les envois
     * email automatiques.
     */
    #[ORM\Column(name: 'envoye_par_user_id', type: 'bigint', nullable: true)]
    private ?int $envoyeParUserId = null;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    /**
     * Cycle clos : le compte a ete solde apres cette relance. Archive (garde la
     * preuve) mais exclue de l'escalade et de l'anti-doublon -> une nouvelle dette
     * repart au niveau 1.
     */
    #[ORM\Column(name: 'cycle_clos', type: 'boolean', options: ['default' => false])]
    private bool $cycleClos = false;

    // ---- Suivi de l'envoi POSTAL (vecteur COURRIER, via prestataire type Maileva) ----

    #[ORM\Column(name: 'courrier_provider', length: 30, nullable: true)]
    private ?string $courrierProvider = null;

    #[ORM\Column(name: 'courrier_ref', length: 128, nullable: true)]
    private ?string $courrierRef = null;

    #[ORM\Column(name: 'courrier_type', enumType: TypeCourrier::class, nullable: true)]
    private ?TypeCourrier $courrierType = null;

    #[ORM\Column(name: 'courrier_statut', enumType: StatutCourrier::class, nullable: true)]
    private ?StatutCourrier $courrierStatut = null;

    /**
     * Accuse de reception / preuve renvoye(e) par le prestataire (recommande),
     * a conserver comme preuve juridique.
     *
     * @var resource|string|null
     */
    #[ORM\Column(name: 'courrier_ar', type: 'blob', nullable: true)]
    private mixed $courrierAr = null;

    #[ORM\Column(name: 'courrier_maj_le', nullable: true)]
    private ?DateTimeImmutable $courrierMajLe = null;

    public function __construct(
        string $compteCode,
        int $niveau,
        RelanceVecteur $vecteur,
        RelanceStatut $statut,
        string $token,
    ) {
        $this->compteCode = $compteCode;
        $this->niveau = $niveau;
        $this->vecteur = $vecteur;
        $this->statut = $statut;
        $this->token = $token;
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

    public function setCompteCode(string $compteCode): self
    {
        $this->compteCode = $compteCode;

        return $this;
    }

    public function getEcritureId(): ?string
    {
        return $this->ecritureId;
    }

    public function setEcritureId(?string $ecritureId): self
    {
        $this->ecritureId = $ecritureId;

        return $this;
    }

    public function getReferenceFacture(): ?string
    {
        return $this->referenceFacture;
    }

    public function setReferenceFacture(?string $referenceFacture): self
    {
        $this->referenceFacture = $referenceFacture;

        return $this;
    }

    public function getNiveau(): int
    {
        return $this->niveau;
    }

    public function setNiveau(int $niveau): self
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getNbFactures(): int
    {
        return $this->nbFactures;
    }

    public function setNbFactures(int $nbFactures): self
    {
        $this->nbFactures = $nbFactures;

        return $this;
    }

    public function getProfil(): ?ProfilPaiement
    {
        return $this->profil;
    }

    public function setProfil(?ProfilPaiement $profil): self
    {
        $this->profil = $profil;

        return $this;
    }

    public function getRegleId(): ?int
    {
        return $this->regleId;
    }

    public function setRegleId(?int $regleId): self
    {
        $this->regleId = $regleId;

        return $this;
    }

    public function getRegleNom(): ?string
    {
        return $this->regleNom;
    }

    public function setRegleNom(?string $regleNom): self
    {
        $this->regleNom = $regleNom;

        return $this;
    }

    public function isMiseEnDemeure(): bool
    {
        return $this->miseEnDemeure;
    }

    public function setMiseEnDemeure(bool $miseEnDemeure): self
    {
        $this->miseEnDemeure = $miseEnDemeure;

        return $this;
    }

    public function getSeuilMed(): ?int
    {
        return $this->seuilMed;
    }

    public function setSeuilMed(?int $seuilMed): self
    {
        $this->seuilMed = $seuilMed;

        return $this;
    }

    /**
     * Contenu du PDF courrier pré-fusionné (Doctrine hydrate un blob en resource
     * à la lecture), ou null si pas encore généré.
     */
    public function getCourrierPdf(): ?string
    {
        if (null === $this->courrierPdf) {
            return null;
        }
        if (\is_resource($this->courrierPdf)) {
            $contenu = stream_get_contents($this->courrierPdf);

            return false === $contenu ? null : $contenu;
        }

        return (string) $this->courrierPdf;
    }

    public function setCourrierPdf(?string $courrierPdf): self
    {
        $this->courrierPdf = $courrierPdf;

        return $this;
    }

    public function getVecteur(): RelanceVecteur
    {
        return $this->vecteur;
    }

    public function setVecteur(RelanceVecteur $vecteur): self
    {
        $this->vecteur = $vecteur;

        return $this;
    }

    public function getStatut(): RelanceStatut
    {
        return $this->statut;
    }

    public function setStatut(RelanceStatut $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDestinataire(): ?string
    {
        return $this->destinataire;
    }

    public function setDestinataire(?string $destinataire): self
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function setSujet(?string $sujet): self
    {
        $this->sujet = $sujet;

        return $this;
    }

    public function getCorpsHtml(): ?string
    {
        return $this->corpsHtml;
    }

    public function setCorpsHtml(?string $corpsHtml): self
    {
        $this->corpsHtml = $corpsHtml;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getMontantSolde(): ?string
    {
        return $this->montantSolde;
    }

    public function setMontantSolde(?string $montantSolde): self
    {
        $this->montantSolde = $montantSolde;

        return $this;
    }

    public function getErreurMessage(): ?string
    {
        return $this->erreurMessage;
    }

    public function setErreurMessage(?string $erreurMessage): self
    {
        $this->erreurMessage = $erreurMessage;

        return $this;
    }

    public function getPrepareLe(): ?DateTimeImmutable
    {
        return $this->prepareLe;
    }

    public function setPrepareLe(?DateTimeImmutable $prepareLe): self
    {
        $this->prepareLe = $prepareLe;

        return $this;
    }

    public function getEnvoyeLe(): ?DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function setEnvoyeLe(?DateTimeImmutable $envoyeLe): self
    {
        $this->envoyeLe = $envoyeLe;

        return $this;
    }

    public function getEnvoyePar(): ?string
    {
        return $this->envoyePar;
    }

    public function setEnvoyePar(?string $envoyePar): self
    {
        $this->envoyePar = $envoyePar;

        return $this;
    }

    public function getEnvoyeParUserId(): ?int
    {
        return $this->envoyeParUserId;
    }

    public function setEnvoyeParUserId(?int $envoyeParUserId): self
    {
        $this->envoyeParUserId = $envoyeParUserId;

        return $this;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function isCycleClos(): bool
    {
        return $this->cycleClos;
    }

    public function setCycleClos(bool $cycleClos): self
    {
        $this->cycleClos = $cycleClos;

        return $this;
    }

    // ---- Envoi postal ----

    public function getCourrierProvider(): ?string
    {
        return $this->courrierProvider;
    }

    public function getCourrierRef(): ?string
    {
        return $this->courrierRef;
    }

    public function getCourrierType(): ?TypeCourrier
    {
        return $this->courrierType;
    }

    public function setCourrierType(?TypeCourrier $courrierType): self
    {
        $this->courrierType = $courrierType;

        return $this;
    }

    public function getCourrierStatut(): ?StatutCourrier
    {
        return $this->courrierStatut;
    }

    public function getCourrierMajLe(): ?DateTimeImmutable
    {
        return $this->courrierMajLe;
    }

    /**
     * Enregistre le resultat d'un depot chez le prestataire (reference + statut).
     */
    public function enregistrerDepotCourrier(string $provider, ?string $reference, StatutCourrier $statut): self
    {
        $this->courrierProvider = $provider;
        $this->courrierRef = $reference;
        $this->courrierStatut = $statut;
        $this->courrierMajLe = new DateTimeImmutable();

        return $this;
    }

    /**
     * Met a jour le statut postal (suivi periodique).
     */
    public function majStatutCourrier(StatutCourrier $statut): self
    {
        $this->courrierStatut = $statut;
        $this->courrierMajLe = new DateTimeImmutable();

        return $this;
    }

    /**
     * Accuse de reception (Doctrine hydrate un blob en resource a la lecture).
     */
    public function getCourrierAr(): ?string
    {
        if (\is_resource($this->courrierAr)) {
            $contenu = stream_get_contents($this->courrierAr);

            return false === $contenu ? null : $contenu;
        }

        return null === $this->courrierAr ? null : (string) $this->courrierAr;
    }

    public function setCourrierAr(?string $courrierAr): self
    {
        $this->courrierAr = $courrierAr;

        return $this;
    }
}
