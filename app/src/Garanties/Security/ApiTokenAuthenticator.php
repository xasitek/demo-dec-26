<?php

declare(strict_types=1);

namespace App\Garanties\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentification API par jeton Bearer (header Authorization).
 * Jeton attendu : env GARANTIES_API_TOKEN. Tout endpoint sous /api herite
 * automatiquement de cette auth via le firewall (voir security.yaml).
 *
 * Utilisateur "synthetique" InMemoryUser pour Symfony Security : pas
 * d'utilisateur en base, juste un identifiant logique "rpa-garanties" avec
 * ROLE_API. Voir docs/SECURITY.md.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire('%env(string:GARANTIES_API_TOKEN)%')]
        private readonly string $expectedToken,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Le firewall filtre deja sur ^/api : on s'occupe de toutes les requetes
        // qui arrivent ici (header present ou non) pour pouvoir renvoyer un
        // JSON 401 propre via onAuthenticationFailure plutot que la page HTML
        // par defaut de Symfony.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if ('' === $this->expectedToken) {
            // Jeton non configure cote serveur : on refuse toute requete API.
            throw new CustomUserMessageAuthenticationException('Jeton API non configuré côté serveur.');
        }

        $header = (string) $request->headers->get('Authorization', '');
        if ('' === $header || !str_starts_with($header, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('Header Authorization Bearer manquant.');
        }
        $envoye = substr($header, 7);

        if (!hash_equals($this->expectedToken, $envoye)) {
            throw new CustomUserMessageAuthenticationException('Jeton API invalide.');
        }

        return new SelfValidatingPassport(
            new UserBadge('rpa-garanties', fn (): InMemoryUser => new InMemoryUser('rpa-garanties', null, ['ROLE_API'])),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Laisser la requete continuer vers le controleur.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => 'Unauthorized', 'message' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
