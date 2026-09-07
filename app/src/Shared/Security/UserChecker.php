<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse l'authentification (y compris via remember_me) des comptes desactives.
 * Permet de couper l'acces immediatement sans attendre l'expiration de session.
 * Voir docs/SECURITY.md.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Votre compte a ete desactive.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Aucune verification post-authentification supplementaire.
    }
}
