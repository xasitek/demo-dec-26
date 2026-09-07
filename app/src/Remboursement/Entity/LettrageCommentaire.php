<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Repository\LettrageCommentaireRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Annotation de la comptable sur une ligne « a lettrer » (onglet Lettrage).
 *
 * Stockee A PART du miroir Gestion commerciale, indexee sur la cle d'ecriture Progiciel : le miroir
 * est reconstruit chaque nuit, un commentaire porte dans ses donnees serait perdu.
 * C'etait deja la raison d'etre de l'onglet `lettrage_manuel` du dashboard historique.
 *
 * La cle d'ecriture EST la cle primaire : une ligne comptable, un commentaire. Effacer
 * le texte revient a supprimer la ligne (cf. LettrageCommentaireRepository::definir).
 */
#[ORM\Entity(repositoryClass: LettrageCommentaireRepository::class)]
#[ORM\Table(name: 'lettrage_commentaire', schema: 'remboursement')]
class LettrageCommentaire
{
    #[ORM\Id]
    #[ORM\Column(name: 'cle_ecriture', type: Types::BIGINT)]
    private int $cleEcriture;

    #[ORM\Column(type: Types::TEXT)]
    private string $commentaire;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $par = null;

    #[ORM\Column(name: 'cree_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    public function __construct(int $cleEcriture, string $commentaire, ?string $par)
    {
        $this->cleEcriture = $cleEcriture;
        $this->commentaire = $commentaire;
        $this->par = $par;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getCleEcriture(): int
    {
        return $this->cleEcriture;
    }

    public function getCommentaire(): string
    {
        return $this->commentaire;
    }

    public function getPar(): ?string
    {
        return $this->par;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): ?DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function mettreAJour(string $commentaire, ?string $par): void
    {
        $this->commentaire = $commentaire;
        $this->par = $par;
        $this->modifieLe = new DateTimeImmutable();
    }
}
