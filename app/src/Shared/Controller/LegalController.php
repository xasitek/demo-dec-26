<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\User;
use App\Shared\Legal\LegalDocuments;
use App\Shared\Repository\LegalAcceptanceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pages legales (CGU, confidentialite, mentions) et enregistrement de
 * l'acceptation. Voir docs/SECURITY.md.
 */
#[Route('/legal')]
#[IsGranted('ROLE_USER')]
final class LegalController extends AbstractController
{
    public function __construct(
        private readonly LegalAcceptanceRepository $acceptances,
    ) {
    }

    #[Route('/cgu', name: 'app_legal_cgu', methods: ['GET'])]
    public function cgu(): Response
    {
        return $this->render('legal/cgu.html.twig', [
            'version' => LegalDocuments::VERSIONS[LegalDocuments::CGU],
        ]);
    }

    #[Route('/confidentialite', name: 'app_legal_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('legal/confidentialite.html.twig', [
            'version' => LegalDocuments::VERSIONS[LegalDocuments::CONFIDENTIALITE],
        ]);
    }

    #[Route('/mentions', name: 'app_legal_mentions', methods: ['GET'])]
    public function mentions(): Response
    {
        return $this->render('legal/mentions.html.twig');
    }

    #[Route('/accepter', name: 'app_legal_accepter', methods: ['POST'])]
    public function accepter(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('legal_accepter', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->acceptances->enregistrerCourantes($user, $request->getClientIp());

        return $this->redirect($request->headers->get('referer') ?: '/');
    }
}
