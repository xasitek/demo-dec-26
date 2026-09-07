<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Entity\DemandeAcces;
use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use App\Shared\Repository\DemandeAccesRepository;
use App\Shared\Repository\UserRepository;
use App\Shared\Service\NotificationService;
use DateTimeImmutable;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Authentification via Google OAuth. Le domaine @demonstration.invalid est le seul
 * accepte pour l'application (verrou serveur : c'est LUI qui fait foi, le parametre
 * `hd` pose par SecurityController::connect n'est qu'un filtre de confort).
 *
 * Exception unique : le DEPOT remboursement (/remboursement/deposer). Les secretaires
 * de certaines enseignes du groupe ont une adresse hors domaine principal ; ces
 * domaines sont acceptes SEULEMENT dans ce flux (drapeau de session pose par
 * `?secretaire=1`) ou pour un compte deja provisionne en ROLE_SECRETAIRE, afin qu'il
 * puisse se reconnecter. Le provisionnement reste ROLE_SECRETAIRE + module
 * Remboursement : aucun acces au reste de l'application.
 *
 * Voir docs/SECURITY.md.
 */
final class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    /** Domaine d'entreprise : seul accepte pour un acces applicatif. */
    public const DOMAINE_PRINCIPAL = 'demonstration.invalid';

    /** Domaines acceptes UNIQUEMENT pour le depot remboursement (secretaires). */
    private const DOMAINES_SECRETAIRE = ['partenaire-a.invalid', 'groupe-demonstration.invalid', 'partenaire-b.invalid'];

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $userRepository,
        private readonly DemandeAccesRepository $demandeAccesRepository,
        private readonly NotificationService $notifications,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'oauth_google_check' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        $userBadge = new UserBadge($accessToken->getToken(), function () use ($accessToken, $client, $request): User {
            /** @var GoogleUser $googleUser */
            $googleUser = $client->fetchUserFromToken($accessToken);

            $email = (string) $googleUser->getEmail();
            if ('' === $email) {
                throw new CustomUserMessageAuthenticationException('Adresse email Google introuvable.');
            }
            $domain = strtolower(substr((string) strrchr($email, '@'), 1));

            $googleId = (string) $googleUser->getId();
            $user = $this->userRepository->findOneByGoogleId($googleId)
                ?? $this->userRepository->findOneByEmail($email);

            // Verrou serveur : c'est LUI qui fait foi (le parametre `hd` cote Google
            // n'est qu'un filtre de confort, contournable).
            if (!self::domaineAutorise($domain, $request, $user)) {
                throw new CustomUserMessageAuthenticationException(sprintf('Acces reserve aux comptes @%s (les comptes @%s ne peuvent se connecter que depuis la page de depot remboursement).', self::DOMAINE_PRINCIPAL, implode(', @', self::DOMAINES_SECRETAIRE)));
            }

            $estNouveau = false;

            if (null === $user) {
                // Premier login non pre-cree : auto-creation, non habilite (ROLE_USER seul).
                $estNouveau = true;
                $user = new User();
                $user->setGoogleId($googleId)
                    ->setEmail($email)
                    ->setFirstName((string) $googleUser->getFirstName())
                    ->setLastName((string) $googleUser->getLastName())
                    ->setAvatarUrl($googleUser->getAvatar());
            } else {
                // Compte existant (connexion suivante ou pre-cree par l'admin) :
                // on rafraichit les infos Google.
                $user->setGoogleId($googleId)
                    ->setAvatarUrl($googleUser->getAvatar());
            }

            $user->setLastLoginAt(new DateTimeImmutable());
            $this->userRepository->save($user);

            // Provisionnement secretaire : connexion initiee depuis le depot public
            // remboursement -> habilitation directe (ROLE_SECRETAIRE + module), sans
            // demande d'acces. Role a faible privilege (deposer / mes dossiers seuls).
            // Le module accorde est celui de l'espace d'ou elle vient (memorise par
            // SecurityController::connect), et non un module ecrit en dur.
            $session = $request->getSession();
            $moduleEspace = Module::depuisValeur((string) $session->get('secretaire_signup_module'));

            if ($session->get('remb_secretaire_signup') && !$user->isHabilite()) {
                $session->remove('remb_secretaire_signup');
                $user->setRoles(['ROLE_SECRETAIRE']);
                $this->ajouterModule($user, $moduleEspace);
                $this->userRepository->save($user);
            } elseif (null !== $moduleEspace && self::estSecretairePure($user)) {
                // Deja habilitee, mais elle arrive par le lien d'un AUTRE espace : on
                // lui ajoute ce module, sinon le second espace lui resterait invisible
                // a jamais. Borne aux secretaires PURES : un role superieur ne peut
                // pas s'attribuer un module par un lien forge.
                if ($this->ajouterModule($user, $moduleEspace)) {
                    $this->userRepository->save($user);
                }
            }

            $session->remove('secretaire_signup_module');

            // Provisionnement du DIRECTEUR DU POLE : habilitation directe en
            // ROLE_DIRECTEUR + module Remboursement a sa premiere connexion, sans demande
            // d'acces. Cible un e-mail precis (aucune auto-elevation possible).
            if ('directeur-comptable@demonstration.invalid' === mb_strtolower(trim((string) $email)) && !$user->isHabilite()) {
                $user->setRoles(['ROLE_DIRECTEUR']);
                $modules = $user->getModules();
                if (!\in_array(Module::REMBOURSEMENT->value, $modules, true)) {
                    $modules[] = Module::REMBOURSEMENT->value;
                    $user->setModules($modules);
                }
                $this->userRepository->save($user);
            }

            // Auto-inscription : on cree la demande d'acces a valider par un admin.
            // (Un compte pre-cree par l'admin a deja des roles : pas de demande.)
            if ($estNouveau && !$user->isHabilite()) {
                $this->demandeAccesRepository->save(new DemandeAcces($user));
                $this->notifications->notifierDemandesEnAttente(
                    $this->demandeAccesRepository->countEnAttente(),
                    $user->getFullName(),
                );
            }

            return $user;
        });

        return new SelfValidatingPassport($userBadge, [new RememberMeBadge()]);
    }

    /**
     * Domaine de l'adresse autorise ? Le domaine d'entreprise l'est toujours. Un domaine
     * secretaire ne l'est que dans le flux de DEPOT remboursement — soit la connexion
     * vient d'etre initiee depuis ce formulaire (`?secretaire=1` -> drapeau de session),
     * soit le compte est deja provisionne en ROLE_SECRETAIRE et se reconnecte. Tout le
     * reste est refuse.
     */
    private static function domaineAutorise(string $domaine, Request $request, ?User $user): bool
    {
        if (self::DOMAINE_PRINCIPAL === $domaine) {
            return true;
        }
        if (!\in_array($domaine, self::DOMAINES_SECRETAIRE, true)) {
            return false;
        }

        return true === $request->getSession()->get('remb_secretaire_signup')
            || (null !== $user && \in_array('ROLE_SECRETAIRE', $user->getRoles(), true));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Cible explicite (secretaire arrivant du bouton "se connecter" du depot, ou page
        // protegee demandee avant login) : on la respecte.
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);
        if (null !== $targetPath) {
            return new RedirectResponse($targetPath);
        }

        // Sinon, redirection selon le role (evite la page d'accueil / demande d'acces).
        $roles = $token->getRoleNames();
        if (\in_array('ROLE_DIRECTEUR', $roles, true)) {
            // Directeur du pole : journal des paiements.
            return new RedirectResponse($this->urlGenerator->generate('app_remboursement_paiements_journal'));
        }
        if (\in_array('ROLE_SECRETAIRE', $roles, true)
            && !\in_array('ROLE_COMPTABLE', $roles, true)
            && !\in_array('ROLE_MANAGER', $roles, true)) {
            // Secretaire pure : depot de dossier.
            return new RedirectResponse($this->urlGenerator->generate('app_remboursement_deposer_formulaire'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(
                'auth_error',
                strtr($exception->getMessageKey(), $exception->getMessageData()),
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    /**
     * Ajoute un module a l'utilisateur s'il ne l'a pas deja.
     *
     * @return bool vrai si la liste a change, donc s'il faut enregistrer
     */
    private function ajouterModule(User $user, ?Module $module): bool
    {
        if (null === $module) {
            return false;
        }

        $modules = $user->getModules();
        if (\in_array($module->value, $modules, true)) {
            return false;
        }

        $modules[] = $module->value;
        $user->setModules($modules);

        return true;
    }

    /**
     * ROLE_SECRETAIRE sans aucun role d'application.
     *
     * Lu sur les roles BRUTS : on juge ici un utilisateur qui n'est pas encore
     * authentifie, la hierarchie de roles n'est donc pas resolue pour nous — d'ou
     * ROLE_SUPER_ADMIN cite explicitement.
     */
    private static function estSecretairePure(User $user): bool
    {
        $roles = $user->getRoles();
        if (!\in_array('ROLE_SECRETAIRE', $roles, true)) {
            return false;
        }

        foreach (['ROLE_COMPTABLE', 'ROLE_MANAGER', 'ROLE_DIRECTEUR', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as $eleve) {
            if (\in_array($eleve, $roles, true)) {
                return false;
            }
        }

        return true;
    }
}
