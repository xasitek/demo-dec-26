<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Repository\MessageSortantRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message sortant : une réponse envoyée par un comptable à un client, journalisée
 * pour apparaître dans l'historique des échanges (avec ses pièces jointes).
 *
 * On ne stocke PAS le binaire des pièces jointes (volume) : seulement leurs noms,
 * suffisants pour tracer ce qui a été envoyé.
 */
#[ORM\Entity(repositoryClass: MessageSortantRepository::class)]
#[ORM\Table(schema: 'recouvrement', name: 'message_sortant')]
#[ORM\Index(name: 'idx_message_sortant_compte', columns: ['compte_code'])]
class MessageSortant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'retour_id', type: Types::INTEGER, nullable: true)]
    private ?int $retourId = null;

    #[ORM\Column(name: 'compte_code', length: 255, nullable: true)]
    private ?string $compteCode = null;

    #[ORM\Column(name: 'ecriture_id', length: 255, nullable: true)]
    private ?string $ecritureId = null;

    #[ORM\Column(length: 255)]
    private string $destinataire;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(name: 'corps_html', type: Types::TEXT, nullable: true)]
    private ?string $corpsHtml = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cc = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cci = null;

    /** @var list<string> */
    #[ORM\Column(name: 'pieces_jointes', type: Types::JSON)]
    private array $piecesJointes = [];

    #[ORM\Column(nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(name: 'envoye_le')]
    private DateTimeImmutable $envoyeLe;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(string $destinataire, DateTimeImmutable $envoyeLe)
    {
        $this->destinataire = $destinataire;
        $this->envoyeLe = $envoyeLe;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRetourId(): ?int
    {
        return $this->retourId;
    }

    public function setRetourId(?int $retourId): self
    {
        $this->retourId = $retourId;

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

    public function getEcritureId(): ?string
    {
        return $this->ecritureId;
    }

    public function setEcritureId(?string $ecritureId): self
    {
        $this->ecritureId = $ecritureId;

        return $this;
    }

    public function getDestinataire(): string
    {
        return $this->destinataire;
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

    public function getCc(): ?string
    {
        return $this->cc;
    }

    public function setCc(?string $cc): self
    {
        $this->cc = $cc;

        return $this;
    }

    public function getCci(): ?string
    {
        return $this->cci;
    }

    public function setCci(?string $cci): self
    {
        $this->cci = $cci;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getPiecesJointes(): array
    {
        return $this->piecesJointes;
    }

    /**
     * @param list<string> $piecesJointes
     */
    public function setPiecesJointes(array $piecesJointes): self
    {
        $this->piecesJointes = $piecesJointes;

        return $this;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function setAuteur(?string $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getEnvoyeLe(): DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
