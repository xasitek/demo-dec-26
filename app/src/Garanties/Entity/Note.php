<?php

declare(strict_types=1);

namespace App\Garanties\Entity;

use App\Garanties\Repository\NoteRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

/**
 * Annotation libre d'un dossier de garantie par un auditeur.
 *
 * Deux ancrages possibles, exclusifs :
 *
 *   - `dossier_id` : la DG principale de l'ecriture. Cas normal. La note est alors
 *     visible sur TOUTES les ecritures rapprochees a cette DG, ce qui est voulu :
 *     le commentaire porte sur le dossier de garantie, pas sur une ligne comptable ;
 *   - `cle_ecriture` + `oidech` : l'ecriture Progiciel elle-meme, quand le rapprochement ne
 *     lui trouve aucune DG (etats « orpheline » et « OD sans VIN »). Le couple, et pas
 *     la cle seule : l'index unique de la vue materialisee est
 *     (vue_lettree, cle_ecriture, oidech), donc une meme cle porte plusieurs lignes.
 *
 * Pas de relation Doctrine vers Dossier : garanties.dossier est gere en DBAL
 * (copie fidele du scrap), donc on garde dossier_id comme entier plus une FK
 * SQL declaree dans la migration. Voir docs/ARCHITECTURE.md.
 */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'note', schema: 'garanties')]
#[ORM\Index(name: 'idx_note_dossier', columns: ['dossier_id'])]
#[ORM\Index(name: 'idx_note_ecriture', columns: ['cle_ecriture', 'oidech'])]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'dossier_id', type: 'bigint', nullable: true)]
    private ?int $dossierId = null;

    #[ORM\Column(name: 'cle_ecriture', type: 'text', nullable: true)]
    private ?string $cleEcriture = null;

    #[ORM\Column(name: 'oidech', type: 'text', nullable: true)]
    private ?string $oidech = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: false, onDelete: 'RESTRICT')]
    private User $auteur;

    #[ORM\Column(type: 'text')]
    private string $texte;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    private function __construct(User $auteur, string $texte)
    {
        $this->auteur = $auteur;
        $this->texte = $texte;
        $this->creeLe = new DateTimeImmutable();
    }

    /** Note portant sur une DG (cas normal : l'ecriture est rapprochee). */
    public static function surDossier(int $dossierId, User $auteur, string $texte): self
    {
        if ($dossierId <= 0) {
            throw new LogicException('Une note sur dossier exige un identifiant de DG.');
        }

        $note = new self($auteur, $texte);
        $note->dossierId = $dossierId;

        return $note;
    }

    /** Note portant sur l'ecriture Progiciel elle-meme (aucune DG rapprochee). */
    public static function surEcriture(string $cleEcriture, ?string $oidech, User $auteur, string $texte): self
    {
        if ('' === trim($cleEcriture)) {
            throw new LogicException('Une note sur ecriture exige une cle d\'ecriture.');
        }

        $note = new self($auteur, $texte);
        $note->cleEcriture = trim($cleEcriture);
        $note->oidech = (null !== $oidech && '' !== trim($oidech)) ? trim($oidech) : null;

        return $note;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossierId(): ?int
    {
        return $this->dossierId;
    }

    public function getCleEcriture(): ?string
    {
        return $this->cleEcriture;
    }

    public function getOidech(): ?string
    {
        return $this->oidech;
    }

    public function getAuteur(): User
    {
        return $this->auteur;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
