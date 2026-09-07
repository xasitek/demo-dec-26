<?php

declare(strict_types=1);

namespace App\Recouvrement\Controller;

use App\Recouvrement\Service\FactureSansPdfService;
use App\Shared\Entity\User;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page "Factures sans PDF" : les vraies factures echues (journaux de vente, type
 * FC/AC, perimetre des strategies actives) sans PDF Progiciel. Le comptable televerse
 * le PDF manquant ; la ligne sort de la liste et le PDF sera utilise par les
 * relances. Temps reel via Mercure (retrait a l'upload, ajout apres ETL).
 *
 * Acces : ROLE_COMPTABLE ou ROLE_MANAGER (comme le reste du module).
 */
#[Route('/recouvrement')]
final class FactureSansPdfController extends AbstractController
{
    public function __construct(private readonly FactureSansPdfService $factures)
    {
    }

    #[Route('/factures-sans-pdf', name: 'app_recouvrement_factures_sans_pdf', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        $q = trim((string) $request->query->get('q', ''));
        $lignes = $this->factures->lister('' !== $q ? $q : null);

        // Fragment (?fragment=1) : uniquement le corps du tableau, pour le
        // rechargement en direct apres un rafraichissement ETL (Mercure).
        if ($request->query->getBoolean('fragment')) {
            return $this->render('recouvrement/factures_sans_pdf/_lignes.html.twig', ['factures' => $lignes]);
        }

        return $this->render('recouvrement/factures_sans_pdf/index.html.twig', [
            'factures' => $lignes,
            'total' => \count($lignes),
            'q' => $q,
        ]);
    }

    #[Route('/factures-sans-pdf/upload', name: 'app_recouvrement_facture_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('facture-upload', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Jeton de securite invalide.'], Response::HTTP_FORBIDDEN);
        }

        $ecritureId = trim((string) $request->request->get('ecriture_id', ''));
        if ('' === $ecritureId) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Facture non identifiee.'], Response::HTTP_BAD_REQUEST);
        }

        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Aucun fichier recu.'], Response::HTTP_BAD_REQUEST);
        }

        $contenu = @file_get_contents($fichier->getPathname());
        if (false === $contenu) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Lecture du fichier impossible.'], Response::HTTP_BAD_REQUEST);
        }

        $nom = $fichier->getClientOriginalName();
        $user = $this->getUser();

        try {
            $this->factures->televerser($ecritureId, $contenu, '' !== $nom ? $nom : 'facture.pdf', $user instanceof User ? $user : null);
        } catch (RuntimeException $e) {
            return new JsonResponse(['ok' => false, 'erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true, 'ecriture_id' => $ecritureId]);
    }

    private function denyUnlessRecouvrement(): void
    {
        if (!$this->isGranted('ROLE_COMPTABLE') && !$this->isGranted('ROLE_MANAGER')) {
            throw $this->createAccessDeniedException('Acces reserve au service recouvrement.');
        }
    }
}
