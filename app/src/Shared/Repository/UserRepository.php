<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByGoogleId(string $googleId): ?User
    {
        return $this->findOneBy(['googleId' => $googleId]);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Utilisateurs actifs et habilites possedant le role donne (role attribue,
     * sans expansion de la hierarchie). Utile pour les notifications par role.
     *
     * @return list<User>
     */
    public function avecRole(string $role): array
    {
        return array_values(array_filter(
            $this->findBy(['isActive' => true]),
            static fn (User $u): bool => $u->isHabilite() && \in_array($role, $u->getRoles(), true),
        ));
    }

    /**
     * Utilisateurs actifs rattaches au module donne (pour les notifications de
     * module). Un admin ne recoit les notifs d'un module que s'il y est rattache.
     *
     * @return list<User>
     */
    public function avecModule(Module $module): array
    {
        return array_values(array_filter(
            $this->findBy(['isActive' => true]),
            static fn (User $u): bool => $u->aModule($module),
        ));
    }

    /**
     * Utilisateurs actifs et habilites ayant A LA FOIS le role ET le module (sans
     * expansion de hierarchie). Ex. notifier les seules comptables rattachees au
     * module Remboursement quand un dossier arrive a verifier.
     *
     * @return list<User>
     */
    public function avecRoleEtModule(string $role, Module $module): array
    {
        return array_values(array_filter(
            $this->findBy(['isActive' => true]),
            static fn (User $u): bool => $u->isHabilite() && \in_array($role, $u->getRoles(), true) && $u->aModule($module),
        ));
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
