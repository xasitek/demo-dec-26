<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

use App\Remboursement\Service\Ia\Exception\ProviderIndisponibleException;
use App\Remboursement\Service\Ia\Exception\ReponseNonExploitableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Provider OCR Gemini (generativelanguage:generateContent). La piece part en
 * inline_data base64 ; on impose responseMimeType=application/json + responseSchema
 * pour une reponse JSON structuree (fin du "reponds sans backticks" de N8N).
 * Cle en variable d'env uniquement (jamais committee).
 */
final class GeminiOcrProvider implements ProviderOcr
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $cle,
        #[Autowire('%env(GEMINI_MODELE)%')]
        private readonly string $modele,
    ) {
    }

    public function nom(): string
    {
        return 'gemini';
    }

    /** @param array<string, mixed> $brut */
    private function messageErreur(array $brut): string
    {
        $message = $brut['error']['message'] ?? null;

        return \is_string($message) ? $message : 'sans detail';
    }

    public function estDisponible(): bool
    {
        return '' !== trim($this->cle);
    }

    public function extraire(ContenuPiece $contenu, GabaritExtraction $gabarit): ResultatExtraction
    {
        if (!$this->estDisponible()) {
            throw new ProviderIndisponibleException('GEMINI_API_KEY absente.');
        }
        $donnees = $contenu->contenu;
        if ('' === $donnees) {
            throw new ReponseNonExploitableException('Piece vide ou illisible.');
        }

        $corps = [
            'contents' => [['parts' => [
                ['text' => $gabarit->prompt],
                ['inline_data' => ['mime_type' => $contenu->mime, 'data' => base64_encode($donnees)]],
            ]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $gabarit->schema,
                'temperature' => 0,
            ],
        ];

        $debut = microtime(true);
        try {
            $reponse = $this->httpClient->request('POST', self::BASE.$this->modele.':generateContent', [
                'query' => ['key' => $this->cle],
                'json' => $corps,
                'timeout' => 60,
            ]);
            $statut = $reponse->getStatusCode();
            /** @var array<string, mixed> $brut */
            $brut = $reponse->toArray(false);
        } catch (Throwable $e) {
            throw new ProviderIndisponibleException('Appel Gemini echoue : '.$e->getMessage(), 0, $e);
        }
        $latenceMs = (int) round((microtime(true) - $debut) * 1000);

        if (429 === $statut || $statut >= 500) {
            throw new ProviderIndisponibleException(sprintf('Gemini indisponible (HTTP %d) : %s', $statut, $this->messageErreur($brut)));
        }
        if (200 !== $statut) {
            throw new ReponseNonExploitableException(sprintf('Gemini a refuse la requete (HTTP %d) : %s', $statut, $this->messageErreur($brut)));
        }

        $texte = $brut['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!\is_string($texte)) {
            throw new ReponseNonExploitableException('Reponse Gemini sans texte exploitable.');
        }

        $champs = json_decode($texte, true);
        if (!\is_array($champs)) {
            throw new ReponseNonExploitableException('JSON Gemini invalide.');
        }

        $usage = \is_array($brut['usageMetadata'] ?? null) ? $brut['usageMetadata'] : [];

        return new ResultatExtraction(
            $champs,
            $brut,
            isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
            $latenceMs,
            $this->modele,
        );
    }
}
