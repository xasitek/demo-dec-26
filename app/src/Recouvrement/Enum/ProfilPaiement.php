<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Profil de paiement d'un compte client, deduit des champs tiers Progiciel.
 *
 * Il determine la cadence de relance (paliers en jours apres l'echeance). Les
 * comptes exclus (intra-groupe, non codifies, statuts juridiques sensibles) ne
 * recoivent aucune relance automatique : ils n'ont donc pas de profil ici.
 *
 * PRELEVEMENT : pas de relance auto pour l'instant (V2 sur rejet bancaire).
 */
enum ProfilPaiement: string
{
    case COMPTANT = 'comptant';
    case TRENTE_J_FDM = 'trente_j_fdm';
    case SOIXANTE_J_FDM = 'soixante_j_fdm';
    case STANDARD = 'standard';
    case PRELEVEMENT = 'prelevement';

    public function libelle(): string
    {
        return match ($this) {
            self::COMPTANT => 'Comptant',
            self::TRENTE_J_FDM => '30 jours fin de mois',
            self::SOIXANTE_J_FDM => '60 jours fin de mois',
            self::STANDARD => 'Standard',
            self::PRELEVEMENT => 'Prélèvement',
        };
    }
}
