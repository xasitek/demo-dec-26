<?php

declare(strict_types=1);

namespace App\Garanties\Controller\Api;

use App\Garanties\Service\GarantiesScrapImporter;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * API d'ingestion du scrap des dossiers de garantie (utilise par le RPA Fiat).
 *
 * Auth : jeton Bearer (env GARANTIES_API_TOKEN), via ApiTokenAuthenticator
 * sur le firewall `api`. Reserve ROLE_API. Voir docs/SECURITY.md.
 *
 * Equivalence avec la fonction Python `_statut_upsert_dg_rows` du RPA :
 * meme contrat (un appel par bucket concession/marque/emetteur, idempotent,
 * marquage des introuvables, detection des transitions de statut).
 */
#[Route('/api/garanties', name: 'api_garanties_')]
#[IsGranted('ROLE_API')]
final class ScrapApiController extends AbstractController
{
    private const MAX_ROWS_PAR_REQUETE = 5000;

    public function __construct(
        private readonly GarantiesScrapImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Upsert d'un lot de DG scrapees (un bucket = une concession, une marque,
     * un emetteur). Marque present_dans_scrap = false les lignes du meme
     * bucket non revues dans ce run.
     *
     * Body JSON attendu :
     * {
     *   "concession": "SITE A - SITE B - SITE C",
     *   "marque": "Fiat",
     *   "emetteur": "0020341",
     *   "rows": [ { "Num DG": "1", "MVS": "...", ... }, ... ]
     * }
     *
     * Reponse 200 :
     * { "lignes": int, "nouveaux": int, "transitions_statut": int, "introuvables": int }
     */
    #[Route('/scrap/upsert', name: 'scrap_upsert', methods: ['POST'])]
    public function scrapUpsert(Request $request): JsonResponse
    {
        $payload = $this->decoderJson($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $erreur = $this->validerPayload($payload);
        if (null !== $erreur) {
            return new JsonResponse(['error' => 'Validation', 'message' => $erreur], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /* @var array{concession: string, marque: string, emetteur: string, rows: list<array<string, string>>} $payload */
        try {
            $stats = $this->importer->importerBucket(
                $payload['concession'],
                $payload['marque'],
                $payload['emetteur'],
                $payload['rows'],
            );
        } catch (Throwable $e) {
            $this->logger->error('API scrap upsert : echec', [
                'concession' => $payload['concession'],
                'marque' => $payload['marque'],
                'emetteur' => $payload['emetteur'],
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => 'Internal', 'message' => 'Erreur interne lors du traitement du lot.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return new JsonResponse([
            'lignes' => $stats['lignes'],
            'nouveaux' => $stats['nouveaux'],
            'transitions_statut' => $stats['transitions'],
            'introuvables' => $stats['introuvables'],
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decoderJson(Request $request): array|JsonResponse
    {
        $brut = (string) $request->getContent();
        if ('' === $brut) {
            return new JsonResponse(['error' => 'BadRequest', 'message' => 'Corps vide.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $decode = json_decode($brut, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new JsonResponse(['error' => 'BadRequest', 'message' => 'JSON invalide : '.$e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        if (!\is_array($decode)) {
            return new JsonResponse(['error' => 'BadRequest', 'message' => 'Le corps doit etre un objet JSON.'], Response::HTTP_BAD_REQUEST);
        }

        return $decode;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validerPayload(array $payload): ?string
    {
        foreach (['concession', 'marque', 'emetteur'] as $champ) {
            if (!isset($payload[$champ]) || !\is_string($payload[$champ]) || '' === trim($payload[$champ])) {
                return sprintf('Champ "%s" obligatoire (chaine non vide).', $champ);
            }
        }
        if (!isset($payload['rows']) || !\is_array($payload['rows'])) {
            return 'Champ "rows" obligatoire (tableau).';
        }
        if (\count($payload['rows']) > self::MAX_ROWS_PAR_REQUETE) {
            return sprintf('Trop de lignes : %d (max %d par requête).', \count($payload['rows']), self::MAX_ROWS_PAR_REQUETE);
        }
        foreach ($payload['rows'] as $i => $row) {
            if (!\is_array($row)) {
                return sprintf('rows[%d] : doit etre un objet.', $i);
            }
        }

        return null;
    }
}
