<?php

declare(strict_types=1);

namespace App\Creances\Entity;

use App\Creances\Repository\CampagneExecutionRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'execution d'une campagne : statistiques + erreurs.
 */
#[ORM\Entity(repositoryClass: CampagneExecutionRepository::class)]
#[ORM\Table(name: 'campagne_execution', schema: 'creances')]
class CampagneExecution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campagne::class)]
    #[ORM\JoinColumn(name: 'campagne_id', nullable: false, onDelete: 'CASCADE')]
    private Campagne $campagne;

    #[ORM\Column(length: 20)]
    private string $statut = 'a_traiter';

    #[ORM\Column(name: 'nb_comptes', type: 'integer')]
    private int $nbComptes = 0;

    #[ORM\Column(name: 'nb_envoyes', type: 'integer')]
    private int $nbEnvoyes = 0;

    #[ORM\Column(name: 'nb_erreurs', type: 'integer')]
    private int $nbErreurs = 0;

    #[ORM\Column(name: 'erreur_message', type: 'text', nullable: true)]
    private ?string $erreurMessage = null;

    #[ORM\Column(name: 'lance_le')]
    private DateTimeImmutable $lanceLe;

    #[ORM\Column(name: 'archive_le', nullable: true)]
    private ?DateTimeImmutable $archiveLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    public function __construct(Campagne $campagne, ?User $auteur)
    {
        $this->campagne = $campagne;
        $this->auteur = $auteur;
        $this->lanceLe = new DateTimeImmutable();
    }

    public function finir(int $envoyes, int $erreurs, int $total): void
    {
        $this->nbEnvoyes = $envoyes;
        $this->nbErreurs = $erreurs;
        $this->nbComptes = $total;
        $this->statut = 0 === $erreurs ? 'archive' : 'en_cours';
        if (0 === $erreurs) {
            $this->archiveLe = new DateTimeImmutable();
        }
    }

    public function signalerErreur(string $message): void
    {
        $this->statut = 'erreur';
        $this->erreurMessage = $message;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampagne(): Campagne
    {
        return $this->campagne;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getNbComptes(): int
    {
        return $this->nbComptes;
    }

    public function getNbEnvoyes(): int
    {
        return $this->nbEnvoyes;
    }

    public function getNbErreurs(): int
    {
        return $this->nbErreurs;
    }

    public function getErreurMessage(): ?string
    {
        return $this->erreurMessage;
    }

    public function getLanceLe(): DateTimeImmutable
    {
        return $this->lanceLe;
    }

    public function getArchiveLe(): ?DateTimeImmutable
    {
        return $this->archiveLe;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }
}
