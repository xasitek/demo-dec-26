<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use App\Recouvrement\Repository\RegleRelanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Règle de relance CONFIGURABLE (recouvrement.regle_relance).
 *
 * Le dispositif n'est plus codé en dur : une règle décrit QUI relancer (filtres
 * sur les colonnes de v_impayes), QUAND et à quelle fréquence (cadence), et
 * COMMENT (relevé seul ou avec les PDF). La comptable / le manager crée, modifie
 * et réordonne ces règles depuis la vue Stratégies, sans redéploiement.
 *
 * Résolution : pour un compte donné, la PREMIÈRE règle active (par priorité
 * croissante) dont les filtres matchent fournit sa cadence et son rendu.
 */
#[ORM\Entity(repositoryClass: RegleRelanceRepository::class)]
#[ORM\Table(name: 'regle_relance', schema: 'recouvrement')]
#[ORM\Index(name: 'idx_regle_actif_priorite', columns: ['actif', 'priorite'])]
class RegleRelance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** Ordre d'évaluation : plus petit = évalué en premier (gagne le match). */
    #[ORM\Column(options: ['default' => 0])]
    private int $priorite = 0;

    /**
     * Conditions combinées en ET. Chaque filtre = {champ, operateur, valeur}.
     * Le champ référence une colonne de v_impayes (liste blanche côté MoteurFiltre).
     *
     * @var list<array{champ: string, operateur: string, valeur: mixed}>
     */
    #[ORM\Column(type: 'json')]
    private array $filtres = [];

    /** Retard (jours) avant la 1re relance. */
    #[ORM\Column(name: 'delai_initial', type: 'smallint', options: ['default' => 30])]
    private int $delaiInitial = 30;

    /** Intervalle (jours) entre deux relances successives. */
    #[ORM\Column(type: 'smallint', options: ['default' => 15])]
    private int $intervalle = 15;

    /** Retard (jours) à partir duquel la relance devient une mise en demeure. */
    #[ORM\Column(name: 'seuil_med', type: 'smallint', options: ['default' => 90])]
    private int $seuilMed = 90;

    /** Montant net minimum (euros TTC) pour déclencher une relance AUTO ; 0 = pas de seuil. */
    #[ORM\Column(name: 'montant_min', type: 'integer', options: ['default' => 100])]
    private int $montantMin = 100;

    /** Continuer à relancer après la mise en demeure (sinon la MED est le dernier acte auto). */
    #[ORM\Column(name: 'continuer_apres_med', options: ['default' => true])]
    private bool $continuerApresMed = true;

    /** Relevé seul (pas de PDF de factures joints) — ex. clients au prélèvement. */
    #[ORM\Column(name: 'releve_seul', options: ['default' => false])]
    private bool $releveSeul = false;

    #[ORM\Column(name: 'cree_le')]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'modifie_le', nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    public function __construct(string $nom)
    {
        $this->nom = $nom;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): void
    {
        $this->nom = $nom;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }

    public function getPriorite(): int
    {
        return $this->priorite;
    }

    public function setPriorite(int $priorite): void
    {
        $this->priorite = $priorite;
    }

    /**
     * @return list<array{champ: string, operateur: string, valeur: mixed}>
     */
    public function getFiltres(): array
    {
        return $this->filtres;
    }

    /**
     * @param list<array{champ: string, operateur: string, valeur: mixed}> $filtres
     */
    public function setFiltres(array $filtres): void
    {
        $this->filtres = $filtres;
    }

    public function getDelaiInitial(): int
    {
        return $this->delaiInitial;
    }

    public function setDelaiInitial(int $delaiInitial): void
    {
        $this->delaiInitial = $delaiInitial;
    }

    public function getIntervalle(): int
    {
        return $this->intervalle;
    }

    public function setIntervalle(int $intervalle): void
    {
        $this->intervalle = $intervalle;
    }

    public function getSeuilMed(): int
    {
        return $this->seuilMed;
    }

    public function setSeuilMed(int $seuilMed): void
    {
        $this->seuilMed = $seuilMed;
    }

    public function getMontantMin(): int
    {
        return $this->montantMin;
    }

    public function setMontantMin(int $montantMin): void
    {
        $this->montantMin = $montantMin;
    }

    public function isContinuerApresMed(): bool
    {
        return $this->continuerApresMed;
    }

    public function setContinuerApresMed(bool $continuerApresMed): void
    {
        $this->continuerApresMed = $continuerApresMed;
    }

    public function isReleveSeul(): bool
    {
        return $this->releveSeul;
    }

    public function setReleveSeul(bool $releveSeul): void
    {
        $this->releveSeul = $releveSeul;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): ?DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function toucherModifieLe(): void
    {
        $this->modifieLe = new DateTimeImmutable();
    }
}
