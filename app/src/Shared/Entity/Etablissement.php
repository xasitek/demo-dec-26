<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\EtablissementRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Etablissement SYNTHAUTO (site). Referentiel PARTAGE : tous les modules s'y referent
 * (code, libelle, societe). Le module Recouvrement y attache des contacts
 * (directeur, secretaires) destinataires des « relances site ».
 * Voir docs/RECOUVREMENT_RELANCE_SITE.md.
 *
 * La cle metier est le code etablissement (colonne `codeetab` de Progiciel, zero-padde :
 * '093'), distinct du code SOCIETE (`code_entite` : 'TAM').
 */
#[ORM\Entity(repositoryClass: EtablissementRepository::class)]
#[ORM\Table(name: 'etablissement', schema: 'shared')]
class Etablissement
{
    #[ORM\Id]
    #[ORM\Column(length: 8)]
    private string $codeEtab;

    #[ORM\Column(length: 150)]
    private string $libelle;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $codeSociete = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $societe = null;

    // Coordonnees bancaires du DEBITEUR (compte SYNTHAUTO de l'etablissement) : identite
    // SEPA du virement emis (remboursement client, ordre de virement). Source =
    // onglet `donnees` du Sheet remboursement. IBAN d'un compte SYNTHAUTO (pas un IBAN
    // client) : moins sensible, non chiffre ici (comme recouvrement.rib_etablissement).
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nomLegalSepa = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $banque = null;

    // Compte de contrepartie comptable (« cinq » de l'onglet donnees, classe 512xxxx) :
    // compte de tresorerie/banque utilise dans l'ecriture OD de remboursement (CSV Gestion commerciale).
    #[ORM\Column(name: 'compte_contrepartie', length: 20, nullable: true)]
    private ?string $compteContrepartie = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $creeLe;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $modifieLe = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $modifiePar = null;

    public function __construct(string $codeEtab, string $libelle)
    {
        $this->codeEtab = $codeEtab;
        $this->libelle = $libelle;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getCodeEtab(): string
    {
        return $this->codeEtab;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
        $this->touch();
    }

    public function getCodeSociete(): ?string
    {
        return $this->codeSociete;
    }

    public function setCodeSociete(?string $codeSociete): void
    {
        $this->codeSociete = $codeSociete;
        $this->touch();
    }

    public function getSociete(): ?string
    {
        return $this->societe;
    }

    public function setSociete(?string $societe): void
    {
        $this->societe = $societe;
        $this->touch();
    }

    public function getNomLegalSepa(): ?string
    {
        return $this->nomLegalSepa;
    }

    public function setNomLegalSepa(?string $nomLegalSepa): void
    {
        $this->nomLegalSepa = $nomLegalSepa;
        $this->touch();
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): void
    {
        $this->iban = null !== $iban ? strtoupper(str_replace(' ', '', $iban)) : null;
        $this->touch();
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): void
    {
        $this->bic = null !== $bic ? strtoupper(str_replace(' ', '', $bic)) : null;
        $this->touch();
    }

    public function getBanque(): ?string
    {
        return $this->banque;
    }

    public function setBanque(?string $banque): void
    {
        $this->banque = $banque;
        $this->touch();
    }

    public function getCompteContrepartie(): ?string
    {
        return $this->compteContrepartie;
    }

    public function setCompteContrepartie(?string $compteContrepartie): void
    {
        $this->compteContrepartie = $compteContrepartie;
        $this->touch();
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
        $this->touch();
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): ?DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function getModifiePar(): ?string
    {
        return $this->modifiePar;
    }

    /** Marque l'auteur du dernier passage (a appeler apres les setters). */
    public function marquerModifiePar(?string $par): void
    {
        $this->modifiePar = $par;
        $this->modifieLe = new DateTimeImmutable();
    }

    private function touch(): void
    {
        $this->modifieLe = new DateTimeImmutable();
    }
}
