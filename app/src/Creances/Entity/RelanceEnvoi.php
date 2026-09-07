<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Enum\RelanceStatut;
use App\Creances\Enum\RelanceVecteur;
use App\Creances\Repository\RelanceEnvoiRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'envoi de relance (email, courrier, extraction, sms). Stocke le
 * corps rendu pour audit + le statut + le message d'erreur eventuel.
 */
#[ORM\Entity(repositoryClass: RelanceEnvoiRepository::class)]
#[ORM\Table(name: 'relance_envoi', schema: 'creances')]
class RelanceEnvoi
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'compte_code', length: 50)]
    private string $compteCode;

    #[ORM\Column(name: 'ecriture_numero', length: 50, nullable: true)]
    private ?string $ecritureNumero = null;

    #[ORM\ManyToOne(targetEntity: StrategieNiveau::class)]
    #[ORM\JoinColumn(name: 'strategie_niveau_id', nullable: true, onDelete: 'SET NULL')]
    private ?StrategieNiveau $strategieNiveau = null;

    #[ORM\ManyToOne(targetEntity: ModeleCourrier::class)]
    #[ORM\JoinColumn(name: 'modele_courrier_id', nullable: true, onDelete: 'SET NULL')]
    private ?ModeleCourrier $modeleCourrier = null;

    #[ORM\Column(length: 20, enumType: RelanceVecteur::class)]
    private RelanceVecteur $vecteur;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $destinataire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(name: 'corps_html', type: 'text', nullable: true)]
    private ?string $corpsHtml = null;

    #[ORM\Column(length: 20, enumType: RelanceStatut::class)]
    private RelanceStatut $statut = RelanceStatut::AEnvoyer;

    #[ORM\Column(name: 'erreur_message', type: 'text', nullable: true)]
    private ?string $erreurMessage = null;

    #[ORM\Column(name: 'fichier_path', length: 500, nullable: true)]
    private ?string $fichierPath = null;

    #[ORM\Column(name: 'envoye_le', nullable: true)]
    private ?DateTimeImmutable $envoyeLe = null;

    #[ORM\Column(name: 'archive_le', nullable: true)]
    private ?DateTimeImmutable $archiveLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(
        string $compteCode,
        RelanceVecteur $vecteur,
        ?User $auteur,
        ?ModeleCourrier $modele = null,
        ?StrategieNiveau $niveau = null,
        ?string $ecritureNumero = null,
        ?string $destinataire = null,
        ?string $sujet = null,
        ?string $corpsHtml = null,
    ) {
        $this->compteCode = $compteCode;
        $this->vecteur = $vecteur;
        $this->auteur = $auteur;
        $this->modeleCourrier = $modele;
        $this->strategieNiveau = $niveau;
        $this->ecritureNumero = $ecritureNumero;
        $this->destinataire = $destinataire;
        $this->sujet = $sujet;
        $this->corpsHtml = $corpsHtml;
        $this->creeLe = new DateTimeImmutable();
    }

    public function marquerEnvoye(): void
    {
        $this->statut = RelanceStatut::Envoye;
        $this->envoyeLe = new DateTimeImmutable();
        $this->erreurMessage = null;
    }

    public function marquerErreur(string $message): void
    {
        $this->statut = RelanceStatut::Erreur;
        $this->erreurMessage = $message;
    }

    public function marquerSupprime(): void
    {
        $this->statut = RelanceStatut::Supprime;
    }

    public function archiver(): void
    {
        $this->statut = RelanceStatut::Archive;
        $this->archiveLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompteCode(): string
    {
        return $this->compteCode;
    }

    public function getEcritureNumero(): ?string
    {
        return $this->ecritureNumero;
    }

    public function getStrategieNiveau(): ?StrategieNiveau
    {
        return $this->strategieNiveau;
    }

    public function getModeleCourrier(): ?ModeleCourrier
    {
        return $this->modeleCourrier;
    }

    public function getVecteur(): RelanceVecteur
    {
        return $this->vecteur;
    }

    public function getDestinataire(): ?string
    {
        return $this->destinataire;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function getCorpsHtml(): ?string
    {
        return $this->corpsHtml;
    }

    public function getStatut(): RelanceStatut
    {
        return $this->statut;
    }

    public function getErreurMessage(): ?string
    {
        return $this->erreurMessage;
    }

    public function getFichierPath(): ?string
    {
        return $this->fichierPath;
    }

    public function getEnvoyeLe(): ?DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function getArchiveLe(): ?DateTimeImmutable
    {
        return $this->archiveLe;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
