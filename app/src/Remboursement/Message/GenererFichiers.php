<?php

declare(strict_types=1);

namespace App\Remboursement\Message;

/**
 * Demande de generation des fichiers comptables d'un dossier (asynchrone). Dispatch
 * automatiquement quand un dossier est valide par le directeur (VALIDE_DIRECTEUR) :
 * un worker genere le CSV OD + le SEPA, les attache en pieces et passe le dossier
 * en « En cours de paiement ». Meme file dediee que l'analyse IA (transport remboursement).
 */
final readonly class GenererFichiers
{
    public function __construct(
        public int $dossierId,
    ) {
    }
}
