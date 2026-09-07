<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Repository\DossierRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;
use Twig\Environment;

/**
 * Temps reel de "Mes dossiers" (cote secretaire) : a chaque changement de statut
 * d'un dossier, on publie le badge d'etat re-rendu sur le topic PRIVE de la
 * secretaire qui l'a depose. Sa page met alors le badge de la ligne a jour en
 * direct, sans rechargement (une seule connexion Mercure par onglet, mutualisee
 * par le controleur "realtime" du layout). Voir docs/REALTIME.md.
 *
 * Topic PRIVE par createur (email) : seule cette secretaire l'a dans son cookie
 * d'autorisation Mercure -> personne d'autre ne recoit ses mises a jour.
 * Publication best-effort : un hub indisponible ne bloque jamais le workflow.
 */
final class RemboursementRealtime
{
    /** Prefixe du topic prive "mes dossiers" d'une secretaire (suffixe = son identifiant). */
    public const TOPIC_SECRETAIRE_PREFIX = 'fc-finance:remboursement:secretaire:';

    /**
     * Topic partage "un dossier vient d'arriver a verifier (comptable)". Public :
     * ne transporte qu'un id ; la ligne complete est ensuite chargee par une requete
     * authentifiee (atelier comptable), comme la liste des retours du Recouvrement.
     */
    public const TOPIC_A_VERIFIER = 'fc-finance:remboursement:a-verifier';

    /**
     * Topic partage "changement de statut" (cote comptable / encadrement) : porte l'id
     * + le badge re-rendu, pour mettre a jour le badge de la ligne EN DIRECT dans les
     * listes /remboursement, sans recharger. Public (ne transporte qu'un badge HTML).
     */
    public const TOPIC_STATUTS = 'fc-finance:remboursement:statuts';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly DossierRepository $dossiers,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Topic prive "mes dossiers" d'une secretaire (identifiant = email de connexion). */
    public static function topicSecretaire(string $identifiant): string
    {
        return self::TOPIC_SECRETAIRE_PREFIX.$identifiant;
    }

    /**
     * A appeler apres un changement de statut : pousse le badge re-rendu vers la
     * page "Mes dossiers" du deposant. Sans deposant ou sans id, on ne publie rien.
     */
    public function signalerChangementStatut(Dossier $dossier): void
    {
        $id = $dossier->getId();
        $creePar = $dossier->getCreePar();
        if (null === $id || null === $creePar || '' === $creePar) {
            return;
        }

        try {
            $badge = trim($this->twig->render('remboursement/_statut_badge_seul.html.twig', [
                'statut' => $dossier->getStatut(),
            ]));

            // On joint le compteur "a corriger" recalcule : le badge de la nav secretaire
            // se met a jour EN DIRECT (ex. la comptable vient de demander une correction).
            $this->hub->publish(new Update(
                self::topicSecretaire($creePar),
                json_encode([
                    'type' => 'remb_dossier',
                    'id' => $id,
                    'badge' => $badge,
                    'nbCorrection' => $this->dossiers->compterCorrectionRequise($creePar),
                ], \JSON_THROW_ON_ERROR),
                true,
            ));

            // Cote comptable / encadrement : badge mis a jour en direct dans /remboursement.
            $this->hub->publish(new Update(
                self::TOPIC_STATUTS,
                json_encode(['type' => 'remb_statut', 'id' => $id, 'badge' => $badge], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication statut remboursement Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * A appeler quand un dossier vient d'entrer en "a verifier" (comptable) : signale
     * son id sur le topic partage pour insertion en direct de la ligne dans l'atelier.
     */
    public function signalerNouveauAVerifier(Dossier $dossier): void
    {
        $id = $dossier->getId();
        if (null === $id) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                self::TOPIC_A_VERIFIER,
                json_encode(['type' => 'remb_a_verifier', 'id' => $id], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication a-verifier remboursement Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }
}
