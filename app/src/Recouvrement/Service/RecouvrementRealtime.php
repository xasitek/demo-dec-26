<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\RetourClient;
use App\Recouvrement\Enum\StatutPreparation;
use App\Shared\Enum\Module;
use App\Shared\Service\NotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Temps reel a l'arrivee d'un nouveau retour client (reponse a une relance) :
 *   1. Cloche : une notification in-app par destinataire (NotificationService
 *      publie sur le topic prive de chacun -> badge + liste en direct) ;
 *   2. Liste des retours : publie l'id du nouveau retour sur un topic partage ;
 *      la page (contoleur Stimulus "retours") va chercher la ligne rendue et
 *      l'insere en tete, sans ouvrir de 2e connexion Mercure.
 *
 * Publication best-effort : un hub indisponible ne bloque jamais l'ingestion.
 * Voir docs/REALTIME.md.
 */
final class RecouvrementRealtime
{
    /**
     * Topic partage "un nouveau retour est arrive". Public : ne transporte qu'un
     * id (la ligne complete est ensuite chargee par une requete authentifiee).
     */
    public const TOPIC_RETOURS = 'fc-finance:recouvrement:retours';

    /**
     * Topic partage "factures sans PDF" : upload (retrait d'une ligne) et
     * rafraichissement apres ETL (nouvelles factures). Public : ne transporte qu'une
     * action et, pour un retrait, l'ecriture_id concerne.
     */
    public const TOPIC_FACTURES_SANS_PDF = 'fc-finance:recouvrement:factures-sans-pdf';

    /**
     * Topic partage "progression d'un lancement manuel de strategie" : total,
     * nombre traite, statut. Alimente la barre de progression GLOBALE (bandeau),
     * persistante au changement de page. Public : ne transporte que des compteurs.
     */
    public const TOPIC_PREPARATION = 'fc-finance:recouvrement:preparation';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly NotificationService $notifications,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A appeler apres la creation d'un NOUVEAU retour (pas un doublon).
     */
    public function signalerNouveauRetour(RetourClient $retour): void
    {
        // 1. Liste : signale l'id pour insertion en direct de la ligne.
        $this->publierRetour($retour);

        // 2. Cloche : notification aux utilisateurs rattaches au module Recouvrement.
        $this->notifications->notifierModule(
            Module::RECOUVREMENT,
            'Nouveau retour client',
            $this->resume($retour),
            $this->urls->generate('app_recouvrement_index'),
            'info',
        );
    }

    /**
     * Une facture vient de recevoir son PDF televerse : elle sort de la liste
     * "factures sans PDF" (retrait en direct sur tous les onglets ouverts).
     */
    public function signalerFactureUploadee(string $ecritureId): void
    {
        $this->publierFacture(['type' => 'facture_sans_pdf', 'action' => 'remove', 'ecriture_id' => $ecritureId]);
    }

    /**
     * La vue materialisee des impayes vient d'etre rafraichie (ETL) : de nouvelles
     * factures sans PDF ont pu apparaitre -> invite les pages ouvertes a recharger
     * la liste.
     */
    public function signalerRafraichissementFactures(): void
    {
        $this->publierFacture(['type' => 'facture_sans_pdf', 'action' => 'refresh']);
    }

    /**
     * Progression d'un lancement manuel de strategie : alimente la barre globale.
     * A appeler au demarrage (total connu), regulierement pendant, et a la fin.
     * Prend des scalaires (pas l'entite) : le handler suit la progression avec des
     * compteurs locaux et ecrit en DBAL direct. Best-effort : un hub indisponible ne
     * bloque jamais la preparation.
     */
    public function signalerProgressionPreparation(int $runId, string $regleNom, StatutPreparation $statut, int $total, int $traites): void
    {
        $pct = $total <= 0 ? 100 : (int) min(100, floor(100 * $traites / $total));

        $this->publierPreparation([
            'type' => 'preparation',
            'run_id' => (string) $runId,
            'statut' => $statut->value,
            'regle_nom' => $regleNom,
            'total' => $total,
            'traites' => $traites,
            'pct' => $pct,
            'termine' => StatutPreparation::EN_COURS !== $statut,
        ]);
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function publierPreparation(array $donnees): void
    {
        try {
            $this->hub->publish(new Update(
                self::TOPIC_PREPARATION,
                json_encode($donnees, \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication progression preparation Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, string> $donnees
     */
    private function publierFacture(array $donnees): void
    {
        try {
            $this->hub->publish(new Update(
                self::TOPIC_FACTURES_SANS_PDF,
                json_encode($donnees, \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication facture-sans-pdf Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    private function publierRetour(RetourClient $retour): void
    {
        $id = $retour->getId();
        if (null === $id) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                self::TOPIC_RETOURS,
                json_encode(['type' => 'retour', 'id' => $id], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication retour Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Resume court pour la cloche : expediteur et/ou compte, sinon libelle neutre.
     */
    private function resume(RetourClient $retour): string
    {
        $qui = trim((string) $retour->getExpediteur());
        $compte = trim((string) $retour->getCompteCode());
        $parts = array_values(array_filter([
            '' !== $qui ? $qui : null,
            '' !== $compte ? $compte : null,
        ]));

        return [] !== $parts ? implode(' · ', $parts) : 'Réponse reçue';
    }
}
