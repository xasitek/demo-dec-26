<?php

declare(strict_types=1);

namespace App\Shared\Controller\Admin;

use App\Shared\Entity\PoleComptable;
use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use App\Shared\Repository\UserRepository;
use App\Shared\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des utilisateurs : recherche, pré-création, rôles, activation.
 * Accessible aux administrateurs. Les rôles élevés (ADMIN, SUPER_ADMIN) ne
 * peuvent être manipulés que par un super administrateur. Voir docs/SECURITY.md.
 */
#[Route('/admin/utilisateurs')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    private const ALLOWED_DOMAIN = 'demonstration.invalid';

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $all = $users->findAll();

        $stats = [
            'total' => \count($all),
            'actifs' => \count(array_filter($all, static fn (User $u): bool => $u->isActive())),
            'attente' => \count(array_filter($all, static fn (User $u): bool => !$u->isHabilite())),
            'inactifs' => \count(array_filter($all, static fn (User $u): bool => !$u->isActive())),
        ];

        $suggestions = [];
        foreach ($all as $u) {
            $suggestions[$u->getFullName()] = true;
        }
        $suggestions = array_keys($suggestions);

        $q = trim((string) $request->query->get('q', ''));
        $statut = (string) $request->query->get('statut', 'tous');
        $sort = (string) $request->query->get('sort', 'nom');
        $dir = 'desc' === $request->query->get('dir') ? 'desc' : 'asc';

        $list = $all;

        if ('' !== $q) {
            $needle = mb_strtolower($q);
            $list = array_filter($list, static fn (User $u): bool => str_contains(mb_strtolower($u->getFullName()), $needle)
                || str_contains(mb_strtolower($u->getEmail()), $needle));
        }

        $list = match ($statut) {
            'actif' => array_filter($list, static fn (User $u): bool => $u->isActive()),
            'inactif' => array_filter($list, static fn (User $u): bool => !$u->isActive()),
            'attente' => array_filter($list, static fn (User $u): bool => !$u->isHabilite()),
            default => $list,
        };

        $list = array_values($list);
        usort($list, static function (User $a, User $b) use ($sort): int {
            return match ($sort) {
                'connexion' => ($a->getLastLoginAt()?->getTimestamp() ?? 0) <=> ($b->getLastLoginAt()?->getTimestamp() ?? 0),
                'creation' => $a->getCreatedAt() <=> $b->getCreatedAt(),
                default => strcasecmp($a->getFullName(), $b->getFullName()),
            };
        });

        if ('desc' === $dir) {
            $list = array_reverse($list);
        }

        $perPage = 20;
        $totalFiltres = \count($list);
        $totalPages = max(1, (int) ceil($totalFiltres / $perPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $totalPages);
        $list = \array_slice($list, ($page - 1) * $perPage, $perPage);

        $params = [
            'users' => $list,
            'stats' => $stats,
            'suggestions' => $suggestions,
            'q' => $q,
            'statut' => $statut,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'total_pages' => $totalPages,
            'total_filtres' => $totalFiltres,
            'per_page' => $perPage,
            'roles_metier' => Roles::METIER,
            'roles_eleves' => Roles::ELEVES,
            'poles' => PoleComptable::choix(),
            'modules_dispo' => Module::cases(),
        ];

        // Scroll infini : on ne renvoie que les lignes pour les pages suivantes.
        if ($request->query->getBoolean('fragment')) {
            return $this->render('admin/utilisateurs/_rows.html.twig', $params);
        }

        return $this->render('admin/utilisateurs/index.html.twig', $params);
    }

    /**
     * Pré-création d'un utilisateur : il aura accès directement à sa 1ère
     * connexion Google (sans demande d'accès).
     */
    #[Route('/nouveau', name: 'app_admin_users_create', methods: ['POST'])]
    public function create(Request $request, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('create_user', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $email = strtolower(trim((string) $request->request->get('email')));
        $firstName = trim((string) $request->request->get('first_name'));
        $lastName = trim((string) $request->request->get('last_name'));
        /** @var list<string> $rolesDemandes */
        $rolesDemandes = (array) $request->request->all('roles');
        $roles = Roles::filtrer($rolesDemandes, $this->isGranted('ROLE_SUPER_ADMIN'));

        $domaine = strtolower(substr((string) strrchr($email, '@'), 1));

        if ('' === $email || self::ALLOWED_DOMAIN !== $domaine) {
            $this->addFlash('error', 'Email invalide : un compte @'.self::ALLOWED_DOMAIN.' est requis.');
        } elseif ('' === $firstName || '' === $lastName) {
            $this->addFlash('error', 'Le prénom et le nom sont requis.');
        } elseif ([] === $roles) {
            $this->addFlash('error', 'Sélectionnez au moins un rôle.');
        } elseif (null !== $users->findOneByEmail($email)) {
            $this->addFlash('error', 'Un utilisateur avec cet email existe déjà.');
        } else {
            $user = new User();
            $user->setEmail($email)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setRoles($roles)
                ->setPoleComptable($this->polePourRoles($request, $roles));
            $users->save($user);
            $this->addFlash('success', sprintf('%s a été créé et peut se connecter.', $user->getFullName()));
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/roles', name: 'app_admin_users_roles', methods: ['POST'])]
    public function updateRoles(Request $request, User $user, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('roles'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (Roles::aRoleEleve($user->getRoles()) && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException('Réservé au super administrateur.');
        }

        /** @var list<string> $rolesDemandes */
        $rolesDemandes = (array) $request->request->all('roles');
        // Les roles eleves (admin/super) ne sont plus modifiables via l'UI (gestion
        // en CLI/shell pour eviter les mauvaises manips) : on n'applique que les
        // roles metier soumis et on conserve les roles eleves deja attribues.
        $metier = Roles::filtrer($rolesDemandes, false);
        $eleves = array_values(array_filter(
            $user->getRoles(),
            static fn (string $r): bool => \array_key_exists($r, Roles::ELEVES),
        ));
        $user->setRoles(array_values(array_unique(array_merge($metier, $eleves))));
        // Pole : conserve/actualise si comptable, sinon vide.
        $user->setPoleComptable($this->polePourRoles($request, $metier));
        $users->save($user);

        $this->addFlash('success', sprintf('Rôles de %s mis à jour.', $user->getFullName()));

        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * Rattachement de l'utilisateur aux modules metier (acces + perimetre des
     * notifications). Ne modifie pas les roles.
     */
    #[Route('/{id}/modules', name: 'app_admin_users_modules', methods: ['POST'])]
    public function updateModules(Request $request, User $user, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('modules'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (Roles::aRoleEleve($user->getRoles()) && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException('Réservé au super administrateur.');
        }

        $demandes = (array) $request->request->all('modules');
        $valides = [];
        foreach ($demandes as $valeur) {
            if (\is_string($valeur) && null !== Module::tryFrom($valeur)) {
                $valides[] = $valeur;
            }
        }
        $user->setModules($valides);
        $users->save($user);

        $this->addFlash('success', sprintf('Modules de %s mis à jour.', $user->getFullName()));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/actif', name: 'app_admin_users_toggle', methods: ['POST'])]
    public function toggleActive(Request $request, User $user, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('toggle'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var User $courant */
        $courant = $this->getUser();
        if ($courant->getId() === $user->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas désactiver votre propre compte.');

            return $this->redirectToRoute('app_admin_users');
        }

        if (Roles::aRoleEleve($user->getRoles()) && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException('Réservé au super administrateur.');
        }

        $user->setIsActive(!$user->isActive());
        $users->save($user);

        $this->addFlash('success', sprintf(
            '%s a été %s.',
            $user->getFullName(),
            $user->isActive() ? 'réactivé' : 'désactivé',
        ));

        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * Pole soumis, uniquement pertinent si le role Comptable fait partie des roles
     * retenus. Retourne null sinon (ou si aucun pole valide n'est fourni).
     *
     * @param list<string> $roles
     */
    private function polePourRoles(Request $request, array $roles): ?PoleComptable
    {
        if (!\in_array('ROLE_COMPTABLE', $roles, true)) {
            return null;
        }

        return PoleComptable::tryFrom(trim((string) $request->request->get('pole', '')));
    }
}
