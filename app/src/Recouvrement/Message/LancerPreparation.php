<?php

declare(strict_types=1);

namespace App\Recouvrement\Message;

/**
 * Ordre asynchrone de LANCEMENT MANUEL d'une strategie (bouton "Lancer
 * maintenant" de la page Strategies) : le worker prepare les relances de la
 * regle ciblee et les dispatch, en alimentant une barre de progression
 * persistante (PreparationRun) et Mercure.
 *
 * On ne porte que l'id du run (deja cree en base, statut EN_COURS) et l'id de la
 * regle : le handler recharge tout et fait le travail long cote worker, jamais
 * dans la requete HTTP.
 */
final class LancerPreparation
{
    /**
     * @param int      $runId   id du PreparationRun pre-cree (EN_COURS)
     * @param int|null $regleId regle ciblee (null = toutes les strategies actives)
     * @param int|null $limit   plafond de comptes pour ce run (null = pas de plafond)
     */
    public function __construct(
        public readonly int $runId,
        public readonly ?int $regleId,
        public readonly ?int $limit = null,
    ) {
    }
}
