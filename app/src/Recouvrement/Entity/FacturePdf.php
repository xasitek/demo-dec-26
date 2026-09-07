<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Repository\FacturePdfRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * PDF d'une facture televerse manuellement (quand Progiciel n'a pas de chemin_pdf).
 *
 * Cle metier = ecriture_id (identifiant unique de la ligne dans v_impayes /
 * mirror.bal_eloficash). Le moteur de relance utilise ce PDF a defaut du PDF Progiciel
 * (cf. EnvoiRelanceService). Contenu en bytea (Doctrine le lit en resource).
 */
#[ORM\Entity(repositoryClass: FacturePdfRepository::class)]
#[ORM\Table(name: 'facture_pdf', schema: 'recouvrement')]
#[ORM\UniqueConstraint(name: 'uniq_facture_pdf_ecriture', columns: ['ecriture_id'])]
class FacturePdf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'ecriture_id', length: 64)]
    private string $ecritureId;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $numpiece = null;

    #[ORM\Column(name: 'compte_code', length: 64, nullable: true)]
    private ?string $compteCode = null;

    #[ORM\Column(name: 'nom_fichier', length: 255)]
    private string $nomFichier;

    #[ORM\Column(name: 'taille_octets', type: 'integer')]
    private int $tailleOctets;

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private mixed $contenu;

    #[ORM\Column(name: 'uploaded_par', length: 255, nullable: true)]
    private ?string $uploadedPar = null;

    #[ORM\Column(name: 'uploaded_par_user_id', type: 'bigint', nullable: true)]
    private ?int $uploadedParUserId = null;

    #[ORM\Column(name: 'uploaded_le')]
    private DateTimeImmutable $uploadedLe;

    public function __construct(string $ecritureId, string $contenu, string $nomFichier)
    {
        $this->ecritureId = $ecritureId;
        $this->contenu = $contenu;
        $this->nomFichier = $nomFichier;
        $this->tailleOctets = \strlen($contenu);
        $this->uploadedLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEcritureId(): string
    {
        return $this->ecritureId;
    }

    public function getNumpiece(): ?string
    {
        return $this->numpiece;
    }

    public function setNumpiece(?string $numpiece): self
    {
        $this->numpiece = $numpiece;

        return $this;
    }

    public function getCompteCode(): ?string
    {
        return $this->compteCode;
    }

    public function setCompteCode(?string $compteCode): self
    {
        $this->compteCode = $compteCode;

        return $this;
    }

    public function getNomFichier(): string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): self
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }

    public function getTailleOctets(): int
    {
        return $this->tailleOctets;
    }

    /**
     * Contenu binaire du PDF (Doctrine hydrate un blob en resource a la lecture).
     */
    public function getContenu(): ?string
    {
        if (\is_resource($this->contenu)) {
            $contenu = stream_get_contents($this->contenu);

            return false === $contenu ? null : $contenu;
        }

        return (string) $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;
        $this->tailleOctets = \strlen($contenu);

        return $this;
    }

    public function getUploadedPar(): ?string
    {
        return $this->uploadedPar;
    }

    public function setUploadedPar(?string $uploadedPar): self
    {
        $this->uploadedPar = $uploadedPar;

        return $this;
    }

    public function getUploadedParUserId(): ?int
    {
        return $this->uploadedParUserId;
    }

    public function setUploadedParUserId(?int $uploadedParUserId): self
    {
        $this->uploadedParUserId = $uploadedParUserId;

        return $this;
    }

    public function getUploadedLe(): DateTimeImmutable
    {
        return $this->uploadedLe;
    }
}
