<?php

declare(strict_types=1);

namespace App\Garanties\Controller\Api;

use App\Garanties\Service\DocumentIngestion;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * API d'upload des documents constructeur (PDF), utilisee par le RPA.
 *
 * Auth : jeton Bearer (env GARANTIES_API_TOKEN) via ApiTokenAuthenticator sur
 * le firewall `api`. Reserve ROLE_API. Voir docs/SECURITY.md.
 *
 * Cas pilote : recapitulatif des avis de credit/debit OPEL. Le robot envoie le
 * PDF + une reference unique qu'il ecrit aussi dans la colonne « document » du
 * Sheet ; la liaison aux dossiers est faite par la sync (GarantiesSheetSync).
 */
#[Route('/api/garanties', name: 'api_garanties_')]
#[IsGranted('ROLE_API')]
final class DocumentApiController extends AbstractController
{
    /** Taille max d'un PDF accepte (octets). */
    private const TAILLE_MAX = 15_000_000;

    public function __construct(
        private readonly DocumentIngestion $ingestion,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Upload (multipart/form-data) d'un document.
     *
     * Champs :
     *   fichier        (file, requis)   le PDF
     *   reference      (string, requis) cle de liaison unique
     *   marque         (string, requis)
     *   type_document  (string, opt.)
     *   compte         (string, opt.)   login/account_key source
     *   concession     (string, opt.)
     *   periode_debut  (string, opt.)   AAAA-MM-JJ
     *   periode_fin    (string, opt.)   AAAA-MM-JJ
     *   source         (string, opt.)
     *
     * Reponse 200 :
     *   { "document_id": int, "reference": string, "deja_present": bool, "taille": int }
     */
    #[Route('/documents/upload', name: 'document_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $reference = trim((string) $request->request->get('reference', ''));
        $marque = trim((string) $request->request->get('marque', ''));
        if ('' === $reference) {
            return $this->erreur('Champ "reference" obligatoire.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' === $marque) {
            return $this->erreur('Champ "marque" obligatoire.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile) {
            return $this->erreur('Champ "fichier" obligatoire (multipart/form-data).', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$fichier->isValid()) {
            return $this->erreur('Upload invalide : '.$fichier->getErrorMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($fichier->getSize() > self::TAILLE_MAX) {
            return $this->erreur(sprintf('Fichier trop volumineux (max %d octets).', self::TAILLE_MAX), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        // Type reel detecte sur le contenu (pas l'extension cliente).
        $mime = (string) $fichier->getMimeType();
        if ('application/pdf' !== $mime) {
            return $this->erreur('Seuls les PDF sont acceptes (type detecte : '.($mime ?: 'inconnu').').', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $contenu = (string) file_get_contents($fichier->getPathname());
        if ('' === $contenu) {
            return $this->erreur('Fichier vide.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $resultat = $this->ingestion->ingerer(
                $reference,
                $contenu,
                $fichier->getClientOriginalName(),
                $mime,
                $marque,
                $this->champ($request, 'type_document'),
                $this->champ($request, 'compte'),
                $this->champ($request, 'concession'),
                $this->champ($request, 'periode_debut'),
                $this->champ($request, 'periode_fin'),
                $this->champ($request, 'source'),
            );
        } catch (Throwable $e) {
            $this->logger->error('API upload document : echec', [
                'reference' => $reference,
                'marque' => $marque,
                'exception' => $e,
            ]);

            return $this->erreur('Erreur interne lors du traitement du document.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'document_id' => $resultat['document_id'],
            'reference' => $resultat['reference'],
            'deja_present' => $resultat['deja_present'],
            'taille' => $resultat['taille'],
        ]);
    }

    private function champ(Request $request, string $nom): ?string
    {
        $valeur = trim((string) $request->request->get($nom, ''));

        return '' === $valeur ? null : $valeur;
    }

    private function erreur(string $message, int $statut): JsonResponse
    {
        return new JsonResponse(['error' => 'Validation', 'message' => $message], $statut);
    }
}
