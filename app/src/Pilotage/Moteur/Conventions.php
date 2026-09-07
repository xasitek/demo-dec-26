<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

/**
 * Les conventions de calcul du cockpit, ecrites noir sur blanc.
 *
 * Un indicateur de pilotage ne vaut que par sa convention. Deux DSO calcules
 * autrement ne se comparent pas, et un DSO dont on ne peut pas produire le
 * numerateur, le denominateur, le nombre de jours et les exclusions n'est pas
 * un indicateur : c'est une opinion chiffree.
 *
 * Chaque grandeur affichee par le cockpit renvoie donc a l'une de ces
 * conventions, et l'ecran permet de l'ouvrir.
 */
final class Conventions
{
    /** Date d'arrete du cockpit. Le monde synthetique s'arrete la. */
    public const ARRETE = '2026-09-01';

    /** Nombre de jours de la periode de reference du DSO. */
    public const JOURS_PERIODE = 90;

    /**
     * Les comptes qui portent une creance client a recouvrer.
     *
     * 4111 est le compte client ordinaire. 4114 porte les clients douteux ou
     * transferes. 4116 porte les garanties constructeur : c'est une creance,
     * mais sur un constructeur, pas sur le client final.
     *
     * @var list<string>
     */
    public const COMPTES_CREANCE = ['4111000', '4114000', '4116000'];

    /**
     * Les comptes qui NE sont pas une creance client, et qui polluent l'encours
     * quand on le lit brut.
     *
     * @var list<string>
     */
    public const COMPTES_HORS_CREANCE = ['5120200', '4012000', '4018000', '4118000', '471000', '4679999'];

    /** Tranches d'anciennete, en jours. La derniere est ouverte. */
    public const TRANCHES = [
        ['code' => 'courant', 'min' => null, 'max' => 0, 'libelle' => 'Non échu'],
        ['code' => 'j30', 'min' => 0, 'max' => 30, 'libelle' => 'Échu 1 à 30 jours'],
        ['code' => 'j45', 'min' => 30, 'max' => 45, 'libelle' => 'Échu 31 à 45 jours'],
        ['code' => 'j90', 'min' => 45, 'max' => 90, 'libelle' => 'Échu 46 à 90 jours'],
        ['code' => 'j180', 'min' => 90, 'max' => 180, 'libelle' => 'Échu 91 à 180 jours'],
        ['code' => 'plus180', 'min' => 180, 'max' => null, 'libelle' => 'Échu plus de 180 jours'],
    ];

    /**
     * Les trois grandeurs qu'on n'additionne JAMAIS.
     *
     * C'est la signature de la suite, et elle vient d'une faute qu'on voit
     * partout : presenter une baisse de l'encours non lettre comme du cash
     * genere. L'argent d'un reglement deja encaisse mais mal affecte est deja
     * en banque. Le lettrer ne cree pas un euro : il fiabilise une lecture.
     *
     * @var array<string, array{libelle: string, suffixe: string, definition: string, teinte: string}>
     */
    public const NATURES = [
        'cash' => [
            'libelle' => 'Cash encaissé',
            'suffixe' => 'flux de règlements encaissés sur la période',
            'definition' => 'INDICATEUR DE CONTEXTE. Flux de règlements réellement entrés en '
                ."trésorerie sur la période, quel que soit leur état d'affectation. "
                .'Il ne se cumule pas avec les positions à l\'arrêté ci-contre : ce sont des '
                .'grandeurs de nature différente.',
            'teinte' => 'positive',
        ],
        'fiabilise' => [
            'libelle' => 'Encours fiabilisé',
            'suffixe' => "position à la date d'arrêté",
            'definition' => 'Argent DÉJÀ REÇU, désormais correctement affecté et lettré. '
                ."Ce n'est pas un encaissement nouveau : c'est une lecture des comptes qui devient juste. "
                .'Le compter comme du cash serait le compter deux fois.',
            'teinte' => 'navy',
        ],
        'exposition' => [
            'libelle' => 'Exposition financière à traiter',
            'suffixe' => "position à la date d'arrêté",
            'definition' => 'Créances réellement encore ouvertes après affectation et lettrage, '
                .'et sans décision interne en attente. '
                ."C'est le seul des trois montants sur lequel une action de recouvrement a un sens.",
            'teinte' => 'warning',
        ],
    ];

    /**
     * La convention du DSO, telle qu'elle est appliquee.
     *
     * @return array<string, string>
     */
    public static function dso(): array
    {
        return [
            'nom' => 'DSO, méthode par épuisement du chiffre d\'affaires (count-back)',
            'numerateur' => "Encours client TTC à la date d'arrêté, comptes 4111, 4114 et 4116.",
            'denominateur' => sprintf(
                "Chiffre d'affaires TTC des %d jours précédant l'arrêté, par société, établissement et cycle.",
                self::JOURS_PERIODE),
            'jours' => sprintf('%d jours de période de référence.', self::JOURS_PERIODE),
            'formule' => 'DSO = encours ÷ (chiffre d\'affaires de la période ÷ nombre de jours de la période).',
            'exclusions' => 'Sont exclus du numérateur : les comptes de banque, de fournisseur, '
                ."de liaison et d'attente, qui ne sont pas des créances clients. "
                .'Sont exclus du DSO retraité, en plus : les règlements déjà encaissés non encore affectés, '
                ."les crédits non lettrés, et les créances dont la cause d'ouverture n'est pas un impayé.",
            'arrete' => self::ARRETE,
            'limite' => "Le chiffre d'affaires est dérivé du portefeuille de factures émises du monde "
                .'synthétique, ramené hors taxes au taux de 20 %. Numérateur et dénominateur sortent donc '
                .'de la même source, ce qui rend le ratio cohérent — et strictement propre à cette '
                .'démonstration : il ne se compare à aucun DSO réel.',
        ];
    }

    /**
     * La convention du besoin en fonds de roulement de creances.
     *
     * @return array<string, string>
     */
    public static function bfr(): array
    {
        return [
            'nom' => 'BFR de créances clients',
            'definition' => 'Part du besoin en fonds de roulement portée par le poste clients : '
                ."l'encours à recouvrer, c'est-à-dire l'argent que le groupe a facturé et pas encore reçu.",
            'formule' => "BFR créances = encours client retraité à la date d'arrêté.",
            'jours' => "Exprimé aussi en jours de chiffre d'affaires : BFR ÷ (chiffre d'affaires quotidien).",
            'pourquoi_retraite' => "L'encours brut surestime le BFR de tout ce qui est déjà encaissé "
                ."mais mal imputé. Un BFR calculé sur l'encours brut fait porter au groupe un besoin de "
                .'financement qu\'il n\'a pas.',
            'arrete' => self::ARRETE,
        ];
    }

    /**
     * Les retraitements qui menent de l'encours comptable a l'encours a piloter.
     *
     * Chacun est une SOUSTRACTION documentee, et leur somme doit refermer
     * l'ecart exactement. C'est le premier controle de reconciliation.
     *
     * @return list<array{code: string, libelle: string, motif: string}>
     */
    public static function retraitements(): array
    {
        return [
            [
                'code' => 'regle_non_affecte',
                'libelle' => 'Règlements encaissés, pas encore affectés',
                'motif' => "L'argent est en banque. La créance apparaît ouverte parce que le règlement "
                    ."n'a pas trouvé son compte client. Ce n'est pas une exposition, c'est un défaut d'imputation.",
            ],
            [
                'code' => 'credit_non_lettre',
                'libelle' => 'Crédits du compte client non lettrés',
                'motif' => 'Un crédit dort dans le compte du client sans être rapproché de sa facture. '
                    .'Il diminue la créance réelle sans diminuer la créance affichée.',
            ],
            [
                'code' => 'garantie_constructeur',
                'libelle' => 'Garanties constructeur',
                'motif' => 'Créance sur un constructeur, pas sur le client final. Elle se recouvre par '
                    .'un dossier de garantie, pas par une relance client.',
            ],
            [
                'code' => 'porte_par_financeur',
                'libelle' => 'Créances portées par un financeur',
                'motif' => "Le client a financé son achat : c'est le financeur qui doit. La relance "
                    .'adressée au client serait une erreur de destinataire.',
            ],
        ];
    }

    /**
     * La mention methodologique qui evite une contradiction apparente.
     *
     * Le cockpit compte sur l'univers complet de demonstration ; les taux de
     * precision des outils 4 et 5 portent sur des populations de test
     * independantes, bien plus petites. Sans cette phrase, un lecteur compare
     * dix mille virements affectes a mille six cent quatre-vingt-dix-neuf
     * affectations et croit a une incoherence.
     */
    public const MENTION_PERIMETRES = 'Les volumes du cockpit portent sur l\'univers complet de '
        .'démonstration. Les taux de précision publiés pour les outils 4 et 5 proviennent de '
        .'populations de test indépendantes et ne portent donc pas sur le même nombre '
        ."d'opérations.";

    /**
     * Les cinq causes pour lesquelles une creance reste ouverte, et leur suite.
     *
     * @return array<string, array{libelle: string, suite: string, outil: string}>
     */
    public static function causes(): array
    {
        return [
            'reellement_due' => [
                // Le libelle a ete corrige le 07/09/2026. « Reellement due »
                // laissait entendre que la categorie entiere etait l'exposition
                // a traiter, alors qu'elle recense TOUTES les creances sans
                // blocage technique identifie -- y compris celles que la chaine
                // a soldees depuis. La categorie mesure une absence de cause
                // bloquante, pas une exigibilite constatee.
                'libelle' => 'Créance ouverte sans cause technique bloquante identifiée',
                'suite' => 'Relance graduée, adressée au bon interlocuteur. '
                    ."La part encore ouverte après la chaîne constitue l'exposition financière à traiter.",
                'outil' => 'outil-10',
            ],
            'piece_manquante' => [
                'libelle' => 'Bloquée par une pièce manquante',
                'suite' => 'Compléter le dossier du grand compte : une seule pièce absente suffit à bloquer le paiement.',
                'outil' => 'outil-7',
            ],
            'decision_concession' => [
                'libelle' => 'Décision de concession en attente',
                'suite' => 'Passage en comité de créances : geste commercial, litige, ou abandon.',
                'outil' => 'outil-9',
            ],
            'financeur' => [
                'libelle' => 'Due par un financeur',
                'suite' => "Le payeur n'est pas le facturé : l'affectation le sait, la relance doit le savoir aussi.",
                'outil' => 'outil-4',
            ],
            'exception_comptable' => [
                'libelle' => 'Exception comptable',
                'suite' => 'Écart, avoir en attente ou imputation à revoir : la main passe au comptable.',
                'outil' => 'comptable',
            ],
        ];
    }
}
