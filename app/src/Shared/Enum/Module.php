<?php

declare(strict_types=1);

namespace App\Shared\Enum;

/**
 * Modules metier de l'application. Un utilisateur est rattache a 0..n modules :
 * il n'accede qu'aux modules auxquels il est rattache (sauf admin) et ne recoit
 * que les notifications de ces modules. Le prefixe d'URL sert au controle d'acces.
 */
enum Module: string
{
    case GARANTIES = 'garanties';
    case CREANCES = 'creances';
    case RECOUVREMENT = 'recouvrement';
    case REMBOURSEMENT = 'remboursement';
    case LIVRAISON = 'livraison';
    case BONUS_ECO = 'bonus_eco';
    case AFFECTATION = 'affectation';
    case LETTRAGE = 'lettrage';
    case COCKPIT = 'cockpit';

    public function libelle(): string
    {
        return match ($this) {
            self::GARANTIES => 'Garanties',
            self::CREANCES => 'Créances',
            self::RECOUVREMENT => 'Recouvrement',
            self::REMBOURSEMENT => 'Remboursement client',
            self::LIVRAISON => 'Livraison',
            self::BONUS_ECO => 'Bonus écologique',
            self::AFFECTATION => 'Affectation des règlements',
            self::LETTRAGE => 'Lettrage des écritures',
            self::COCKPIT => 'Cockpit BFR et DSO',
        };
    }

    /**
     * Prefixe d'URL du module (pour le controle d'acces par chemin).
     */
    public function prefixe(): string
    {
        return match ($this) {
            self::GARANTIES => '/garanties',
            self::CREANCES => '/creances',
            self::RECOUVREMENT => '/recouvrement',
            self::REMBOURSEMENT => '/remboursement',
            self::LIVRAISON => '/livraison',
            self::BONUS_ECO => '/bonus-eco',
            self::AFFECTATION => '/affectation',
            self::LETTRAGE => '/lettrage',
            self::COCKPIT => '/cockpit',
        };
    }

    /**
     * Attribut de securite associe (utilise dans security.yaml et les templates).
     */
    public function attribut(): string
    {
        return 'MODULE_'.strtoupper($this->value);
    }

    /**
     * Route d'accueil du module : ou mene son entree de menu.
     *
     * Pour Remboursement et Livraison, c'est l'ecran de GESTION et non le
     * formulaire de depot — ce dernier est offert par un espace du poste
     * secretaire, pas par le module.
     */
    public function route(): string
    {
        return match ($this) {
            self::GARANTIES => 'app_garanties_index',
            self::CREANCES => 'app_creances_index',
            self::RECOUVREMENT => 'app_recouvrement_index',
            self::REMBOURSEMENT => 'app_remboursement_accueil',
            self::LIVRAISON => 'app_livraison_controle',
            self::BONUS_ECO => 'app_bonus_eco_index',
            self::AFFECTATION => 'app_affectation_file',
            self::LETTRAGE => 'app_lettrage_file',
            self::COCKPIT => 'app_cockpit',
        };
    }

    /**
     * Teinte de l'icone du module, classe Tailwind de couleur de TEXTE : elle sert
     * telle quelle sur fond navy, et via `bg-current` pour un filet.
     */
    public function couleur(): string
    {
        return match ($this) {
            self::GARANTIES => 'text-blue-400',
            self::CREANCES => 'text-emerald-400',
            self::RECOUVREMENT => 'text-rose-400',
            self::REMBOURSEMENT => 'text-sky-400',
            self::LIVRAISON => 'text-amber-400',
            self::BONUS_ECO => 'text-lime-400',
            self::AFFECTATION => 'text-gold',
            self::LETTRAGE => 'text-gold',
            self::COCKPIT => 'text-gold',
        };
    }

    public static function depuisValeur(?string $valeur): ?self
    {
        return null === $valeur ? null : self::tryFrom($valeur);
    }
}
