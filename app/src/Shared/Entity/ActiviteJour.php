<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\ActiviteJourRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Temps de presence active agrege par utilisateur et par jour (suivi teletravail).
 * Une seule ligne par (user, jour), incrementee par le heartbeat de presence
 * (voir PresenceService). Donnee de surveillance : conservation limitee et
 * information des salaries obligatoires. Voir docs/SECURITY.md.
 */
#[ORM\Entity(repositoryClass: ActiviteJourRepository::class)]
#[ORM\Table(name: 'activite_jour', schema: 'shared')]
#[ORM\UniqueConstraint(name: 'uniq_activite_jour_user_jour', columns: ['user_id', 'jour'])]
#[ORM\Index(name: 'idx_activite_jour_jour', columns: ['jour'])]
class ActiviteJour
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $jour;

    #[ORM\Column(options: ['default' => 0])]
    private int $secondesActives = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $derniereActivite;

    public function __construct(User $user, DateTimeImmutable $jour)
    {
        $this->user = $user;
        $this->jour = $jour;
        $this->derniereActivite = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getJour(): DateTimeImmutable
    {
        return $this->jour;
    }

    public function getSecondesActives(): int
    {
        return $this->secondesActives;
    }

    public function getDerniereActivite(): DateTimeImmutable
    {
        return $this->derniereActivite;
    }
}
