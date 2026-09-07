<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\EnvoiProgramme;
use App\Creances\Repository\EnvoiProgrammeRepository;
use App\Creances\Service\ReportingService;
use App\Shared\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des rapports recurrents (DSO mensuel, balance agee hebdo, etc.).
 *
 * Limitations V1 :
 * - Le cron auto sera ajoute via Symfony Scheduler en V2. Pour l'instant,
 *   l'utilisateur lance les envois manuellement avec le bouton "Envoyer
 *   maintenant" depuis la liste.
 * - Format e-mail uniquement (HTML inline). Pas de PDF (bloque par la responsable technique).
 */
#[Route('/creances/rapports')]
#[IsGranted('ROLE_COMPTABLE')]
final class EnvoiProgrammeController extends AbstractController
{
    public function __construct(
        private readonly EnvoiProgrammeRepository $envois,
        private readonly ReportingService $reporting,
    ) {
    }

    #[Route('', name: 'app_creances_rapports', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('creances/rapports.html.twig', [
            'envois' => $this->envois->findToutes(),
        ]);
    }

    #[Route('/creer', name: 'app_creances_rapport_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_rapport_creer');
        $libelle = self::texte($request->request->get('libelle'));
        $type = self::texte($request->request->get('type'));
        $frequence = self::texte($request->request->get('frequence'));
        if (null === $libelle || null === $type || null === $frequence) {
            $this->addFlash('warning', 'Libelle, type et frequence sont obligatoires.');

            return $this->redirectToRoute('app_creances_rapports');
        }
        $destinatairesRaw = self::texte($request->request->get('destinataires')) ?? '';
        $destinataires = [];
        foreach (preg_split('/[\s,;]+/', $destinatairesRaw) ?: [] as $email) {
            $email = trim($email);
            if ('' !== $email && filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $destinataires[] = $email;
            }
        }
        if ([] === $destinataires) {
            $this->addFlash('warning', 'Au moins un destinataire valide est obligatoire.');

            return $this->redirectToRoute('app_creances_rapports');
        }

        $envoi = new EnvoiProgramme($libelle, $type, $frequence, $this->utilisateurCourant());
        $envoi->setDestinataires($destinataires);
        $this->envois->save($envoi);
        $this->addFlash('success', 'Rapport programme cree.');

        return $this->redirectToRoute('app_creances_rapports');
    }

    #[Route('/{id}/envoyer', name: 'app_creances_rapport_envoyer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function envoyer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_rapport_envoyer_'.$id);
        $envoi = $this->envois->find($id);
        if (null === $envoi) {
            throw new NotFoundHttpException('Rapport introuvable.');
        }

        $n = $this->reporting->envoyer($envoi);
        $this->envois->save($envoi);
        $this->addFlash('success', sprintf('%d destinataire(s) atteint(s).', $n));

        return $this->redirectToRoute('app_creances_rapports');
    }

    #[Route('/{id}/apercu', name: 'app_creances_rapport_apercu', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function apercu(int $id): Response
    {
        $envoi = $this->envois->find($id);
        if (null === $envoi) {
            throw new NotFoundHttpException('Rapport introuvable.');
        }

        return new Response($this->reporting->genererHtml($envoi->getType(), $envoi->getLibelle()));
    }

    #[Route('/{id}/supprimer', name: 'app_creances_rapport_supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_rapport_supprimer_'.$id);
        $envoi = $this->envois->find($id);
        if (null === $envoi) {
            throw new NotFoundHttpException('Rapport introuvable.');
        }
        $this->envois->remove($envoi);
        $this->addFlash('success', 'Rapport supprime.');

        return $this->redirectToRoute('app_creances_rapports');
    }

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

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }
}
