<?php

declare(strict_types=1);

namespace App\Garanties\Enum;

/**
 * Resultat de la reconciliation d'un dossier DG avec la creance Progiciel.
 * Les valeurs correspondent a celles calculees en SQL (ReconciliationRepository).
 */
enum Anomalie: string
{
    case RECONCILIE = 'reconcilie';
    case PAIEMENT_NON_RECONCILIE = 'paiement_non_reconcilie';
    case ANNULEE_MAIS_DUE = 'annulee_mais_due';
    case A_CORRIGER = 'a_corriger';
    case SANS_LIGNE_SAGE = 'sans_ligne_sage';
    case A_VERIFIER = 'a_verifier';

    public function libelle(): string
    {
        return match ($this) {
            self::RECONCILIE => 'Réconcilié',
            self::PAIEMENT_NON_RECONCILIE => 'Paiement non réconcilié',
            self::ANNULEE_MAIS_DUE => 'Annulée mais due',
            self::A_CORRIGER => 'À corriger',
            self::SANS_LIGNE_SAGE => 'Sans ligne Progiciel',
            self::A_VERIFIER => 'À vérifier',
        };
    }

    /**
     * Ton du badge : positive (sain), negative (perte), warning (action), neutral.
     */
    public function ton(): string
    {
        return match ($this) {
            self::RECONCILIE => 'positive',
            self::ANNULEE_MAIS_DUE => 'negative',
            self::PAIEMENT_NON_RECONCILIE, self::A_CORRIGER, self::A_VERIFIER => 'warning',
            self::SANS_LIGNE_SAGE => 'neutral',
        };
    }
}
