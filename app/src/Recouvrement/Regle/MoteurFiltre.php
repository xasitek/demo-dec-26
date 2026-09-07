<?php

declare(strict_types=1);

namespace App\Recouvrement\Regle;

/**
 * Traduit les filtres d'une règle de relance (liste de {champ, operateur, valeur})
 * en une clause SQL WHERE paramétrée, appliquée sur la vue v_impayes (alias `v`).
 *
 * Sécurité : le champ doit appartenir à la LISTE BLANCHE (sinon ignoré) et les
 * valeurs passent toujours en paramètres liés -> aucune injection possible même
 * si les filtres sont saisis par l'utilisateur.
 */
final class MoteurFiltre
{
    /**
     * Champs autorisés : nom logique -> colonne de v_impayes + nature numérique.
     * (Seules ces colonnes existent aujourd'hui dans v_impayes ; type_piece et
     * montant_initial_facturation seront ajoutés lors de l'élargissement de la vue.).
     *
     * @var array<string, array{colonne: string, numerique: bool}>
     */
    public const CHAMPS = [
        'collectif' => ['colonne' => 'collectif', 'numerique' => false],
        'compte' => ['colonne' => 'compte', 'numerique' => false],
        'code_entite' => ['colonne' => 'code_entite', 'numerique' => false],
        'codeetab' => ['colonne' => 'codeetab', 'numerique' => false],
        'marque' => ['colonne' => 'marque', 'numerique' => false],
        'type_compte' => ['colonne' => 'type_compte', 'numerique' => false],
        'statut_juridique' => ['colonne' => 'statut_juridique', 'numerique' => false],
        'code_statut' => ['colonne' => 'code_statut', 'numerique' => false],
        'code_conditions_reglement' => ['colonne' => 'code_conditions_reglement', 'numerique' => false],
        'code_mode_paiement' => ['colonne' => 'code_mode_paiement', 'numerique' => false],
        'email' => ['colonne' => 'email', 'numerique' => false],
        'reference_facture' => ['colonne' => 'reference_facture', 'numerique' => false],
        'numpiece' => ['colonne' => 'numpiece', 'numerique' => false],
        'libelle' => ['colonne' => 'libelle', 'numerique' => false],
        'type_piece' => ['colonne' => 'type_piece', 'numerique' => false],
        'jours_retard' => ['colonne' => 'jours_retard', 'numerique' => true],
        'montant_solde' => ['colonne' => 'montant_solde', 'numerique' => true],
        'montant_initial' => ['colonne' => 'montant_initial', 'numerique' => true],
        'montant_initial_facturation' => ['colonne' => 'montant_initial_facturation', 'numerique' => true],
    ];

    /** Opérateurs reconnus. */
    public const OPERATEURS = ['eq', 'neq', 'in', 'notin', 'gt', 'lt', 'contient', 'commence_par'];

    /**
     * Libellés français des champs (pour le constructeur de filtres de la vue).
     * Doit couvrir exactement les clés de CHAMPS.
     *
     * @var array<string, string>
     */
    public const LIBELLES_CHAMPS = [
        'collectif' => 'Collectif (compte général)',
        'compte' => 'Code compte client',
        'code_entite' => 'Entité',
        'codeetab' => 'Établissement',
        'marque' => 'Marque',
        'type_compte' => 'Type (comptant / en compte)',
        'statut_juridique' => 'Statut juridique',
        'code_statut' => 'Famille / catégorie client',
        'code_conditions_reglement' => 'Conditions de règlement',
        'code_mode_paiement' => 'Mode de paiement',
        'email' => 'Email',
        'reference_facture' => 'Référence facture',
        'numpiece' => 'N° de pièce',
        'libelle' => 'Libellé',
        'type_piece' => 'Type de pièce (FC, OD…)',
        'jours_retard' => 'Jours de retard',
        'montant_solde' => 'Montant solde',
        'montant_initial' => 'Montant initial',
        'montant_initial_facturation' => 'Montant initial (facturation)',
    ];

    /**
     * Champs "catégoriels" : la valeur se choisit dans une liste déroulante (select)
     * plutôt qu'en saisie libre. Les options viennent des valeurs réellement présentes
     * en base (SelectionRelanceService::optionsValeursChamps).
     *
     * @var list<string>
     */
    public const CHAMPS_CATEGORIELS = [
        'code_statut', 'code_conditions_reglement', 'code_mode_paiement',
        'collectif', 'codeetab', 'marque', 'type_piece', 'type_compte',
    ];

    /**
     * Libellés métier des VALEURS codées (dictionnaires Gestion commerciale fournis par le métier ;
     * NON stockés en base). champ -> [code => libellé]. Un code présent en base mais
     * absent d'ici s'affiche tel quel. NB : les codes purement numériques deviennent
     * des clés entières en PHP (d'où int|string) ; l'accès par chaîne les retrouve.
     *
     * @var array<string, array<int|string, string>>
     */
    public const LIBELLES_VALEURS = [
        'code_statut' => [
            '01' => 'Particuliers', '02' => 'MRA', '03' => 'Flottes / Sociétés',
            '04' => 'PARTENAIRE-B', '05' => 'Loueurs', '06' => 'Administration',
            '07' => 'Personnel Constructeur', '08' => 'Marchands', '09' => 'Export',
            '10' => 'Agents', '11' => 'Garantie', '12' => 'DREU (Renault)',
            '13' => 'Assurances', '14' => 'Rétrocession (confrère)', '15' => 'Casse',
            '16' => 'Relation Clientèle (Renault)', '17' => 'Intra-groupe sauf PARTENAIRE-B',
            '18' => 'Intra-Groupe Renault', '19' => 'Cessions internes - interservices',
            '20' => 'Cess PR Mag -> Atel', '22' => 'Inter sites', '23' => 'Carrossiers',
            '25' => 'Constructeurs', '26' => 'Personnel Groupe', '27' => "Contrat d'entretien",
            '271' => "Contrat d'entretien Sigma Fleetbox", '32' => 'MRA ++', '33' => 'Réparateur Motrio',
        ],
        'code_conditions_reglement' => [
            '2' => 'À réception', '5' => '30 jours fin de mois le 10', '6' => '60 jours fin de mois',
            '9' => 'Non codifié', '10' => '30 jours fin de mois', '11' => 'Fin de mois 000 jours',
            '12' => 'Fin de mois 045 jours', '13' => 'Fin de mois 040 jours',
        ],
        'code_mode_paiement' => [
            '2' => 'Espèces', '3' => 'Carte bancaire', '5' => 'Virement bancaire',
            '6' => 'Prélèvement', '9' => 'Non codifié', '11' => 'LCR',
            '12' => 'Chèque bancaire', '17' => 'PNF Cofinoga',
        ],
    ];

    /**
     * Libellés français des opérateurs.
     *
     * @var array<string, string>
     */
    public const LIBELLES_OPERATEURS = [
        'eq' => 'est égal à',
        'neq' => 'est différent de',
        'in' => 'est dans la liste',
        'notin' => 'n\'est pas dans la liste',
        'gt' => 'est supérieur à',
        'lt' => 'est inférieur à',
        'contient' => 'contient',
        'commence_par' => 'commence par',
    ];

    /**
     * Ne conserve que les filtres valides (champ + opérateur en liste blanche) et
     * normalise la valeur : une liste "in" saisie "a, b, c" devient un tableau, un
     * champ numérique comparé (>, <) devient un nombre. Utilisé à l'enregistrement.
     *
     * @return list<array{champ: string, operateur: string, valeur: mixed}>
     */
    public function normaliserFiltres(mixed $brut): array
    {
        if (!\is_array($brut)) {
            return [];
        }

        $normalises = [];
        foreach ($brut as $filtre) {
            if (!\is_array($filtre)) {
                continue;
            }
            $champ = (string) ($filtre['champ'] ?? '');
            $operateur = (string) ($filtre['operateur'] ?? '');
            if (!isset(self::CHAMPS[$champ]) || !\in_array($operateur, self::OPERATEURS, true)) {
                continue;
            }
            // > / < n'ont de sens que sur un champ numérique (sinon "text > numeric" plante en SQL).
            if (\in_array($operateur, ['gt', 'lt'], true) && !self::CHAMPS[$champ]['numerique']) {
                continue;
            }

            $valeurBrute = $filtre['valeur'] ?? '';

            if (\in_array($operateur, ['in', 'notin'], true)) {
                $valeur = $this->listeDepuis($valeurBrute);
            } elseif (self::CHAMPS[$champ]['numerique'] && \in_array($operateur, ['gt', 'lt'], true)) {
                $valeur = is_numeric($valeurBrute) ? (float) $valeurBrute : 0.0;
            } else {
                $valeur = \is_scalar($valeurBrute) ? (string) $valeurBrute : '';
            }

            $normalises[] = ['champ' => $champ, 'operateur' => $operateur, 'valeur' => $valeur];
        }

        return $normalises;
    }

    /**
     * Transforme une valeur "in" (chaîne "a, b, c" ou tableau) en liste de chaînes
     * non vides.
     *
     * @return list<string>
     */
    private function listeDepuis(mixed $valeur): array
    {
        $elements = \is_array($valeur)
            ? $valeur
            : explode(',', \is_scalar($valeur) ? (string) $valeur : '');

        $liste = [];
        foreach ($elements as $element) {
            $texte = trim(\is_scalar($element) ? (string) $element : '');
            if ('' !== $texte) {
                $liste[] = $texte;
            }
        }

        return $liste;
    }

    /**
     * @param list<array<string, mixed>> $filtres filtres issus du JSON (structure non garantie)
     *
     * @return array{0: string, 1: array<string, scalar>} [clause WHERE (TRUE si vide), paramètres liés]
     */
    public function versSql(array $filtres): array
    {
        $clauses = [];
        $params = [];
        $i = 0;

        foreach ($filtres as $filtre) {
            $champ = (string) ($filtre['champ'] ?? '');
            $operateur = (string) ($filtre['operateur'] ?? '');
            $valeur = $filtre['valeur'] ?? null;

            if (!isset(self::CHAMPS[$champ]) || !\in_array($operateur, self::OPERATEURS, true)) {
                continue; // filtre invalide : ignoré (la validation à l'enregistrement est la vraie garde)
            }

            $col = 'v.'.self::CHAMPS[$champ]['colonne'];
            // Défensif : jamais de > / < sur un champ texte (sinon "text > numeric" plante).
            if (\in_array($operateur, ['gt', 'lt'], true) && !self::CHAMPS[$champ]['numerique']) {
                continue;
            }

            switch ($operateur) {
                case 'eq':
                    $p = 'f'.$i++;
                    $clauses[] = "{$col} = :{$p}";
                    $params[$p] = $this->scalaire($valeur);
                    break;
                case 'neq':
                    $p = 'f'.$i++;
                    $clauses[] = "{$col} <> :{$p}";
                    $params[$p] = $this->scalaire($valeur);
                    break;
                case 'gt':
                    $p = 'f'.$i++;
                    $clauses[] = "{$col} > :{$p}";
                    $params[$p] = (float) $valeur;
                    break;
                case 'lt':
                    $p = 'f'.$i++;
                    $clauses[] = "{$col} < :{$p}";
                    $params[$p] = (float) $valeur;
                    break;
                case 'contient':
                    $p = 'f'.$i++;
                    $clauses[] = "{$col} ILIKE :{$p}";
                    $params[$p] = '%'.$this->scalaire($valeur).'%';
                    break;
                case 'commence_par':
                    $p = 'f'.$i++;
                    $clauses[] = "{$col} ILIKE :{$p}";
                    $params[$p] = $this->scalaire($valeur).'%';
                    break;
                case 'in':
                    $valeurs = \is_array($valeur) ? array_values($valeur) : [$valeur];
                    $placeholders = [];
                    foreach ($valeurs as $v) {
                        $p = 'f'.$i++;
                        $placeholders[] = ':'.$p;
                        $params[$p] = $this->scalaire($v);
                    }
                    // Liste vide -> aucune ligne ne matche (règle sans valeur = inerte).
                    $clauses[] = [] === $placeholders ? 'FALSE' : "{$col} IN (".implode(', ', $placeholders).')';
                    break;
                case 'notin':
                    $valeurs = \is_array($valeur) ? array_values($valeur) : [$valeur];
                    $placeholders = [];
                    foreach ($valeurs as $v) {
                        $p = 'f'.$i++;
                        $placeholders[] = ':'.$p;
                        $params[$p] = $this->scalaire($v);
                    }
                    // Liste vide -> n'exclut rien (TRUE). Sinon NOT IN, en laissant passer
                    // les NULL (une ligne sans valeur n'est pas dans la liste d'exclusion).
                    $clauses[] = [] === $placeholders ? 'TRUE' : "({$col} IS NULL OR {$col} NOT IN (".implode(', ', $placeholders).'))';
                    break;
            }
        }

        return [[] === $clauses ? 'TRUE' : implode(' AND ', $clauses), $params];
    }

    /**
     * Clause SQL = OU des filtres de plusieurs règles (un compte est relançable
     * s'il correspond à AU MOINS une règle active). Les paramètres sont re-préfixés
     * par règle pour éviter toute collision. Liste vide -> FALSE (rien à relancer).
     *
     * @param list<list<array<string, mixed>>> $listeFiltres
     *
     * @return array{0: string, 1: array<string, scalar>}
     */
    public function versSqlUnion(array $listeFiltres): array
    {
        $clauses = [];
        $params = [];

        foreach ($listeFiltres as $i => $filtres) {
            [$where, $sousParams] = $this->versSql($filtres);
            $prefixe = 'r'.$i;
            foreach ($sousParams as $cle => $valeur) {
                $params[$prefixe.$cle] = $valeur;
            }
            $where = preg_replace_callback('/:f(\d+)/', static fn (array $m): string => ':'.$prefixe.'f'.$m[1], $where) ?? $where;
            $clauses[] = '('.$where.')';
        }

        return [[] === $clauses ? 'FALSE' : implode(' OR ', $clauses), $params];
    }

    /**
     * Évalue les filtres (combinés en ET) contre une ligne v_impayes, CÔTÉ PHP.
     * Sert à déterminer quelle règle s'applique à un compte (la 1re dont les filtres
     * matchent une de ses lignes). Un filtre invalide est ignoré (comme versSql).
     *
     * @param array<string, mixed>       $ligne
     * @param list<array<string, mixed>> $filtres
     */
    public function correspondLigne(array $ligne, array $filtres): bool
    {
        foreach ($filtres as $filtre) {
            $champ = (string) ($filtre['champ'] ?? '');
            $operateur = (string) ($filtre['operateur'] ?? '');
            if (!isset(self::CHAMPS[$champ]) || !\in_array($operateur, self::OPERATEURS, true)) {
                continue;
            }

            $valeurLigne = $ligne[self::CHAMPS[$champ]['colonne']] ?? null;
            if (!$this->comparer($valeurLigne, $operateur, $filtre['valeur'] ?? null, self::CHAMPS[$champ]['numerique'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare une valeur de ligne à la valeur attendue selon l'opérateur.
     */
    private function comparer(mixed $valeurLigne, string $operateur, mixed $attendu, bool $numerique): bool
    {
        if ($numerique && \in_array($operateur, ['gt', 'lt'], true)) {
            $a = is_numeric($valeurLigne) ? (float) $valeurLigne : 0.0;
            $b = is_numeric($attendu) ? (float) $attendu : 0.0;

            return 'gt' === $operateur ? $a > $b : $a < $b;
        }

        $texte = null === $valeurLigne ? '' : (string) $valeurLigne;
        $cible = \is_scalar($attendu) ? (string) $attendu : '';

        return match ($operateur) {
            'eq' => $texte === $cible,
            'neq' => $texte !== $cible,
            'in' => \in_array($texte, $this->listeDepuis($attendu), true),
            'notin' => !\in_array($texte, $this->listeDepuis($attendu), true),
            'contient' => '' !== $cible && str_contains(mb_strtolower($texte), mb_strtolower($cible)),
            'commence_par' => str_starts_with(mb_strtolower($texte), mb_strtolower($cible)),
            'gt' => $texte > $cible,
            'lt' => $texte < $cible,
            default => false,
        };
    }

    /**
     * Normalise une valeur en scalaire liable (les tableaux/objets deviennent '').
     */
    private function scalaire(mixed $valeur): string|int|float|bool
    {
        if (\is_scalar($valeur)) {
            return $valeur;
        }

        return '';
    }
}
