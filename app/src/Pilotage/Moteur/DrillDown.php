<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

use Doctrine\DBAL\Connection;

/**
 * La descente d'un chiffre de plusieurs millions jusqu'a l'ecriture.
 *
 * Sept niveaux, six clics : groupe, societe, etablissement, payeur, compte
 * client, facture, ecriture. Chaque niveau conserve les filtres du precedent --
 * un drill-down qui perdrait le filtre en descendant ne serait pas une descente,
 * ce serait une autre requete.
 *
 * C'est la condition pour qu'un indicateur soit defendable : un examinateur doit
 * pouvoir demander « montrez-moi les lignes qui composent ce chiffre » et
 * arriver a l'ecriture comptable.
 */
final class DrillDown
{
    /**
     * Les niveaux, dans l'ordre de la descente.
     *
     * @var array<string, array{libelle: string, suivant: string|null, colonne: string}>
     */
    public const NIVEAUX = [
        'groupe' => ['libelle' => 'Groupe', 'suivant' => 'societe', 'colonne' => 'societe_id'],
        'societe' => ['libelle' => 'Société', 'suivant' => 'etablissement', 'colonne' => 'etablissement_id'],
        'etablissement' => ['libelle' => 'Établissement', 'suivant' => 'payeur', 'colonne' => 'client_id'],
        'payeur' => ['libelle' => 'Payeur', 'suivant' => 'compte', 'colonne' => 'client_id'],
        'compte' => ['libelle' => 'Compte client', 'suivant' => 'facture', 'colonne' => 'facture_id'],
        'facture' => ['libelle' => 'Facture', 'suivant' => 'ecriture', 'colonne' => 'facture_id'],
        'ecriture' => ['libelle' => 'Écriture', 'suivant' => null, 'colonne' => 'id'],
    ];

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Les lignes d'un niveau, sous les filtres accumules.
     *
     * @param array<string, string> $filtres
     *
     * @return array{lignes: list<array<string, mixed>>, total: float, ms: float}
     */
    public function lignes(string $niveau, array $filtres, string $nature = 'encours'): array
    {
        $t0 = microtime(true);
        [$ou, $params] = $this->clause($filtres, $nature);

        $lignes = match ($niveau) {
            'groupe', 'societe' => $this->cnx->fetchAllAssociative(
                "SELECT c.societe_id AS cle, c.societe_id AS libelle,
                        coalesce(sum(c.montant), 0) AS montant, count(*) AS factures,
                        count(DISTINCT c.etablissement_id) AS sous_niveaux
                   FROM pilotage.cause_ouverture c WHERE {$ou}
                  GROUP BY 1, 2 ORDER BY 3 DESC", $params),

            'etablissement' => $this->cnx->fetchAllAssociative(
                "SELECT c.etablissement_id AS cle, coalesce(e.nom, c.etablissement_id) AS libelle,
                        coalesce(sum(c.montant), 0) AS montant, count(*) AS factures,
                        count(DISTINCT c.client_id) AS sous_niveaux
                   FROM pilotage.cause_ouverture c
                   LEFT JOIN affectation.etablissement e ON e.id = c.etablissement_id
                  WHERE {$ou} GROUP BY 1, 2 ORDER BY 3 DESC", $params),

            'payeur', 'compte' => $this->cnx->fetchAllAssociative(
                "SELECT c.client_id AS cle, coalesce(cl.nom, c.client_id) AS libelle,
                        coalesce(sum(c.montant), 0) AS montant, count(*) AS factures,
                        count(DISTINCT c.facture_id) AS sous_niveaux, cl.type AS complement
                   FROM pilotage.cause_ouverture c
                   LEFT JOIN affectation.client cl ON cl.id = c.client_id
                  WHERE {$ou} GROUP BY 1, 2, 6 ORDER BY 3 DESC LIMIT 200", $params),

            'facture' => $this->cnx->fetchAllAssociative(
                "SELECT c.facture_id AS cle, coalesce(f.numero, c.facture_id) AS libelle,
                        c.montant, 1 AS factures,
                        (SELECT count(*) FROM lettrage.ecriture e WHERE e.facture_id = c.facture_id) AS sous_niveaux,
                        c.cause AS complement, c.echeance, c.soldee_par
                   FROM pilotage.cause_ouverture c
                   LEFT JOIN affectation.facture f ON f.id = c.facture_id
                  WHERE {$ou} ORDER BY c.montant DESC LIMIT 200", $params),

            'ecriture' => $this->cnx->fetchAllAssociative(
                'SELECT e.id AS cle, e.id AS libelle, e.montant, 1 AS factures, 0 AS sous_niveaux,
                        e.compte AS complement, e.sens, e.journal, e.date_ecriture,
                        e.lettrage, e.vin8, e.reference_piece
                   FROM lettrage.ecriture e
                  WHERE e.facture_id = ? ORDER BY e.sens DESC, e.date_ecriture',
                [$filtres['facture_id'] ?? '']),

            default => [],
        };

        $total = array_sum(array_map(static fn (array $l): float => (float) $l['montant'], $lignes));

        return ['lignes' => $lignes, 'total' => $total, 'ms' => (microtime(true) - $t0) * 1000];
    }

    /**
     * Le fil d'Ariane : ou l'on est, et comment on est arrive.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array{niveau: string, libelle: string, valeur: string}>
     */
    public function chemin(array $filtres): array
    {
        $chemin = [];
        foreach ([
            'societe_id' => 'Société',
            'etablissement_id' => 'Établissement',
            'client_id' => 'Compte client',
            'facture_id' => 'Facture',
        ] as $cle => $libelle) {
            if ('' !== ($filtres[$cle] ?? '')) {
                $chemin[] = ['niveau' => $cle, 'libelle' => $libelle, 'valeur' => $filtres[$cle]];
            }
        }

        return $chemin;
    }

    /**
     * @param array<string, string> $filtres
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function clause(array $filtres, string $nature): array
    {
        $ou = ['1=1'];
        $params = [];
        foreach (['societe_id', 'etablissement_id', 'client_id', 'facture_id', 'cause'] as $champ) {
            if ('' !== ($filtres[$champ] ?? '')) {
                $ou[] = "c.{$champ} = ?";
                $params[] = $filtres[$champ];
            }
        }

        // La nature decide du perimetre, et elle doit etre explicite : on ne
        // descend pas dans « l'exposition » pour trouver des creances deja
        // soldees par la chaine.
        if ('exposition' === $nature) {
            $ou[] = "c.cause = 'reellement_due' AND NOT c.soldee_par_suite";
        } elseif ('fiabilise' === $nature) {
            $ou[] = 'c.soldee_par_suite';
        }

        return [implode(' AND ', $ou), $params];
    }
}
