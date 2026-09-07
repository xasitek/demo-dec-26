<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Etat normalise d'un envoi postal (vecteur COURRIER), independant du prestataire.
 * Chaque prestataire (Maileva, La Poste...) mappe ses propres statuts vers ceux-ci.
 */
enum StatutCourrier: string
{
    /** Pas encore transmis au prestataire (en file / mode simulation). */
    case EN_ATTENTE = 'en_attente';

    /** Accepte par le prestataire : une reference a ete obtenue. */
    case DEPOSE = 'depose';

    /** Parti en production / affranchi / posté. */
    case POSTE = 'poste';

    /** Remis au destinataire (AR recu pour un recommande). */
    case DISTRIBUE = 'distribue';

    /** Non remis : NPAI, refuse, non reclame (negligence). */
    case NON_DISTRIBUE = 'non_distribue';

    /** Rejet API / erreur technique cote prestataire. */
    case ERREUR = 'erreur';

    public function libelle(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::DEPOSE => 'Déposé',
            self::POSTE => 'Posté',
            self::DISTRIBUE => 'Distribué',
            self::NON_DISTRIBUE => 'Non distribué',
            self::ERREUR => 'Erreur',
        };
    }

    /** Etat final (plus d'evolution attendue) -> le suivi peut cesser de l'interroger. */
    public function estFinal(): bool
    {
        return self::DISTRIBUE === $this || self::NON_DISTRIBUE === $this || self::ERREUR === $this;
    }
}
