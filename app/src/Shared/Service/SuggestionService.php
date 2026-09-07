<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Shared\Entity\Suggestion;
use App\Shared\Entity\SuggestionVote;
use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use App\Shared\Exception\LimiteSuggestionsAtteinte;
use App\Shared\Repository\SuggestionRepository;
use App\Shared\Repository\SuggestionVoteRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Boite a idees : creation d'une remontee, vote sur une idee, traitement par un
 * administrateur. Toute la regle metier vit ici — les controleurs ne font que
 * valider l'entree et rendre la reponse.
 *
 * Voir docs/ARCHITECTURE.md (socle transverse) et docs/REALTIME.md (topic public).
 */
final class SuggestionService
{
    /**
     * Topic du mur d'idees. Public : ne transporte qu'un identifiant et un
     * compteur de votes, jamais le texte d'une remontee.
     */
    public const TOPIC_MUR = 'fc-finance:suggestions';

    /** Garde-fou anti-abus : envois maximum par heure et par auteur. */
    public const MAX_PAR_HEURE = 5;

    /** Longueur maximale du message (tronque au-dela). */
    public const LONGUEUR_MAX = 2000;

    public function __construct(
        private readonly SuggestionRepository $suggestions,
        private readonly SuggestionVoteRepository $votes,
        private readonly NotificationService $notifications,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Enregistre une remontee et previent les administrateurs.
     *
     * @param string|null $route route Symfony de la page d'origine (contexte)
     * @param string|null $url   chemin de la page d'origine (contexte)
     *
     * @throws LimiteSuggestionsAtteinte si le quota horaire de l'auteur est atteint
     */
    public function creer(
        User $auteur,
        TypeSuggestion $type,
        string $message,
        ?string $route = null,
        ?string $url = null,
    ): Suggestion {
        $message = trim($message);
        if (mb_strlen($message) > self::LONGUEUR_MAX) {
            $message = mb_substr($message, 0, self::LONGUEUR_MAX);
        }

        $uneHeureAvant = new DateTimeImmutable('-1 hour');
        if ($this->suggestions->compterDepuis($auteur, $uneHeureAvant) >= self::MAX_PAR_HEURE) {
            throw new LimiteSuggestionsAtteinte(self::MAX_PAR_HEURE);
        }

        $suggestion = new Suggestion(
            $auteur,
            $type,
            $message,
            self::moduleDepuisUrl($url),
            $route,
            null === $url ? null : mb_substr($url, 0, 1024),
        );
        $this->suggestions->save($suggestion);

        $this->prevenirAdministrateurs($suggestion);

        if ($suggestion->estSurLeMur()) {
            $this->publierMur('nouvelle', $suggestion, $suggestion->getNbVotes());
        }

        return $suggestion;
    }

    /**
     * Ajoute ou retire le soutien de l'utilisateur a une idee (idempotent).
     *
     * @return array{vote: bool, votes: int} vote = l'utilisateur soutient l'idee
     *                                       apres l'operation
     */
    public function basculerVote(Suggestion $suggestion, User $votant): array
    {
        $id = $suggestion->getId();
        if (null === $id) {
            return ['vote' => false, 'votes' => $suggestion->getNbVotes()];
        }

        $existant = $this->votes->trouver($suggestion, $votant);

        if (null !== $existant) {
            $this->votes->remove($existant);
            $votes = $this->suggestions->ajusterVotes($id, -1);
            $this->publierMur('vote', $suggestion, $votes);

            return ['vote' => false, 'votes' => $votes];
        }

        try {
            $this->votes->save(new SuggestionVote($suggestion, $votant));
        } catch (Throwable $e) {
            // Course entre deux clics : la contrainte d'unicite a tranche. L'etat
            // voulu (« je soutiens ») est deja atteint, on ne recompte pas.
            $this->logger->info('Vote suggestion deja enregistre : {message}', ['message' => $e->getMessage()]);

            return ['vote' => true, 'votes' => $suggestion->getNbVotes()];
        }

        $votes = $this->suggestions->ajusterVotes($id, 1);
        $this->publierMur('vote', $suggestion, $votes);

        return ['vote' => true, 'votes' => $votes];
    }

    /**
     * Decision d'un administrateur. La boucle de retour est le coeur du dispositif :
     * l'auteur est notifie (temps reel) de ce qui a ete fait de sa remontee.
     */
    public function traiter(
        Suggestion $suggestion,
        StatutSuggestion $statut,
        ?string $reponse,
        User $administrateur,
    ): void {
        $suggestion->traiter($statut, $reponse, $administrateur);
        $this->suggestions->save($suggestion);

        $auteur = $suggestion->getAuteur();
        if (null !== $auteur && $statut->meriteNotification()) {
            $this->notifications->notifier(
                $auteur,
                sprintf('Votre %s : %s', mb_strtolower($suggestion->getType()->libelle()), $statut->libelle()),
                $suggestion->getReponse() ?? self::extrait($suggestion->getMessage()),
                $suggestion->estSurLeMur() ? '/suggestions' : null,
                $statut->tonaliteNotification(),
            );
        }

        if ($suggestion->estSurLeMur()) {
            $this->publierMur('statut', $suggestion, $suggestion->getNbVotes());
        }
    }

    /**
     * Module d'origine, deduit du chemin de la page d'envoi. Accepte un chemin
     * ou une URL absolue.
     */
    public static function moduleDepuisUrl(?string $url): ?Module
    {
        if (null === $url || '' === $url) {
            return null;
        }

        $chemin = parse_url($url, \PHP_URL_PATH);
        if (!\is_string($chemin) || '' === $chemin) {
            $chemin = $url;
        }

        foreach (Module::cases() as $module) {
            if (str_starts_with($chemin, $module->prefixe())) {
                return $module;
            }
        }

        return null;
    }

    private function prevenirAdministrateurs(Suggestion $suggestion): void
    {
        $contexte = $suggestion->getModule()?->libelle();

        $this->notifications->notifierRole(
            'ROLE_ADMIN',
            sprintf('%s de %s', $suggestion->getType()->libelle(), $suggestion->getAuteurNom()),
            null === $contexte
                ? self::extrait($suggestion->getMessage())
                : sprintf('%s — %s', $contexte, self::extrait($suggestion->getMessage())),
            '/admin/suggestions',
            TypeSuggestion::ANOMALIE === $suggestion->getType() ? 'alerte' : 'info',
        );
    }

    /**
     * Publication best-effort : un hub indisponible ne doit jamais faire echouer
     * l'envoi d'une idee (cf. docs/REALTIME.md).
     */
    private function publierMur(string $action, Suggestion $suggestion, int $votes): void
    {
        try {
            $this->hub->publish(new Update(
                self::TOPIC_MUR,
                json_encode([
                    'type' => 'suggestion',
                    'action' => $action,
                    'id' => $suggestion->getId(),
                    'votes' => $votes,
                    'statut' => $suggestion->getStatut()->value,
                ], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication suggestion Mercure echouee : {message}', ['message' => $e->getMessage()]);
        }
    }

    private static function extrait(string $message, int $longueur = 150): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_strlen($message) <= $longueur
            ? $message
            : mb_substr($message, 0, $longueur - 1).'…';
    }
}
