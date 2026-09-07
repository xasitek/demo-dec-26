<?php

declare(strict_types=1);

namespace App\Garanties\Enum;

/**
 * Regroupement metier des statuts DG constructeur. Sert au visuel (couleur du
 * badge dans l'ecran Audit). Voir StatutDg pour le mapping code -> famille.
 */
enum Famille: string
{
    case PAYE = 'paye';
    case ANNULE = 'annule';
    case A_CORRIGER = 'a_corriger';
    case EN_TRAITEMENT = 'en_traitement';

    public function libelle(): string
    {
        return match ($this) {
            self::PAYE => 'Payé',
            self::ANNULE => 'Annulé',
            self::A_CORRIGER => 'À corriger',
            self::EN_TRAITEMENT => 'En traitement',
        };
    }

    /**
     * Couleur visuelle pour les badges/cartes (cf. tons dans le template).
     */
    public function ton(): string
    {
        return match ($this) {
            self::PAYE => 'positive',
            self::ANNULE => 'negative',
            self::A_CORRIGER => 'warning',
            self::EN_TRAITEMENT => 'info',
        };
    }
}
