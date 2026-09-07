<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Service\PriorisationService;
use App\Creances\Service\RelanceInterneService;
use App\Shared\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vue "priorites" pour cibler rapidement les comptes a fort enjeu :
 *  - top criticite (gros encours + vieux)
 *  - inactifs (rien fait depuis 15 jours)
 *  - relance interne (alerter secretaire/vendeur/directeur)
 */
#[Route('/creances')]
#[IsGranted('ROLE_COMPTABLE')]
final class PrioritesController extends AbstractController
{
    public function __construct(
        private readonly PriorisationService $priorisation,
        private readonly RelanceInterneService $relanceInterne,
    ) {
    }

    #[Route('/priorites', name: 'app_creances_priorites', methods: ['GET'])]
    public function priorites(): Response
    {
        return $this->render('creances/priorites.html.twig', [
            'top_criticite' => $this->priorisation->topCriticite(30),
        ]);
    }

    #[Route('/inactifs', name: 'app_creances_inactifs', methods: ['GET'])]
    public function inactifs(Request $request): Response
    {
        $jours = max(1, min(180, $request->query->getInt('jours', PriorisationService::SEUIL_INACTIVITE_JOURS)));

        return $this->render('creances/inactifs.html.twig', [
            'comptes' => $this->priorisation->comptesInactifs($jours, 100),
            'jours' => $jours,
            'seuil_defaut' => PriorisationService::SEUIL_INACTIVITE_JOURS,
        ]);
    }

    /**
     * Envoie une alerte interne aux equipes Synthauto qui connaissent le client
     * (secretaire, vendeur, directeur). On extrait les destinataires
     * deja connus du mirror Progiciel et on permet la saisie libre.
     */
    #[Route('/tiers/{code}/alerter-interne', name: 'app_creances_alerter_interne', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function alerterInterne(string $code, Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('creances_alerter_interne_'.$code, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $emailsRaw = (string) $request->request->get('emails', '');
        $emails = [];
        foreach (preg_split('/[\s,;]+/', $emailsRaw) ?: [] as $e) {
            $e = trim($e);
            if ('' !== $e) {
                $emails[] = $e;
            }
        }
        $commentaire = trim((string) $request->request->get('commentaire', ''));
        if ([] === $emails) {
            $this->addFlash('warning', 'Au moins un destinataire est obligatoire.');

            return $this->redirectFiche($code);
        }
        if ('' === $commentaire) {
            $this->addFlash('warning', 'Ajoute un commentaire pour expliquer ta demande a l\'equipe interne.');

            return $this->redirectFiche($code);
        }

        $auteur = $this->getUser();
        $resultat = $this->relanceInterne->alerter(
            $code,
            $emails,
            $commentaire,
            $auteur instanceof User ? $auteur : null,
        );

        if ($resultat['envoyes'] > 0) {
            $this->addFlash('success', sprintf(
                '%d alerte(s) envoyee(s)%s + action tracee sur la fiche.',
                $resultat['envoyes'],
                $resultat['erreurs'] > 0 ? ' ('.$resultat['erreurs'].' en erreur)' : '',
            ));
        } else {
            $this->addFlash('warning', 'Aucune alerte envoyee (verifie les adresses ou les logs).');
        }

        return $this->redirectFiche($code);
    }

    private function redirectFiche(string $code): Response
    {
        return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=actions');
    }
}
