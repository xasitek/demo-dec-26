<?php

declare(strict_types=1);

namespace App\Shared\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal Google Sheets API v4 via Service Account (JWT Bearer flow).
 *
 * Auth :
 *   1) construit un JWT RS256 signe avec la cle privee du SA
 *   2) l'echange contre un access_token aupres de oauth2.googleapis.com
 *   3) appelle l'API Sheets avec ce token
 *
 * Pas de dependance Google SDK (lourde). 100 lignes, pas de complexite cachee.
 *
 * Le SA doit avoir l'acces Lecteur au spreadsheet (partage cote Drive).
 */
final class GoogleSheetsClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $serviceAccountJsonPath,
    ) {
    }

    /**
     * Liste les onglets d'un spreadsheet (titres uniquement).
     *
     * @return list<string>
     */
    public function listSheetTitles(string $spreadsheetId): array
    {
        $token = $this->getAccessToken();
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s?fields=sheets.properties.title',
            urlencode($spreadsheetId),
        );
        $resp = $this->httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);
        /** @var array{sheets?: list<array{properties?: array{title?: string}}>} $data */
        $data = $resp->toArray();
        $titles = [];
        foreach ($data['sheets'] ?? [] as $sheet) {
            $title = $sheet['properties']['title'] ?? null;
            if (null !== $title && '' !== $title) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Lit une plage de cellules. Retourne la liste des lignes (chaque ligne = liste de cellules).
     *
     * @return list<list<string>>
     */
    public function readRange(string $spreadsheetId, string $range): array
    {
        $token = $this->getAccessToken();
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING',
            urlencode($spreadsheetId),
            urlencode($range),
        );

        $resp = $this->httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        /** @var array{values?: list<list<scalar|null>>} $data */
        $data = $resp->toArray();
        $rows = $data['values'] ?? [];

        // Normalisation : tout en string, valeurs null -> ''.
        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = null === $cell ? '' : (string) $cell;
            }
            $out[] = $cells;
        }

        /* @var list<list<string>> $out */
        return $out;
    }

    private function getAccessToken(): string
    {
        if (null !== $this->accessToken && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        if (!is_file($this->serviceAccountJsonPath)) {
            throw new RuntimeException(sprintf('Service account JSON introuvable : %s', $this->serviceAccountJsonPath));
        }

        $raw = file_get_contents($this->serviceAccountJsonPath);
        if (false === $raw) {
            throw new RuntimeException('Lecture du JSON SA impossible.');
        }

        /** @var array{client_email?: string, private_key?: string} $sa */
        $sa = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('JSON SA invalide (client_email/private_key manquant).');
        }

        $jwt = $this->buildJwt($sa['client_email'], $sa['private_key']);

        $resp = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        /** @var array{access_token?: string, expires_in?: int} $payload */
        $payload = $resp->toArray();
        if (empty($payload['access_token'])) {
            throw new RuntimeException('Pas d\'access_token retourne par Google.');
        }

        $this->accessToken = $payload['access_token'];
        $this->tokenExpiresAt = time() + (int) ($payload['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function buildJwt(string $saEmail, string $privateKey): string
    {
        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'iss' => $saEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header.'.'.$claims;
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Echec signature JWT.');
        }

        return $signingInput.'.'.self::base64Url($signature);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
