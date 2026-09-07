<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Shared\Enum\Module;
use App\Shared\Secretaire\RegistreEspaces;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Confinement de la SECRETAIRE PURE (ROLE_SECRETAIRE, sans comptable / manager /
 * directeur / admin) : elle ne navigue que dans les espaces que les modules lui
 * ouvrent. Toute autre page la ramene a l'accueil de son premier espace.
 *
 * La liste des routes autorisees vient du RegistreEspaces : chaque module declare
 * les siennes. Auparavant cette liste etait codee en dur dans le module
 * Remboursement, ce qui obligeait tout nouveau module a venir la modifier.
 *
 * Ne s'applique QU'AUX navigations de page (GET, hors AJAX) : les appels AJAX
 * (cloche, presence, Mercure, fragments) et l'authentification ne sont jamais
 * redirigees. Les comptes ayant un role superieur ne sont pas concernes.
 */
#[AsEventListener(event: ControllerEvent::class)]
final class ConfinementSecretaireListener
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly RegistreEspaces $registre,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('GET') || $request->isXmlHttpRequest()) {
            return;
        }

        if (null === $this->security->getUser() || !$this->security->isGranted('ROLE_SECRETAIRE')) {
            return;
        }
        if ($this->security->isGranted('ROLE_COMPTABLE')
            || $this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_DIRECTEUR')
            || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $route = (string) $request->attributes->get('_route');
        if ('' === $route || str_starts_with($route, '_')) {
            return;
        }
        if (\in_array($route, $this->registre->routesAutorisees(), true)) {
            return;
        }

        // Une secretaire peut etre rattachee a un module de GESTION : le confinement
        // ne doit pas l'en priver, sans quoi elle clique dans son menu et rebondit
        // sur son accueil. On laisse donc passer tout chemin appartenant a un module
        // qu'elle possede. Le prefixe d'URL est porte par l'enum, donc aucune liste
        // a tenir ici : un module ajoute est couvert d'office.
        $chemin = $request->getPathInfo();
        foreach (Module::cases() as $module) {
            if (str_starts_with($chemin, $module->prefixe()) && $this->security->isGranted($module->attribut())) {
                return;
            }
        }

        $cible = $this->urls->generate($this->registre->routeParDefaut());
        $event->setController(static fn (): RedirectResponse => new RedirectResponse($cible));
    }
}
