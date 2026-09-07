<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Shared\Entity\User;
use App\Shared\Entity\UserPresence;
use App\Shared\Repository\ActiviteJourRepository;
use App\Shared\Repository\UserPresenceRepository;
use App\Shared\Repository\UserRepository;
use App\Shared\Security\Roles;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Presence temps reel des utilisateurs. Le statut est maintenu par heartbeat ;
 * tout changement est pousse instantanement via Mercure (zero latence cote UI).
 * Voir docs/REALTIME.md et [[project-presence]].
 */
final class PresenceService
{
    public const TOPIC_PRESENCE = 'fc-finance:presence';

    /**
     * Pile du poste secretaire. Deux populations qui ne se melangent pas : les
     * secretaires ne se voient qu'entre elles, l'application complete ne voit que
     * les siens. Deux topics et non un filtre client : sans separation a la source,
     * le changement de statut d'une secretaire arriverait en direct dans la pile
     * des comptables.
     */
    public const TOPIC_PRESENCE_SECRETAIRE = 'fc-finance:presence-secretaire';

    /**
     * Roles qui gardent l'application complete, donc la pile de l'application.
     *
     * Meme regle que ConfinementSecretaireListener et SecretaireExtension, mais lue
     * sur les roles BRUTS : ici on classe des utilisateurs quelconques et non celui
     * qui est connecte, donc la hierarchie de roles n'est pas resolue pour nous.
     * ROLE_SUPER_ADMIN y figure explicitement pour cette raison.
     */
    private const ROLES_APPLICATION = [
        'ROLE_COMPTABLE', 'ROLE_MANAGER', 'ROLE_DIRECTEUR', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN',
    ];

    /** Du plus eleve au plus bas, pour le tri par role. */
    private const ORDRE_ROLES = [
        'ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_MANAGER',
        'ROLE_AUDITEUR', 'ROLE_COMPTABLE', 'ROLE_SECRETAIRE',
    ];

    public function __construct(
        private readonly UserPresenceRepository $presences,
        private readonly UserRepository $users,
        private readonly ActiviteJourRepository $activiteJour,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Enregistre le heartbeat. Pousse une mise a jour Mercure uniquement si le
     * statut a change (limite le trafic, garde l'instantaneite a la connexion).
     */
    /**
     * Tout utilisateur habilite et actif est suivi (presence + temps actif).
     */
    public function estSuivi(User $user): bool
    {
        return $user->isActive() && $user->isHabilite();
    }

    /**
     * La pile de presence est visible par tout utilisateur habilite et actif.
     */
    public function peutVoir(User $user): bool
    {
        return $user->isActive() && $user->isHabilite();
    }

    /**
     * Secretaire pure : ROLE_SECRETAIRE sans aucun role d'application. Elle ne voit
     * que ses homologues, et n'apparait pas dans la pile des comptables.
     */
    public function estSecretairePure(User $user): bool
    {
        $roles = $user->getRoles();

        if (!\in_array('ROLE_SECRETAIRE', $roles, true)) {
            return false;
        }

        foreach (self::ROLES_APPLICATION as $eleve) {
            if (\in_array($eleve, $roles, true)) {
                return false;
            }
        }

        return true;
    }

    /** Le topic de la population de cet utilisateur. */
    public function topicPour(User $user): string
    {
        return $this->estSecretairePure($user) ? self::TOPIC_PRESENCE_SECRETAIRE : self::TOPIC_PRESENCE;
    }

    /** Apparait dans la pile d'avatars : utilisateur suivi et non masque par le manager. */
    private function apparaitDansLaPile(User $user): bool
    {
        return $this->estSuivi($user) && $user->isPresenceVisible();
    }

    public function ping(User $user, bool $active): string
    {
        if (!$this->estSuivi($user)) {
            return 'offline';
        }

        // Temps de presence active (suivi teletravail), agrege par jour.
        $id = $user->getId();
        if (null !== $id) {
            $this->activiteJour->accumuler($id, $active);
        }

        $presence = $this->presences->findOneByUser($user);
        $ancien = null !== $presence ? $presence->statut() : 'offline';

        if (null === $presence) {
            $presence = new UserPresence($user);
        }
        $presence->touch($active);
        $this->presences->save($presence);

        $nouveau = $presence->statut();

        if ($ancien !== $nouveau && $this->apparaitDansLaPile($user)) {
            $this->publier($user, $nouveau);
        }

        return $nouveau;
    }

    /**
     * Pousse le passage hors-ligne (ex. fermeture d'onglet, best-effort).
     */
    public function deconnecter(User $user): void
    {
        if ($this->apparaitDansLaPile($user)) {
            $this->publier($user, 'offline');
        }
    }

    private function publier(User $user, string $statut): void
    {
        try {
            $this->hub->publish(new Update(
                $this->topicPour($user),
                json_encode($this->carte($user, $statut), \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Publication presence Mercure echouee : {m}', ['m' => $e->getMessage()]);
        }
    }

    /**
     * Etat initial visible par $viewer (filtre serveur, securise).
     *
     * @return list<array<string, mixed>>
     */
    public function listeVisible(User $viewer): array
    {
        if (!$this->peutVoir($viewer)) {
            return [];
        }

        $presencesParUser = [];
        foreach ($this->presences->findAllWithUser() as $p) {
            $presencesParUser[$p->getUser()->getId()] = $p;
        }

        $liste = [];
        foreach ($this->users->findAll() as $u) {
            if ($u->getId() === $viewer->getId()) {
                continue; // on ne s'affiche pas soi-meme
            }
            if (!$u->isHabilite() || !$u->isActive()) {
                continue;
            }
            if (!$u->isPresenceVisible()) {
                continue;
            }
            // Les deux populations ne se voient pas : filtre SERVEUR, donc non
            // contournable en bricolant la page.
            if ($this->estSecretairePure($u) !== $this->estSecretairePure($viewer)) {
                continue;
            }

            $presence = $presencesParUser[$u->getId()] ?? null;
            $liste[] = $this->carte($u, null !== $presence ? $presence->statut() : 'offline');
        }

        usort($liste, function (array $a, array $b): int {
            $ra = array_search($a['roleKey'], self::ORDRE_ROLES, true);
            $rb = array_search($b['roleKey'], self::ORDRE_ROLES, true);
            $r = (false === $ra ? 99 : $ra) <=> (false === $rb ? 99 : $rb);
            if (0 !== $r) {
                return $r;
            }
            $os = ['online' => 0, 'idle' => 1, 'offline' => 2];
            $s = ($os[$a['status']] ?? 3) <=> ($os[$b['status']] ?? 3);

            return 0 !== $s ? $s : strcasecmp($a['name'], $b['name']);
        });

        return $liste;
    }

    /**
     * @return array<string, mixed>
     */
    private function carte(User $user, string $statut): array
    {
        if ($user->isPresenceForceeEnLigne()) {
            $statut = 'online';
        }

        $roleKey = $this->rolePrincipal($user);

        return [
            'id' => $user->getId(),
            'name' => $user->getFullName(),
            'initials' => mb_strtoupper(mb_substr($user->getFirstName(), 0, 1).mb_substr($user->getLastName(), 0, 1)),
            'avatar' => $user->getAvatarUrl(),
            'role' => Roles::label($roleKey),
            'roleKey' => $roleKey,
            'status' => $statut,
        ];
    }

    private function rolePrincipal(User $user): string
    {
        foreach (self::ORDRE_ROLES as $role) {
            if (\in_array($role, $user->getRoles(), true)) {
                return $role;
            }
        }

        return 'ROLE_USER';
    }
}
