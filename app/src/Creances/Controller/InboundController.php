<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\EmailReponse;
use App\Creances\Repository\EmailReponseRepository;
use App\Creances\Repository\RelanceEnvoiRepository;
use App\Creances\Service\RecouvrementNotifier;
use App\Creances\Service\ScoringIaService;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des "retours" (reponses recues a une relance) + declenchement du
 * scoring IA. Tout est sous ROLE_COMPTABLE.
 *
 * Sources de retours supportees en V1 :
 *  - Saisie manuelle depuis la fiche tiers ou la page dediee
 *
 * Sources prevues en V2 (necessite config infra cote responsable technique) :
 *  - Webhook mail-inbound (Mailgun / SES qui forward les replies)
 *  - Sync IMAP / Microsoft Graph
 */
#[Route('/creances')]
#[IsGranted('ROLE_COMPTABLE')]
final class InboundController extends AbstractController
{
    public function __construct(
        private readonly EmailReponseRepository $reponses,
        private readonly RelanceEnvoiRepository $envois,
        private readonly ScoringIaService $scoringIa,
        private readonly RecouvrementNotifier $notifier,
    ) {
    }

    // ============================================================
    // Reponses recues
    // ============================================================

    #[Route('/reponses', name: 'app_creances_reponses', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('creances/reponses.html.twig', [
            'non_traites' => $this->reponses->findNonTraites(50),
            'nb_non_traites' => $this->reponses->countNonTraites(),
        ]);
    }

    #[Route('/reponse/saisir', name: 'app_creances_reponse_saisir', methods: ['POST'])]
    public function saisir(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_reponse_saisir');
        $compteCode = self::texte($request->request->get('compte_code'));
        $corps = self::texte($request->request->get('corps'));
        if (null === $compteCode || null === $corps) {
            $this->addFlash('warning', 'Compte et corps de la reponse sont obligatoires.');

            return $this->redirectToRoute('app_creances_reponses');
        }
        $envoiId = $request->request->getInt('relance_envoi_id');
        $envoi = $envoiId > 0 ? $this->envois->find($envoiId) : null;

        $reponse = new EmailReponse($compteCode, 'manuel', self::date($request->request->get('recu_le')));
        $reponse->setRelanceEnvoi($envoi);
        $reponse->setEcritureNumero(self::texte($request->request->get('ecriture_numero')));
        $reponse->setSujet(self::texte($request->request->get('sujet')));
        $reponse->setExpediteur(self::texte($request->request->get('expediteur')));
        $reponse->setCorpsTexte($corps);
        $reponse->setCategorie(self::texte($request->request->get('categorie')));
        $this->reponses->save($reponse);

        $this->notifier->notifierTiers($compteCode, 'reponse_recue', ['reponse_id' => $reponse->getId()]);
        $this->addFlash('success', 'Reponse enregistree.');

        return $this->redirectToRoute('app_creances_reponses');
    }

    #[Route('/reponse/{id}/traiter', name: 'app_creances_reponse_traiter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function traiter(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_reponse_traiter_'.$id);
        $reponse = $this->reponses->find($id);
        if (null === $reponse) {
            throw new NotFoundHttpException('Reponse introuvable.');
        }
        $reponse->traiter($this->utilisateurCourant(), self::texte($request->request->get('commentaire')));
        $this->reponses->save($reponse);
        $this->addFlash('success', 'Reponse marquee comme traitee.');

        return $this->redirectToRoute('app_creances_reponses');
    }

    // ============================================================
    // Scoring IA
    // ============================================================

    #[Route('/tiers/{code}/scorer-ia', name: 'app_creances_scorer_ia', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function scorer(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_scorer_ia_'.$code);

        if (!$this->scoringIa->configure()) {
            $this->addFlash('warning', 'Le scoring IA n\'est pas configure (variable d\'environnement ANTHROPIC_API_KEY absente). Une cle API doit etre posee par l\'administrateur.');

            return $this->redirectFiche($code);
        }
        $score = $this->scoringIa->score($code);
        if (null === $score) {
            $this->addFlash('warning', 'Impossible de calculer le score IA (voir les logs).');
        } else {
            $this->addFlash('success', sprintf('Score IA mis a jour : %s / 10.', $score->getScore()));
            $this->notifier->notifierTiers($code, 'score_ia_calcule', ['score' => $score->getScore()]);
        }

        return $this->redirectFiche($code);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function verifierCsrf(Request $request, string $intention): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intention, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function utilisateurCourant(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }

    private function redirectFiche(string $code): Response
    {
        return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=actions');
    }

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    private static function date(mixed $valeur): ?DateTimeImmutable
    {
        if (!is_string($valeur) || '' === trim($valeur)) {
            return null;
        }
        try {
            return new DateTimeImmutable($valeur);
        } catch (Exception) {
            return null;
        }
    }
}
