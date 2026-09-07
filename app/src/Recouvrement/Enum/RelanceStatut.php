<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Cycle de vie d'une relance (recouvrement.relance_envoi.statut).
 *
 *   A_ENVOYER : preparee (sujet/corps generes), en attente d'envoi.
 *   ENVOYE    : remise au transport mail acceptee.
 *   ECHEC     : erreur a la preparation ou a l'envoi (cf. erreur_message).
 *   ANNULE    : facture redevenue ineligible (payee / soldee) entre la
 *               preparation et l'envoi -> on n'envoie PAS la relance.
 */
enum RelanceStatut: string
{
    case A_ENVOYER = 'a_envoyer';
    case ENVOYE = 'envoye';
    case ECHEC = 'echec';
    case ANNULE = 'annule';

    public function libelle(): string
    {
        return match ($this) {
            self::A_ENVOYER => 'À envoyer',
            self::ENVOYE => 'Envoyé',
            self::ECHEC => 'Échec',
            self::ANNULE => 'Annulé',
        };
    }
}
