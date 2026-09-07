<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Enum\Module;
use App\Shared\Secretaire\RegistreEspaces;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Les modules qu'un utilisateur peut ouvrir, pour la barre laterale et l'accueil du
 * poste secretaire.
 *
 * Aucune liste de modules n'est ecrite dans un gabarit : elle vient de
 * `App\Shared\Enum\Module`, qui porte deja le libelle, le prefixe d'URL, l'attribut
 * de securite, la route d'accueil et la teinte. Ajouter ou retirer un module se fait
 * donc a un seul endroit, et l'ORDRE des cas de l'enum est l'ordre du menu.
 *
 * Le filtre passe par le voteur (`ModuleVoter`) et non par `User::modules` : les
 * administrateurs voient tout sans y etre rattaches, et c'est lui qui le sait.
 */
final class ModuleExtension extends AbstractExtension
{
    /**
     * Roles qui gardent l'application complete. Meme regle que
     * ConfinementSecretaireListener et SecretaireExtension. Lue via `Security`, donc
     * la hierarchie est resolue : ROLE_SUPER_ADMIN implique ROLE_ADMIN.
     */
    private const ROLES_APPLICATION = ['ROLE_COMPTABLE', 'ROLE_MANAGER', 'ROLE_DIRECTEUR', 'ROLE_ADMIN'];

    public function __construct(
        private readonly Security $security,
        private readonly RegistreEspaces $registre,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('modules_accessibles', $this->accessibles(...)),
        ];
    }

    /**
     * Les modules ouverts a l'utilisateur courant.
     *
     * Sans parametre : l'extension a le contexte de securite, elle n'a pas besoin
     * qu'un gabarit lui dise qui regarde — et un drapeau oublie a l'appel donnait un
     * doublon (« Remboursement client » apparaissait comme espace ET comme module).
     *
     * Pour une secretaire pure, les modules deja offerts comme espace de depot sont
     * retires : Remboursement et Livraison sont ses formulaires, pas ses ecrans de
     * gestion. Le rapprochement se fait sur la cle de l'espace, donc sans exception
     * codee en dur.
     *
     * @return list<Module>
     */
    public function accessibles(): array
    {
        $offertsEnEspace = [];
        if ($this->estSecretairePure()) {
            foreach ($this->registre->espaces() as $espace) {
                $offertsEnEspace[] = $espace->cle;
            }
        }

        $modules = [];
        foreach (Module::cases() as $module) {
            if (\in_array($module->value, $offertsEnEspace, true)) {
                continue;
            }
            if (!$this->security->isGranted($module->attribut())) {
                continue;
            }
            $modules[] = $module;
        }

        return $modules;
    }

    /** ROLE_SECRETAIRE sans aucun role d'application. */
    private function estSecretairePure(): bool
    {
        if (!$this->security->isGranted('ROLE_SECRETAIRE')) {
            return false;
        }

        foreach (self::ROLES_APPLICATION as $eleve) {
            if ($this->security->isGranted($eleve)) {
                return false;
            }
        }

        return true;
    }
}
