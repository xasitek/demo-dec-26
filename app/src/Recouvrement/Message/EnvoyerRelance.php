<?php

declare(strict_types=1);

namespace App\Recouvrement\Message;

use App\Recouvrement\Service\SelectionRelanceService;

/**
 * Ordre asynchrone d'envoi d'une relance (groupee par compte) deja preparee.
 *
 * Le RelanceEnvoi est cree (statut A_ENVOYER) cote commande AVANT le dispatch :
 * le message ne porte donc que son identifiant + les donnees de rendu figees au
 * moment de la preparation (le groupe de factures du compte selectionne). Le
 * handler charge l'entite par son id, l'envoie et la passe a ENVOYE / ECHEC.
 *
 * Pourquoi figer le groupe dans le message plutot que le re-lire depuis
 * v_impayes : le contenu de la relance (factures, total, retard) reflete l'etat
 * decide a la preparation, et le handler n'a pas a re-requeter Progiciel.
 *
 * @phpstan-import-type GroupeARelancer from SelectionRelanceService
 */
final class EnvoyerRelance
{
    /**
     * @param int             $relanceId id du RelanceEnvoi pre-cree (A_ENVOYER)
     * @param GroupeARelancer $groupe    donnees de rendu figees a la preparation
     */
    public function __construct(
        public readonly int $relanceId,
        public readonly array $groupe,
    ) {
    }
}
