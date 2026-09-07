<?php

declare(strict_types=1);

namespace App\Remboursement\Enum;

/**
 * Motif d'un dossier de remboursement client. Deux cas metier distincts qui
 * different par la cle d'identite (anti-doublon), les pieces requises et le
 * prefixe d'ecriture comptable. Voir docs/MODULE_REMBOURSEMENT.md.
 */
enum DossierMotif: string
{
    /** Rachat sec (buy back) : cle = immatriculation, prefixe compta "RBC //". */
    case RACHAT_SEC = 'rachat_sec';

    /** Trop-percu : cle = code ICAR (seul, cf. arbitrage PO 2026-08-11), prefixe "TP //". */
    case TROP_PERCU = 'trop_percu';

    public function libelle(): string
    {
        return match ($this) {
            self::RACHAT_SEC => 'Rachat sec',
            self::TROP_PERCU => 'Trop-perçu',
        };
    }

    /** Prefixe du libelle d'ecriture comptable (CSV OD). */
    public function prefixeComptable(): string
    {
        return match ($this) {
            self::RACHAT_SEC => 'RBC //',
            self::TROP_PERCU => 'TP //',
        };
    }

    /**
     * Types de pieces attendus au depot pour ce motif (cle technique => libelle).
     * Sert a piloter le formulaire dynamique et la verification de completude.
     *
     * @return array<string, string>
     */
    public function piecesRequises(): array
    {
        return match ($this) {
            self::RACHAT_SEC => [
                'rib' => 'RIB',
                'facture_achat_vo' => 'Facture d\'achat VO',
                'estimation_salesforce' => 'Estimation Salesforce',
                'carte_grise' => 'Carte grise',
                'certificat_situation' => 'Certificat de situation administrative',
            ],
            self::TROP_PERCU => [
                'rib' => 'RIB',
                'releve_icar' => 'Relevé de compte ICAR',
                'petits_comptes' => 'Petits comptes',
            ],
        };
    }
}
