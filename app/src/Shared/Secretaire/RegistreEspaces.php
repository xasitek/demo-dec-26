<?php

declare(strict_types=1);

namespace App\Shared\Secretaire;

use App\Shared\Enum\Module;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Rassemble les espaces declares par les modules et les rend a la barre laterale
 * comme au confinement.
 *
 * Les espaces arrivent par le tag `app.espace_secretaire`, pose automatiquement sur
 * toute implementation de FournisseurEspaceInterface. Aucun enregistrement manuel.
 */
final class RegistreEspaces
{
    /** @var list<EspaceSecretaire>|null */
    private ?array $espaces = null;

    /**
     * @param iterable<FournisseurEspaceInterface> $fournisseurs
     */
    public function __construct(
        #[AutowireIterator('app.espace_secretaire')]
        private readonly iterable $fournisseurs,
        private readonly Security $security,
    ) {
    }

    /**
     * Les espaces OUVERTS a l'utilisateur courant : ceux dont il a le module.
     *
     * La cle d'un espace est la valeur du module qui l'offre ('livraison',
     * 'remboursement'), donc le rapprochement se fait sans exception codee. Un
     * espace dont la cle ne correspond a aucun module reste visible : mieux vaut le
     * montrer a tort que le faire disparaitre sans que personne comprenne pourquoi.
     *
     * Sert a l'affichage ET au confinement : une secretaire sans le module Livraison
     * ne voit pas l'espace, et ne peut pas non plus l'atteindre en tapant l'URL.
     *
     * @return list<EspaceSecretaire>
     */
    public function espacesAccessibles(): array
    {
        $ouverts = [];
        foreach ($this->espaces() as $espace) {
            $module = Module::depuisValeur($espace->cle);
            if (null !== $module && !$this->security->isGranted($module->attribut())) {
                continue;
            }
            $ouverts[] = $espace;
        }

        return $ouverts;
    }

    /**
     * TOUS les espaces declares, tries par ordre d'affichage — sans filtre d'acces.
     *
     * @return list<EspaceSecretaire>
     */
    public function espaces(): array
    {
        if (null !== $this->espaces) {
            return $this->espaces;
        }

        $espaces = [];
        foreach ($this->fournisseurs as $fournisseur) {
            $espaces[] = $fournisseur->espace();
        }
        usort($espaces, static fn (EspaceSecretaire $a, EspaceSecretaire $b) => $a->ordre <=> $b->ordre);

        return $this->espaces = $espaces;
    }

    /** L'espace auquel appartient une route, ou null si aucune. */
    public function espacePourRoute(string $route): ?EspaceSecretaire
    {
        foreach ($this->espaces() as $espace) {
            if ($espace->contient($route)) {
                return $espace;
            }
        }

        return null;
    }

    /**
     * Toutes les routes qu'une secretaire pure est autorisee a visiter : celles des
     * espaces, plus le socle commun (authentification, notifications, pages legales).
     *
     * @return list<string>
     */
    public function routesAutorisees(): array
    {
        $routes = self::SOCLE;
        foreach ($this->espacesAccessibles() as $espace) {
            foreach ($espace->routesAutorisees as $route) {
                $routes[] = $route;
            }
        }

        return array_values(array_unique($routes));
    }

    /**
     * Ou renvoyer une secretaire qui tente d'aller ailleurs : l'accueil neutre du
     * poste, ou elle rechoisit son espace. Volontairement pas l'accueil du premier
     * espace : le poste ne privilegie aucun metier.
     */
    public function routeParDefaut(): string
    {
        return self::ACCUEIL;
    }

    /** Accueil neutre du poste, hors de tout module. */
    public const ACCUEIL = 'app_poste_secretaire';

    /**
     * Routes hors espace metier, toujours accessibles : sans elles la secretaire ne
     * pourrait ni choisir son espace, ni se connecter, ni voir ses notifications,
     * ni se deconnecter.
     */
    private const SOCLE = [
        self::ACCUEIL,
        'oauth_google_connect',
        'oauth_google_check',
        'app_oauth_popup_ok',
        'app_logout',
        'app_notification_list',
        'app_notification_ouvrir',
        'app_notification_tout_lu',
        // COPIE DE DEMONSTRATION. Les routes du portail de demonstration : sans
        // elles, un lecteur entre dans le poste secretaire et ne peut plus en
        // sortir — le confinement intercepte le changement de poste lui-meme et
        // le renvoie a son espace. Le confinement metier n'est pas affaibli :
        // ces routes ne montrent aucun dossier, elles changent de regard.
        'demo_portail',
        'demo_voir_comme',
        'demo_entrer',
        'demo_outil_entrer',
        'demo_outil_profils',
        'demo_outil_ecran',
        'demo_changer_poste',
        'demo_acces',
        'demo_acces_quitter',
    ];
}
