<?php

declare(strict_types=1);

namespace App\GrandsComptes\Moteur;

/**
 * Le referentiel de l'outil 7 : ce qu'on attend, ce qu'on reproche, et pourquoi.
 *
 * Tout est ecrit noir sur blanc, parce qu'un dossier rejete se defend devant le
 * site qui l'a depose et devant le loueur qui attend. Un rejet dont on ne peut
 * pas produire la regle n'est pas un rejet : c'est une opinion.
 */
final class Referentiel
{
    /** Date d'arrete de la demonstration. Le monde synthetique s'arrete la. */
    public const ARRETE = '2026-09-01';

    /**
     * Les trois verdicts, et la distinction qui fait la doctrine.
     *
     * `incomplet` n'est pas une nuance de `non_conforme` : c'est son contraire
     * logique. Non conforme veut dire « j'ai lu, et c'est faux ». Incomplet veut
     * dire « je n'ai pas pu lire ». Confondre les deux fait rejeter un dossier
     * pour une piece qu'on n'a jamais ouverte, et un loueur rejete a tort est
     * une facture bloquee un mois de plus.
     *
     * @var array<string, array{libelle: string, definition: string, teinte: string, suite: string}>
     */
    public const VERDICTS = [
        'conforme' => [
            'libelle' => 'Conforme',
            'definition' => 'Toutes les pièces exigées par la grille du loueur sont présentes et '
                .'lisibles, et aucune règle du référentiel ne trouve à reprocher.',
            'teinte' => 'positive',
            'suite' => 'Le dossier part au loueur. La créance peut être recouvrée.',
        ],
        'non_conforme' => [
            'libelle' => 'Non conforme',
            'definition' => 'Les pièces ont été lues, et au moins une règle est enfreinte. '
                .'L’anomalie est nommée, et sa correction est nommée avec elle.',
            'teinte' => 'negative',
            'suite' => 'Correction par le site, puis nouveau dépôt. Une anomalie portant sur une '
                .'facture se corrige par un avoir et une refacturation.',
        ],
        'incomplet' => [
            'libelle' => 'Incomplet, à instruire',
            'definition' => 'Une pièce exigée manque, ou une valeur est illisible. Le contrôle '
                ."n'a pas pu établir la conformité : il ne conclut donc pas.",
            'teinte' => 'warning',
            'suite' => 'Compléter le dossier. Le contrôle reprendra quand la pièce sera là — '
                ."aucun rejet n'est prononcé sur une pièce qu'on n'a pas pu lire.",
        ],
    ];

    /**
     * Ce que veut dire chaque niveau d'exigence de la grille.
     *
     * @var array<string, array{libelle: string, bloquant: bool, explication: string}>
     */
    public const EXIGENCES = [
        'obligatoire' => [
            'libelle' => 'Obligatoire',
            'bloquant' => true,
            'explication' => 'Son absence bloque le dossier.',
        ],
        'si_electrique' => [
            'libelle' => 'Si véhicule électrifié',
            'bloquant' => true,
            'explication' => 'Exigée seulement pour un véhicule électrique ou hybride.',
        ],
        'si_premier_reglt' => [
            'libelle' => 'Au premier règlement',
            'bloquant' => true,
            'explication' => 'Exigée une seule fois, à la première facture adressée à ce payeur.',
        ],
        'optionnelle' => [
            'libelle' => 'Optionnelle',
            'bloquant' => false,
            'explication' => 'Acceptée si elle est là, jamais bloquante si elle manque.',
        ],
        'non_demandee' => [
            'libelle' => 'Non demandée',
            'bloquant' => false,
            'explication' => 'Ce loueur ne la demande pas. La réclamer serait une faute.',
        ],
    ];

    /**
     * Les suites possibles d'une anomalie, et la regle d'or du groupe.
     *
     * @var array<string, array{libelle: string, explication: string}>
     */
    public const SUITES = [
        'avoir_refacturation' => [
            'libelle' => 'Avoir puis refacturation',
            'explication' => "L'anomalie porte sur une facture émise. Une facture ne se retouche "
                .'pas : on établit un avoir, puis on refacture. Toute correction directe du '
                .'document serait une irrégularité comptable.',
        ],
        'redeposer' => [
            'libelle' => 'Corriger la pièce et redéposer le dossier',
            'explication' => 'La pièce elle-même est en cause. Le site la corrige et redépose le '
                .'dossier : une correction hors de l’espace de dépôt ne laisse aucune trace.',
        ],
        'declarer' => [
            'libelle' => 'Déclarer la livraison',
            'explication' => 'Un véhicule non déclaré livré, ou sans PV, équivaut à un véhicule '
                .'non livré aux yeux du loueur.',
        ],
    ];

    /** Ce que le contrôle lit, et ce qu'il ne lit pas. À dire, pas à sous-entendre. */
    public const LIMITE = 'Le contrôle est un moteur de règles déterministe et local. Il applique '
        ."les quatorze règles du référentiel aux valeurs déclarées et lues de chaque pièce. Il n'y "
        .'a ni reconnaissance optique, ni appel à un modèle de langage : le réseau est fermé au '
        .'niveau du transport dans cette démonstration, et les documents sont synthétiques. Ce qui '
        .'se démontre ici est la règle et la décision, pas la lecture automatique d’un scan.';
}
