<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Acces a un module : l'utilisateur doit y etre rattache (User::modules), sauf
 * les administrateurs qui voient tout. Attribut = Module::attribut() (ex.
 * MODULE_RECOUVREMENT), utilise dans security.yaml (access_control) et les
 * templates (is_granted).
 *
 * @extends Voter<string, mixed>
 */
final class ModuleVoter extends Voter
{
    private const PREFIXE = 'MODULE_';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::PREFIXE) && null !== $this->module($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Les administrateurs accedent a tous les modules.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $module = $this->module($attribute);

        return null !== $module && $user->aModule($module);
    }

    private function module(string $attribute): ?Module
    {
        return Module::depuisValeur(strtolower(substr($attribute, \strlen(self::PREFIXE))));
    }
}
