<?php

declare(strict_types=1);

namespace App\Demo\Twig;

use App\Demo\Outil;
use App\Demo\Persona;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ce dont le bandeau de demonstration a besoin : quel poste regarde, quel
 * outil on visite, et vers quels autres postes on peut basculer sans quitter
 * l'ecran courant.
 */
final class DemoExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requetes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('demo_persona', $this->persona(...)),
            new TwigFunction('demo_outil', $this->outil(...)),
            new TwigFunction('demo_autres_postes', $this->autresPostes(...)),
            new TwigFunction('demo_url_courante', $this->urlCourante(...)),
        ];
    }

    public function persona(): ?Persona
    {
        $u = $this->security->getUser();
        if (null === $u) {
            return null;
        }
        $email = method_exists($u, 'getEmail') ? (string) $u->getEmail() : $u->getUserIdentifier();

        return Persona::tryFrom(explode('@', $email)[0]);
    }

    public function outil(): ?Outil
    {
        $session = $this->requetes->getSession();
        $cle = $session->has('demo_outil') ? (string) $session->get('demo_outil') : '';

        return '' !== $cle ? Outil::parCle($cle) : null;
    }

    /**
     * Les postes vers lesquels basculer depuis l'ecran courant.
     *
     * Ce sont ceux que l'outil visite declare pertinents, moins celui qu'on
     * incarne deja. Si l'outil n'est pas connu, on propose les quatre postes
     * du circuit de decision.
     *
     * @return list<Persona>
     */
    public function autresPostes(): array
    {
        $courant = $this->persona();
        $outil = $this->outil();
        $candidats = null !== $outil ? $outil->personas() : [
            Persona::SECRETAIRE, Persona::COMPTABLE,
            Persona::DIRECTEUR_CONCESSION, Persona::DIRECTEUR_COMPTABLE,
        ];

        return array_values(array_filter($candidats, static fn (Persona $p): bool => $p !== $courant));
    }

    /** L'adresse courante, pour y revenir apres avoir change de poste. */
    public function urlCourante(): string
    {
        $r = $this->requetes->getCurrentRequest();

        return null !== $r ? $r->getRequestUri() : '/demo';
    }
}
