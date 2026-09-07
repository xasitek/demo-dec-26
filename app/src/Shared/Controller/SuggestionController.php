<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\Suggestion;
use App\Shared\Entity\User;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use App\Shared\Exception\LimiteSuggestionsAtteinte;
use App\Shared\Repository\SuggestionRepository;
use App\Shared\Repository\SuggestionVoteRepository;
use App\Shared\Service\SuggestionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Boite a idees, cote utilisateur : l'ampoule presente sur toutes les pages, et
 * le mur des idees soumises au vote. Ouverte a tous les roles — c'est le terrain
 * (secretaires, comptables, directeurs) qui sait ce qui manque.
 */
#[Route('/suggestions')]
#[IsGranted('ROLE_USER')]
final class SuggestionController extends AbstractController
{
    /**
     * Le mur : les idees, triees par soutien ou par date. Repond aussi en
     * fragment (?fragment=1) pour le scroll infini.
     */
    #[Route('', name: 'app_suggestions_index', methods: ['GET'])]
    public function index(
        Request $request,
        SuggestionRepository $suggestions,
        SuggestionVoteRepository $votes,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $statut = StatutSuggestion::tryFrom((string) $request->query->get('statut', ''));
        $tri = SuggestionRepository::TRI_RECENTES === $request->query->get('tri')
            ? SuggestionRepository::TRI_RECENTES
            : SuggestionRepository::TRI_POPULAIRES;
        $page = max(1, $request->query->getInt('page', 1));

        $lignes = $suggestions->pageDuMur($statut, $tri, $page);
        $total = $suggestions->compterMur($statut);

        $ids = array_values(array_filter(array_map(
            static fn (Suggestion $s): ?int => $s->getId(),
            $lignes,
        )));

        $contexte = [
            'suggestions' => $lignes,
            'votes_utilisateur' => $votes->idsVotesPar($user, $ids),
        ];

        if ($request->query->getBoolean('fragment')) {
            return $this->render('suggestions/_liste.html.twig', $contexte);
        }

        return $this->render('suggestions/index.html.twig', $contexte + [
            'statut' => $statut,
            'tri' => $tri,
            'total' => $total,
            'pages' => (int) ceil($total / SuggestionRepository::PAR_PAGE),
            'statuts' => StatutSuggestion::choix(),
        ]);
    }

    /**
     * Contenu du panneau de l'ampoule. Charge au premier clic seulement : le
     * bouton est rendu sur toutes les pages de l'application, il ne doit couter
     * aucune requete SQL tant qu'on ne l'ouvre pas.
     */
    #[Route('/panneau', name: 'app_suggestions_panneau', methods: ['GET'])]
    public function panneau(Request $request, SuggestionRepository $suggestions): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $url = (string) $request->query->get('url', '');

        return $this->render('suggestions/_panneau.html.twig', [
            'types' => TypeSuggestion::cases(),
            'mes_suggestions' => $suggestions->mesSuggestions($user),
            'origine_route' => (string) $request->query->get('route', ''),
            'origine_url' => $url,
            // Contexte lisible affiche sous le champ (« Depuis Livraison »).
            'origine_module' => SuggestionService::moduleDepuisUrl($url)?->libelle(),
            'longueur_max' => SuggestionService::LONGUEUR_MAX,
        ]);
    }

    /**
     * Envoi d'une remontee. Repond en JSON a l'appel Stimulus, et retombe sur une
     * redirection + message flash si le navigateur poste le formulaire lui-meme.
     */
    #[Route('', name: 'app_suggestions_envoyer', methods: ['POST'])]
    public function envoyer(Request $request, SuggestionService $service): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('suggestion', (string) $request->request->get('_token'))) {
            return $this->echec($request, 'Jeton de sécurité invalide. Rechargez la page.', Response::HTTP_BAD_REQUEST);
        }

        $type = TypeSuggestion::tryFrom((string) $request->request->get('type', ''));
        if (null === $type) {
            return $this->echec($request, 'Choisissez le type de votre message.', Response::HTTP_BAD_REQUEST);
        }

        $message = trim((string) $request->request->get('message', ''));
        if ('' === $message) {
            return $this->echec($request, 'Votre message est vide.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $service->creer(
                $user,
                $type,
                $message,
                self::valeurOuNull($request->request->get('route')),
                self::valeurOuNull($request->request->get('url')),
            );
        } catch (LimiteSuggestionsAtteinte $e) {
            return $this->echec($request, $e->getMessage(), Response::HTTP_TOO_MANY_REQUESTS);
        }

        $confirmation = TypeSuggestion::IDEE === $type
            ? 'Merci. Votre idée est publiée sur le mur, les administrateurs sont prévenus.'
            : 'Merci. Votre message est transmis aux administrateurs.';

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'message' => $confirmation]);
        }

        $this->addFlash('success', $confirmation);

        return $this->redirect(self::retour($request));
    }

    /**
     * Soutien a une idee (bascule). Reserve aux idees : une anomalie ne se vote pas.
     */
    #[Route('/{id}/vote', name: 'app_suggestions_vote', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function vote(Request $request, Suggestion $suggestion, SuggestionService $service): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('suggestion_vote', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        if (!$suggestion->estSurLeMur()) {
            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $resultat = $service->basculerVote($suggestion, $user);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true] + $resultat);
        }

        return $this->redirectToRoute('app_suggestions_index');
    }

    /**
     * Erreur d'envoi : JSON pour l'appel Stimulus, flash + redirection sinon.
     */
    private function echec(Request $request, string $message, int $statut): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'message' => $message], $statut);
        }

        $this->addFlash('error', $message);

        return $this->redirect(self::retour($request));
    }

    /**
     * Retour sur la page d'origine (envoi sans JavaScript), en restant sur le site :
     * un chemin absolu, et jamais « //hote » qui sortirait du domaine.
     */
    private static function retour(Request $request): string
    {
        $url = self::valeurOuNull($request->request->get('url'));

        if (null === $url || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '/';
        }

        return $url;
    }

    private static function valeurOuNull(mixed $valeur): ?string
    {
        if (!\is_string($valeur)) {
            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }
}
