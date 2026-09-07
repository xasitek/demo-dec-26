<?php

declare(strict_types=1);

namespace App\Remboursement\Entity;

use App\Remboursement\Repository\EmailFavoriRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une adresse deja mise en copie par une secretaire, proposee en autocompletion a son
 * depot suivant. Carnet d'adresses PERSONNEL : la cle est le couple (secretaire,
 * adresse), et une secretaire ne voit jamais les adresses d'une autre.
 *
 * `nbUsages` classe les propositions : l'adresse la plus souvent choisie remonte en
 * tete, ce qui evite d'imposer un tri alphabetique sans rapport avec l'usage reel.
 *
 * L'ecriture passe par un UPSERT dans le repository et non par l'ORM : elle est
 * concurrente par nature (deux depots simultanes de la meme secretaire vers la meme
 * adresse), et l'index unique doit trancher en base plutot que dans PHP.
 */
#[ORM\Entity(repositoryClass: EmailFavoriRepository::class)]
#[ORM\Table(name: 'email_favori', schema: 'remboursement')]
#[ORM\UniqueConstraint(name: 'uniq_email_favori', columns: ['secretaire', 'email'])]
#[ORM\Index(name: 'idx_email_favori_usage', columns: ['secretaire', 'dernier_usage_le'])]
class EmailFavori
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'remboursement.email_favori_id_seq')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Compte de la secretaire (son adresse Google), telle que `dossier.cree_par`. */
    #[ORM\Column(length: 190)]
    private string $secretaire;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'nb_usages')]
    private int $nbUsages = 1;

    #[ORM\Column(name: 'dernier_usage_le', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $dernierUsageLe;

    public function __construct(string $secretaire, string $email)
    {
        $this->secretaire = $secretaire;
        $this->email = $email;
        $this->dernierUsageLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSecretaire(): string
    {
        return $this->secretaire;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNbUsages(): int
    {
        return $this->nbUsages;
    }

    public function getDernierUsageLe(): DateTimeImmutable
    {
        return $this->dernierUsageLe;
    }
}
