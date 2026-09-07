<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\SuggestionVoteRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Soutien d'un utilisateur a une idee du mur. Un vote par personne et par idee :
 * la contrainte d'unicite porte la regle, pas le code applicatif.
 */
#[ORM\Entity(repositoryClass: SuggestionVoteRepository::class)]
#[ORM\Table(name: 'suggestion_vote', schema: 'shared')]
#[ORM\UniqueConstraint(name: 'uniq_suggestion_vote', columns: ['suggestion_id', 'votant_id'])]
class SuggestionVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Suggestion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Suggestion $suggestion;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $votant;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(Suggestion $suggestion, User $votant)
    {
        $this->suggestion = $suggestion;
        $this->votant = $votant;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSuggestion(): Suggestion
    {
        return $this->suggestion;
    }

    public function getVotant(): User
    {
        return $this->votant;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
