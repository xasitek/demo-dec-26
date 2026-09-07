<?php

declare(strict_types=1);

namespace App\Recouvrement\Message;

/**
 * Ordre asynchrone d'envoi POSTAL d'une relance deja preparee (vecteur COURRIER).
 *
 * Le RelanceEnvoi porte deja le PDF fusionne (courrier_pdf) et le niveau (=> type de
 * courrier). On ajoute le destinataire + l'adresse postale, qui ne sont pas stockes
 * sur l'entite (ils viennent du groupe au moment du dispatch). Le handler, cote
 * worker interne, depose le courrier chez le prestataire et met a jour le suivi.
 */
final class EnvoyerCourrierPostal
{
    public function __construct(
        public readonly int $relanceId,
        public readonly string $destinataireNom,
        public readonly string $adresse,
    ) {
    }
}
