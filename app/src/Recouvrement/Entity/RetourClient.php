<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Enum\RetourCategorie;
use App\Recouvrement\Enum\RetourSource;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un retour client (reponse a une relance), collecte par IMAP / webhook ou saisi
 * manuellement.
 *
 * Reliable a une relance via relance_envoi_id quand on retrouve le token /
 * message-id ; la FK est ON DELETE SET NULL pour conserver l'historique des
 * retours meme si la relance d'origine disparait. Unicite partielle sur
 * message_id (seulement quand il est renseigne) pour eviter les doublons IMAP.
 */
#[ORM\Entity]
#[ORM\Table(name: 'retour_client', schema: 'recouvrement')]
#[ORM\Index(name: 'idx_retour_traite', columns: ['traite'])]
#[ORM\Index(name: 'idx_retour_compte', columns: ['compte_code'])]
class RetourClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RelanceEnvoi::class)]
    #[ORM\JoinColumn(name: 'relance_envoi_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?RelanceEnvoi $relanceEnvoi = null;

    #[ORM\Column(name: 'compte_code', length: 64, nullable: true)]
    private ?string $compteCode = null;

    #[ORM\Column(name: 'ecriture_id', length: 64, nullable: true)]
    private ?string $ecritureId = null;

    #[ORM\Column(name: 'message_id', length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'in_reply_to', length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $expediteur = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(name: 'corps_texte', type: 'text', nullable: true)]
    private ?string $corpsTexte = null;

    #[ORM\Column(name: 'corps_html', type: 'text', nullable: true)]
    private ?string $corpsHtml = null;

    #[ORM\Column(enumType: RetourCategorie::class, nullable: true)]
    private ?RetourCategorie $categorie = null;

    #[ORM\Column(enumType: RetourSource::class)]
    private RetourSource $source;

    #[ORM\Column]
    private bool $traite = false;

    #[ORM\Column(name: 'traite_par', length: 255, nullable: true)]
    private ?string $traitePar = null;

    #[ORM\Column(name: 'traite_le', nullable: true)]
    private ?DateTimeImmutable $traiteLe = null;

    #[ORM\Column(name: 'commentaire_traitement', type: 'text', nullable: true)]
    private ?string $commentaireTraitement = null;

    /**
     * Adresses en copie (CC) presentes dans le mail recu. Le CCI (BCC) n'est pas
     * capturable : il est retire par les serveurs mail avant livraison.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cc = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $payload = null;

    #[ORM\Column(name: 'recu_le')]
    private DateTimeImmutable $recuLe;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    public function __construct(RetourSource $source, DateTimeImmutable $recuLe)
    {
        $this->source = $source;
        $this->recuLe = $recuLe;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelanceEnvoi(): ?RelanceEnvoi
    {
        return $this->relanceEnvoi;
    }

    public function setRelanceEnvoi(?RelanceEnvoi $relanceEnvoi): self
    {
        $this->relanceEnvoi = $relanceEnvoi;

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

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?string $inReplyTo): self
    {
        $this->inReplyTo = $inReplyTo;

        return $this;
    }

    public function getExpediteur(): ?string
    {
        return $this->expediteur;
    }

    public function setExpediteur(?string $expediteur): self
    {
        $this->expediteur = $expediteur;

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

    public function getCorpsTexte(): ?string
    {
        return $this->corpsTexte;
    }

    public function setCorpsTexte(?string $corpsTexte): self
    {
        $this->corpsTexte = $corpsTexte;

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

    public function getCategorie(): ?RetourCategorie
    {
        return $this->categorie;
    }

    public function setCategorie(?RetourCategorie $categorie): self
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getSource(): RetourSource
    {
        return $this->source;
    }

    public function setSource(RetourSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function isTraite(): bool
    {
        return $this->traite;
    }

    public function setTraite(bool $traite): self
    {
        $this->traite = $traite;

        return $this;
    }

    public function getTraitePar(): ?string
    {
        return $this->traitePar;
    }

    public function setTraitePar(?string $traitePar): self
    {
        $this->traitePar = $traitePar;

        return $this;
    }

    public function getTraiteLe(): ?DateTimeImmutable
    {
        return $this->traiteLe;
    }

    public function setTraiteLe(?DateTimeImmutable $traiteLe): self
    {
        $this->traiteLe = $traiteLe;

        return $this;
    }

    public function getCommentaireTraitement(): ?string
    {
        return $this->commentaireTraitement;
    }

    public function setCommentaireTraitement(?string $commentaireTraitement): self
    {
        $this->commentaireTraitement = $commentaireTraitement;

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

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getRecuLe(): DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function setRecuLe(DateTimeImmutable $recuLe): self
    {
        $this->recuLe = $recuLe;

        return $this;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
