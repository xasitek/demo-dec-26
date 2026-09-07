<?php

declare(strict_types=1);

namespace App\Recouvrement\Postal;

use App\Recouvrement\Enum\StatutCourrier;

/**
 * Contrat d'un expediteur de courrier postal (vecteur COURRIER). Une implementation
 * par prestataire (Maileva, La Poste...) + un simulateur pour les tests. Permet de
 * changer de prestataire sans toucher au reste du module.
 */
interface CourrierPostalSender
{
    /**
     * Depose un courrier chez le prestataire. Ne doit jamais lever : en cas d'echec,
     * renvoyer un ResultatCourrier en statut ERREUR (le handler gerera le retry).
     */
    public function envoyer(CourrierAEnvoyer $courrier): ResultatCourrier;

    /**
     * Statut courant d'un courrier deja depose (suivi/AR), ou null si le prestataire
     * ne sait pas repondre. Utilise par la commande de suivi periodique.
     */
    public function statut(string $reference): ?StatutCourrier;

    /** Nom court du prestataire (ex. "maileva", "simule"). */
    public function nom(): string;
}
