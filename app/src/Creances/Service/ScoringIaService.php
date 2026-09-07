<?php

declare(strict_types=1);

namespace App\Creances\Service;

use App\Creances\Entity\ScoreIa;
use App\Creances\Repository\ActionRepository;
use App\Creances\Repository\CreancesRepository;
use App\Creances\Repository\DossierRepository;
use App\Creances\Repository\PromesseRepository;
use App\Creances\Repository\ScoreIaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Scoring enrichi par IA (LLM Anthropic Claude). Le scoring deterministe
 * reste calcule par IndicateursService ; ce service ajoute une couche
 * d'analyse contextuelle :
 *
 *  - synthese en langage naturel de la situation du compte
 *  - identification de facteurs aggravants/attenuants
 *  - ajustement du score (10 = sain, 0 = critique)
 *
 * Utilise `symfony/http-client` (deja installe) pour appeler l'API
 * Anthropic en POST JSON pur (pas de SDK requis).
 *
 * La cle API est lue dans la variable d'env `ANTHROPIC_API_KEY` (a
 * configurer par la responsable technique dans `.env.local` ou les variables Render).
 * Sans cle, le service renvoie `null` et le compte utilise uniquement le
 * scoring deterministe (fallback transparent).
 */
final class ScoringIaService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const MODELE_DEFAUT = 'claude-haiku-4-5-20251001';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ScoreIaRepository $scores,
        private readonly CreancesRepository $creances,
        private readonly IndicateursService $indicateurs,
        private readonly ActionRepository $actions,
        private readonly PromesseRepository $promesses,
        private readonly DossierRepository $dossiers,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::ANTHROPIC_API_KEY')]
        private readonly ?string $apiKey = '',
        #[Autowire(env: 'default::ANTHROPIC_MODEL')]
        private readonly ?string $modeleEnv = '',
    ) {
    }

    private function modeleEffectif(): string
    {
        return null !== $this->modeleEnv && '' !== $this->modeleEnv ? $this->modeleEnv : self::MODELE_DEFAUT;
    }

    public function configure(): bool
    {
        return null !== $this->apiKey && '' !== $this->apiKey;
    }

    /**
     * Lance le scoring IA pour un compte. Renvoie le ScoreIa enregistre ou
     * null si la cle API n'est pas configuree / appel echoue.
     */
    public function score(string $compteCode): ?ScoreIa
    {
        if (!$this->configure()) {
            $this->logger->info('Scoring IA ignore : ANTHROPIC_API_KEY non configuree pour le compte {code}.', ['code' => $compteCode]);

            return null;
        }

        $contexte = $this->construireContexte($compteCode);
        if (null === $contexte) {
            return null;
        }

        $prompt = $this->construirePrompt($contexte);

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->modeleEffectif(),
                    'max_tokens' => 800,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray(false);
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Scoring IA - erreur HTTP {code} : {body}', [
                    'code' => $response->getStatusCode(),
                    'body' => json_encode($data),
                ]);

                return null;
            }

            $texte = $this->extraireTexte($data);
            $analyse = $this->parserReponseJson($texte);
            if (null === $analyse) {
                $this->logger->warning('Scoring IA - reponse non parseable : {texte}', ['texte' => $texte]);

                return null;
            }

            $score = $this->scores->findByCompte($compteCode) ?? new ScoreIa($compteCode, (string) $analyse['score']);
            $score->setScore((string) $analyse['score']);
            $score->setCommentaire($analyse['commentaire']);
            $score->setFacteurs($analyse['facteurs']);
            $score->setConfiance(null !== $analyse['confiance'] ? (string) $analyse['confiance'] : null);
            $score->setProvider('anthropic');
            $score->setModele($this->modeleEffectif());
            $usage = $data['usage'] ?? [];
            if (is_array($usage)) {
                $tokens = (int) (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0));
                $score->setTokensUtilises($tokens > 0 ? $tokens : null);
            }

            $this->scores->save($score);

            return $score;
        } catch (Throwable $e) {
            $this->logger->warning('Scoring IA echec pour {code} : {message}', [
                'code' => $compteCode,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Construit le contexte synthetique transmis au LLM (sans donnees
     * personnelles sensibles : on anonymise le nom).
     *
     * @return array<string, mixed>|null
     */
    private function construireContexte(string $compteCode): ?array
    {
        $synthese = $this->indicateurs->syntheseCompte($compteCode);
        if (0.0 === $synthese['encours_total'] && 0 === $synthese['nb_ecritures']) {
            return null;
        }
        $tiers = $this->creances->tiers($compteCode);
        $d = [];
        if (null !== $tiers) {
            $brut = $tiers['donnees'] ?? null;
            $d = is_array($brut) ? $brut : (is_string($brut) ? (json_decode($brut, true) ?: []) : []);
        }

        return [
            'code_anonyme' => '****'.substr($compteCode, -3),
            'type' => (string) ($d['TYPE'] ?? 'inconnu'),
            'indicateurs' => $synthese,
            'nb_actions' => count($this->actions->findByCompte($compteCode)),
            'nb_promesses' => count($this->promesses->findByCompte($compteCode)),
            'nb_dossiers' => count($this->dossiers->findByCompte($compteCode)),
        ];
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function construirePrompt(array $contexte): string
    {
        $indicateurs = $contexte['indicateurs'] ?? [];

        return <<<PROMPT
Tu es un assistant specialise dans le recouvrement de creances clients d'un groupe automobile (concessionnaires).

Analyse la situation suivante d'un compte client et fournis un score de risque (0 = critique, 10 = sain) ainsi qu'un commentaire bref.

Donnees disponibles :
- Compte : {$contexte['code_anonyme']}
- Type : {$contexte['type']}
- Encours total : {$indicateurs['encours_total']} EUR
- Encours echu : {$indicateurs['encours_echu']} EUR ({$indicateurs['taux_echu']}% du total)
- Retard moyen pondere : {$indicateurs['retard_moyen_jours']} jours
- Pire tranche d'anciennete : {$indicateurs['pire_tranche']}
- Nombre d'ecritures ouvertes : {$indicateurs['nb_ecritures']}
- Score deterministe (Gestion commerciale) : {$indicateurs['score']} / 10
- Nombre d'actions de relance deja menees : {$contexte['nb_actions']}
- Nombre de promesses enregistrees : {$contexte['nb_promesses']}
- Nombre de dossiers (litige/contentieux/echeancier) : {$contexte['nb_dossiers']}

Reponds STRICTEMENT au format JSON suivant, sans texte autour :

{
  "score": <nombre entre 0 et 10, decimal a 1 chiffre>,
  "confiance": <nombre entre 0 et 1>,
  "commentaire": "<analyse en 2-3 phrases en francais, ton professionnel, factuel>",
  "facteurs": ["<facteur 1>", "<facteur 2>", ...]
}

Exemples de facteurs : "retards_chroniques", "promesses_non_tenues", "litige_actif", "encours_eleve", "comportement_regulier", "premier_retard", "concession_strategique".
PROMPT;
    }

    /**
     * @param array<string, mixed> $reponse
     */
    private function extraireTexte(array $reponse): string
    {
        $content = $reponse['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }
        $texte = '';
        foreach ($content as $bloc) {
            if (is_array($bloc) && 'text' === ($bloc['type'] ?? null) && isset($bloc['text']) && is_string($bloc['text'])) {
                $texte .= $bloc['text'];
            }
        }

        return $texte;
    }

    /**
     * @return array{score: float, confiance: float|null, commentaire: string|null, facteurs: list<string>}|null
     */
    private function parserReponseJson(string $texte): ?array
    {
        // Extrait le premier bloc JSON valide (au cas ou le modele a mis du
        // texte autour, malgre les instructions).
        if (!preg_match('/\{.*\}/s', $texte, $matches)) {
            return null;
        }
        $json = json_decode($matches[0], true);
        if (!is_array($json) || !isset($json['score']) || !is_numeric($json['score'])) {
            return null;
        }
        $facteurs = [];
        if (isset($json['facteurs']) && is_array($json['facteurs'])) {
            foreach ($json['facteurs'] as $f) {
                if (is_string($f)) {
                    $facteurs[] = $f;
                }
            }
        }

        return [
            'score' => max(0.0, min(10.0, (float) $json['score'])),
            'confiance' => isset($json['confiance']) && is_numeric($json['confiance']) ? max(0.0, min(1.0, (float) $json['confiance'])) : null,
            'commentaire' => isset($json['commentaire']) && is_string($json['commentaire']) ? $json['commentaire'] : null,
            'facteurs' => $facteurs,
        ];
    }
}
