<?php

declare(strict_types=1);

namespace App\Recouvrement\Controller;

use App\Recouvrement\Enum\RetourSource;
use App\Recouvrement\Service\IngestionRetourService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Formulaire PUBLIC (sans authentification) permettant a un client dont l'email
 * n'a pas pu etre rattache de repondre a sa relance : il saisit sa reference
 * dossier, son message et joint eventuellement des fichiers. La soumission
 * reutilise le pipeline d'ingestion (IngestionRetourService) -> le retour arrive
 * dans la boite de la comptable (temps reel inclus), rattache par la reference.
 *
 * Surface d'attaque publique -> garde-fous : CSRF, honeypot, rate-limit par IP,
 * validation stricte des fichiers (type + taille + nombre), et la reference doit
 * correspondre a un compte reellement relance (sinon rejet : bloque le spam).
 */
final class ReponsePubliqueController extends AbstractController
{
    /** Soumissions max par IP et par fenetre. */
    private const MAX_PAR_IP = 5;

    /** Verifications AJAX de reference max par IP et par fenetre (anti-enumeration). */
    private const MAX_VERIF_PAR_IP = 40;

    /** Fenetre du rate-limit (secondes). */
    private const FENETRE_SECONDES = 3600;

    private const MAX_FICHIERS = 5;

    /** Taille max par fichier (octets). */
    private const TAILLE_MAX_FICHIER = 15_000_000;

    private const MESSAGE_MAX = 5000;

    /** Types MIME acceptes pour les pieces jointes. */
    private const MIME_AUTORISES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly IngestionRetourService $ingestion,
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/recouvrement/repondre', name: 'app_recouvrement_repondre_public', methods: ['GET', 'POST'])]
    public function repondre(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->rendreFormulaire();
        }

        // Honeypot : un champ cache que seuls les robots remplissent -> on fait mine
        // d'accepter (page de succes) sans rien enregistrer.
        if ('' !== trim((string) $request->request->get('site_web'))) {
            return $this->rendreSucces();
        }

        if (!$this->isCsrfTokenValid('reponse-publique', (string) $request->request->get('_token'))) {
            return $this->rendreFormulaire(['Session expirée, merci de renvoyer le formulaire.'], $request);
        }

        if (!$this->sousLimite($request)) {
            return $this->rendreFormulaire(['Trop de tentatives. Merci de réessayer plus tard.'], $request, Response::HTTP_TOO_MANY_REQUESTS);
        }

        // On compte CHAQUE tentative (pas seulement les succes) : sinon un robot muni
        // d'un jeton CSRF valide pourrait marteler la verification de reference sans
        // limite (enumeration). La reference figurant dans l'e-mail du client, 5 essais
        // par heure restent largement suffisants pour un envoi legitime.
        $this->incrementerLimite($request);

        $reference = self::nettoyerReference((string) $request->request->get('reference'));
        $compteRelance = $this->resoudreCompte($reference);
        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $message = trim((string) $request->request->get('message'));

        // Tout est obligatoire. C'est la REFERENCE qui rattache la relance (jamais
        // l'e-mail seul) ; l'e-mail ne sert qu'a repondre au client une fois rattache.
        $erreurs = [];
        if ('' === $reference) {
            $erreurs[] = 'La référence de votre dossier est obligatoire (elle figure dans votre e-mail de relance, entre crochets : [ref. …]).';
        } elseif (null === $compteRelance) {
            $erreurs[] = 'Référence introuvable. Vérifiez la référence indiquée dans votre e-mail de relance.';
        }
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'Une adresse e-mail valide est obligatoire.';
        }
        if ('' === $message) {
            $erreurs[] = 'Votre message est obligatoire.';
        } elseif (mb_strlen($message) > self::MESSAGE_MAX) {
            $message = mb_substr($message, 0, self::MESSAGE_MAX);
        }

        $fichiers = array_values(array_filter(
            $request->files->all('fichiers'),
            static fn (mixed $f): bool => $f instanceof UploadedFile,
        ));
        [$pieces, $erreursFichiers] = $this->validerFichiers($fichiers);
        $erreurs = array_merge($erreurs, $erreursFichiers);

        if ([] !== $erreurs) {
            return $this->rendreFormulaire($erreurs, $request);
        }

        $this->ingestion->ingerer([
            'messageId' => 'form-'.bin2hex(random_bytes(8)),
            'from' => $email,
            'sujet' => sprintf('Réponse via le formulaire [ref. %s]', $compteRelance ?? $reference),
            'corpsTexte' => $message,
            'corpsHtml' => null,
            'headers' => [],
            'piecesJointes' => $pieces,
            'recuLe' => new DateTimeImmutable(),
        ], RetourSource::FORMULAIRE);

        return $this->rendreSucces();
    }

    /**
     * Verification AJAX de la reference (feedback live sous le champ) : renvoie si
     * elle est vide et si elle correspond a un compte reellement relance. Ne divulgue
     * qu'un booleen (pas de donnee client).
     */
    #[Route('/recouvrement/repondre/verifier', name: 'app_recouvrement_verifier_ref', methods: ['GET'])]
    public function verifierRef(Request $request): JsonResponse
    {
        // Anti-enumeration : borne le nombre de verifications par IP (l'endpoint
        // revele si une reference existe -> ne pas laisser brute-forcer).
        if (!$this->throttleVerif($request)) {
            return new JsonResponse(['vide' => false, 'connue' => false], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $reference = self::nettoyerReference((string) $request->query->get('ref'));

        return new JsonResponse([
            'vide' => '' === $reference,
            'connue' => null !== $this->resoudreCompte($reference),
        ]);
    }

    private function throttleVerif(Request $request): bool
    {
        $item = $this->cache->getItem('reponse_verif_'.sha1((string) $request->getClientIp()));
        $n = (int) $item->get();
        if ($n >= self::MAX_VERIF_PAR_IP) {
            return false;
        }
        $item->set($n + 1);
        $item->expiresAfter(self::FENETRE_SECONDES);
        $this->cache->save($item);

        return true;
    }

    /**
     * @param list<string> $erreurs
     */
    private function rendreFormulaire(array $erreurs = [], ?Request $request = null, int $statut = Response::HTTP_OK): Response
    {
        $reponse = $this->render('recouvrement/public/repondre.html.twig', [
            'erreurs' => $erreurs,
            'reference' => null !== $request ? trim((string) $request->request->get('reference')) : '',
            'email' => null !== $request ? trim((string) $request->request->get('email')) : '',
            'message' => null !== $request ? trim((string) $request->request->get('message')) : '',
            'succes' => false,
        ]);
        $reponse->setStatusCode($statut);

        return $reponse;
    }

    private function rendreSucces(): Response
    {
        return $this->render('recouvrement/public/repondre.html.twig', ['succes' => true, 'erreurs' => []]);
    }

    /**
     * Valide les fichiers uploades (nombre, taille, type MIME) et renvoie les pieces
     * pretes pour l'ingestion + la liste des erreurs.
     *
     * @param list<UploadedFile> $fichiers
     *
     * @return array{0: list<array{nom: string, typeMime: ?string, contenu: string}>, 1: list<string>}
     */
    private function validerFichiers(array $fichiers): array
    {
        $pieces = [];
        $erreurs = [];

        if (\count($fichiers) > self::MAX_FICHIERS) {
            $erreurs[] = sprintf('Maximum %d fichiers.', self::MAX_FICHIERS);

            return [[], $erreurs];
        }

        foreach ($fichiers as $fichier) {
            if (!$fichier->isValid()) {
                $erreurs[] = sprintf('Le fichier « %s » n\'a pas pu être lu.', $fichier->getClientOriginalName());

                continue;
            }
            if ($fichier->getSize() > self::TAILLE_MAX_FICHIER) {
                $erreurs[] = sprintf('Le fichier « %s » dépasse 15 Mo.', $fichier->getClientOriginalName());

                continue;
            }
            $mime = (string) $fichier->getMimeType();
            if (!\in_array($mime, self::MIME_AUTORISES, true)) {
                $erreurs[] = sprintf('Le type du fichier « %s » n\'est pas accepté (PDF, image ou document bureautique).', $fichier->getClientOriginalName());

                continue;
            }
            $contenu = @file_get_contents($fichier->getPathname());
            if (false === $contenu) {
                continue;
            }
            $pieces[] = [
                'nom' => $fichier->getClientOriginalName(),
                'typeMime' => $mime,
                'contenu' => $contenu,
            ];
        }

        return [$pieces, $erreurs];
    }

    /**
     * Retrouve le code compte reellement relance correspondant a la reference saisie,
     * en ignorant la casse. Renvoie le code canonique (tel que stocke) ou null. Sert
     * aussi de garde-fou anti-spam : une reference inconnue est rejetee.
     */
    private function resoudreCompte(string $reference): ?string
    {
        if ('' === $reference) {
            return null;
        }

        $compte = $this->connection->fetchOne(
            'SELECT compte_code FROM recouvrement.relance_envoi WHERE UPPER(compte_code) = UPPER(:ref) LIMIT 1',
            ['ref' => $reference],
        );

        return false === $compte ? null : (string) $compte;
    }

    private function sousLimite(Request $request): bool
    {
        $item = $this->cache->getItem($this->cleLimite($request));

        return (int) $item->get() < self::MAX_PAR_IP;
    }

    private function incrementerLimite(Request $request): void
    {
        $item = $this->cache->getItem($this->cleLimite($request));
        $item->set(((int) $item->get()) + 1);
        $item->expiresAfter(self::FENETRE_SECONDES);
        $this->cache->save($item);
    }

    private function cleLimite(Request $request): string
    {
        return 'reponse_pub_'.sha1((string) $request->getClientIp());
    }

    /**
     * Nettoie la reference saisie pour retrouver le code compte, quelle que soit la
     * forme copiee par le client : « ACMSERVICES », « [ACMSERVICES] » ou
     * « [ref. ACMSERVICES] » donnent tous « ACMSERVICES ».
     */
    private static function nettoyerReference(string $brut): string
    {
        $ref = trim($brut);

        // Crochets englobants eventuels (une ou plusieurs paires) : [ ... ].
        while (str_starts_with($ref, '[') && str_ends_with($ref, ']')) {
            $ref = trim(substr($ref, 1, -1));
        }

        // Prefixe d'etiquette « ref. » / « réf. » (avec point ou espace) uniquement en
        // tete : on ne touche pas a un code qui commencerait par « ref ».
        $ref = preg_replace('/^r[ée]f(?:\.|\s)\s*/iu', '', $ref) ?? $ref;

        // Filet : crochets residuels d'une saisie malformee (jamais presents dans un code).
        $ref = str_replace(['[', ']'], '', $ref);

        return trim($ref);
    }
}
