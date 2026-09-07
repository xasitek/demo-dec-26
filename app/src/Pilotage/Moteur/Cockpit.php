<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Le cockpit : ce qu'il reste reellement a piloter.
 *
 * Tout est CALCULE a l'affichage, sur la base de demonstration, et chaque
 * grandeur descend jusqu'a l'ecriture. Aucun indicateur n'est ecrit en dur :
 * un chiffre de cockpit qui ne bougerait pas quand la donnee bouge serait une
 * affirmation, pas une mesure.
 *
 * Le cockpit ne recalcule rien de ce que les outils 4 et 5 ont decide : il LIT
 * leurs decisions -- `affectation.decision`, `lettrage.decision` -- et en tire
 * les consequences. C'est ce qui rend la vue « avant / apres » honnete : elle
 * ne simule pas un effet, elle le constate.
 */
final class Cockpit
{
    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Les grandeurs d'encours, avant et apres fiabilisation.
     *
     * @param array<string, string> $filtres societe_id, etablissement_id, cycle, cause
     *
     * @return array<string, mixed>
     */
    public function encours(array $filtres = []): array
    {
        [$ou, $params] = $this->filtre($filtres, 'f');

        // ---- l'encours COMPTABLE : le poste client tel que le systeme
        // l'affiche. Ce sont les factures non soldees, et rien d'autre.
        //
        // Precision qui a son importance : ce n'est PAS le vivier de
        // rapprochement de l'outil 4. Ce vivier compte toutes les factures que
        // l'outil peut examiner, soldees comprises ; le confondre avec l'encours
        // donnait un poste client de huit cent trente millions au lieu de
        // cinquante-deux, et faisait retrancher des flux d'un stock.
        $brut = $this->cnx->fetchAssociative(
            "SELECT coalesce(sum(f.montant), 0) AS montant, count(*) AS factures,
                    count(DISTINCT f.client_id) AS comptes
               FROM affectation.facture f
              WHERE f.statut <> 'soldee' AND {$ou}", $params);

        // ---- les quatre retraitements. Chacun est une PART de cet encours, et
        // leur somme referme exactement l'ecart entre les deux lectures. Un
        // retraitement qui ne serait pas une part de l'encours -- un flux de
        // reglements, par exemple -- n'aurait rien a retrancher d'un stock.
        [$ouCause, $paramsCause] = $this->filtre($filtres, 'c');
        $part = function (string $condition) use ($ouCause, $paramsCause): float {
            return (float) $this->cnx->fetchOne(
                "SELECT coalesce(sum(c.montant), 0) FROM pilotage.cause_ouverture c
                  WHERE {$ouCause} AND {$condition}", $paramsCause);
        };

        $retraitements = [
            // 1. la creance etait deja reglee : l'outil 4 a retrouve le payeur
            //    de l'argent qui dormait en banque.
            'regle_non_affecte' => $part("c.soldee_par = 'outil-4'"),
            // 2. la creance etait deja soldee au compte : l'outil 5 a lettre
            //    le credit qui l'attendait.
            'credit_non_lettre' => $part("c.soldee_par = 'outil-5'"),
            // 3. garantie constructeur : creance sur un constructeur, pas sur
            //    le client final. Elle se recouvre par un dossier.
            'garantie_constructeur' => $part("c.cause = 'piece_manquante' AND NOT c.soldee_par_suite"),
            // 4. portee par un financeur : le client a finance son achat.
            'porte_par_financeur' => $part("c.cause = 'financeur' AND NOT c.soldee_par_suite"),
        ];

        $montantBrut = (float) ($brut['montant'] ?? 0);
        $totalRetraite = array_sum($retraitements);

        // ---- les trois natures, qu'on n'additionne JAMAIS.
        //
        // La premiere est un FLUX sur la periode. Les deux autres sont des STOCKS
        // a la date d'arrete. C'est deja une raison de ne pas les sommer ; la
        // seconde est qu'un reglement deja encaisse figurerait alors deux fois.
        $cash = (float) $this->cnx->fetchOne(
            'SELECT coalesce(sum(montant), 0) FROM affectation.virement');
        $fiabilise = $retraitements['regle_non_affecte'] + $retraitements['credit_non_lettre'];
        $exposition = $part("c.cause = 'reellement_due' AND NOT c.soldee_par_suite");

        return [
            'brut' => $montantBrut,
            'factures_brut' => (int) ($brut['factures'] ?? 0),
            'comptes' => (int) ($brut['comptes'] ?? 0),
            'retraitements' => $retraitements,
            'total_retraitements' => $totalRetraite,
            'retraite' => round($montantBrut - $totalRetraite, 2),
            'ecart' => $totalRetraite,
            'natures' => [
                'cash' => $cash,
                'fiabilise' => $fiabilise,
                'exposition' => $exposition,
            ],
        ];
    }

    /**
     * La passerelle de l'encours comptable a l'exposition financiere.
     *
     * Trois montants circulaient sans que le chemin entre eux soit ecrit :
     * 51,83 M€ d'encours comptable, 19,38 M€ d'encours a piloter, 13,02 M€
     * d'exposition. Un lecteur pouvait croire que la categorie « sans cause
     * bloquante » -- 21,57 M€ -- etait l'exposition, alors qu'elle recense
     * aussi les creances que la chaine a soldees depuis.
     *
     * Chaque etape est donc nommee, chiffree, et pointe vers les lignes qui la
     * composent. Aucune difference residuelle n'est laissee sans nom : les
     * 6,36 M€ entre l'encours a piloter et l'exposition sont les creances en
     * attente d'une decision interne, comite de concession ou comptable.
     *
     * @param array<string, string> $filtres
     *
     * @return array<string, mixed>
     */
    public function passerelle(array $filtres = []): array
    {
        $encours = $this->encours($filtres);
        [$ouCause, $paramsCause] = $this->filtre($filtres, 'c');
        $part = function (string $condition) use ($ouCause, $paramsCause): float {
            return (float) $this->cnx->fetchOne(
                "SELECT coalesce(sum(c.montant), 0) FROM pilotage.cause_ouverture c
                  WHERE {$ouCause} AND {$condition}", $paramsCause);
        };

        // La segmentation qui separe l'encours a piloter de l'exposition : ce
        // qui attend une decision interne n'est pas une exposition financiere,
        // c'est une decision a prendre.
        $decisions = [
            'decision_concession' => $part("c.cause = 'decision_concession' AND NOT c.soldee_par_suite"),
            'exception_comptable' => $part("c.cause = 'exception_comptable' AND NOT c.soldee_par_suite"),
        ];
        $enAttente = array_sum($decisions);

        return [
            'encours_comptable' => $encours['brut'],
            'retraitements' => $encours['retraitements'],
            'total_retraitements' => $encours['total_retraitements'],
            'encours_a_piloter' => $encours['retraite'],
            'decisions_internes' => $decisions,
            'total_decisions_internes' => $enAttente,
            'exposition' => round($encours['retraite'] - $enAttente, 2),
            // La verification vit ici, et pas seulement dans la commande de
            // reconciliation : l'ecran ne doit pas pouvoir afficher un chemin
            // qui ne se referme pas.
            'boucle' => abs(round($encours['retraite'] - $enAttente, 2)
                - round($encours['natures']['exposition'], 2)) <= 0.01,
        ];
    }

    /**
     * Le DSO, calcule. Numerateur, denominateur, jours, exclusions.
     *
     * @param array<string, string> $filtres
     *
     * @return array<string, mixed>
     */
    public function dso(array $filtres = []): array
    {
        [$ouCa, $paramsCa] = $this->filtre($filtres, 'ca');
        $debut = (new DateTimeImmutable(Conventions::ARRETE))
            ->modify('-'.Conventions::JOURS_PERIODE.' days')->format('Y-m-d');

        $ca = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(ca.chiffre_affaires_ttc), 0)
               FROM pilotage.chiffre_affaires ca
              WHERE ca.mois >= substr(?, 1, 7) AND {$ouCa}",
            array_merge([$debut], $paramsCa));

        $encours = $this->encours($filtres);
        $quotidien = $ca / Conventions::JOURS_PERIODE;

        $brut = $quotidien > 0 ? $encours['brut'] / $quotidien : 0.0;
        $retraite = $quotidien > 0 ? $encours['retraite'] / $quotidien : 0.0;

        return [
            'brut' => $brut,
            'retraite' => $retraite,
            // L'ecart des deux lectures, en jours. Ce n'est pas un delai gagne :
            // c'est la part de l'encours qui n'aurait jamais du y figurer.
            'ecart_lecture' => round($brut - $retraite, 1),
            'numerateur_brut' => $encours['brut'],
            'numerateur_retraite' => $encours['retraite'],
            'denominateur' => $ca,
            'quotidien' => $quotidien,
            'jours' => Conventions::JOURS_PERIODE,
            'debut' => $debut,
            'arrete' => Conventions::ARRETE,
        ];
    }

    /**
     * Le besoin en fonds de roulement de creances, et son equivalent en jours.
     *
     * @param array<string, string> $filtres
     *
     * @return array<string, mixed>
     */
    public function bfr(array $filtres = []): array
    {
        $dso = $this->dso($filtres);
        $encours = $this->encours($filtres);

        $contributions = $this->cnx->fetchAllAssociative(
            'SELECT c.cause, coalesce(sum(c.montant), 0) AS montant, count(*) AS factures
               FROM pilotage.cause_ouverture c GROUP BY 1 ORDER BY 2 DESC');

        return [
            'montant' => $encours['retraite'],
            'montant_brut' => $encours['brut'],
            'jours_ca' => $dso['quotidien'] > 0 ? $encours['retraite'] / $dso['quotidien'] : 0.0,
            'jours_ca_brut' => $dso['quotidien'] > 0 ? $encours['brut'] / $dso['quotidien'] : 0.0,
            'contributions' => $contributions,
        ];
    }

    /**
     * L'anciennete, par tranche. La somme des tranches fait le total : c'est
     * un controle de reconciliation, pas une esperance.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    public function anciennete(array $filtres = []): array
    {
        [$ou, $params] = $this->filtre($filtres, 'f');
        $lignes = [];
        foreach (Conventions::TRANCHES as $t) {
            $conditions = [];
            if (null !== $t['min']) {
                $conditions[] = sprintf("(DATE '%s' - f.echeance) > %d", Conventions::ARRETE, $t['min']);
            }
            if (null !== $t['max']) {
                $conditions[] = sprintf("(DATE '%s' - f.echeance) <= %d", Conventions::ARRETE, $t['max']);
            }
            $sql = "SELECT coalesce(sum(f.montant), 0) AS montant, count(*) AS factures
                      FROM affectation.facture f
                     WHERE f.statut <> 'soldee' AND {$ou}".
                ([] !== $conditions ? ' AND '.implode(' AND ', $conditions) : '');
            $r = $this->cnx->fetchAssociative($sql, $params);
            $lignes[] = [
                'code' => $t['code'], 'libelle' => $t['libelle'],
                'montant' => (float) ($r['montant'] ?? 0),
                'factures' => (int) ($r['factures'] ?? 0),
            ];
        }

        return $lignes;
    }

    /**
     * La matrice multi-sites. Une ligne par etablissement, triable.
     *
     * @return list<array<string, mixed>>
     */
    public function multiSites(): array
    {
        return $this->cnx->fetchAllAssociative(
            "WITH encours AS (
                SELECT f.etablissement_id, sum(f.montant) AS montant, count(*) AS factures,
                       sum(f.montant) FILTER (WHERE (DATE '".Conventions::ARRETE."' - f.echeance) > 90) AS plus90
                  FROM affectation.facture f WHERE f.statut <> 'soldee' GROUP BY 1
             ), ca AS (
                SELECT etablissement_id, sum(chiffre_affaires_ttc) AS ca
                  FROM pilotage.chiffre_affaires
                 WHERE mois >= substr((DATE '".Conventions::ARRETE."' - INTERVAL '".Conventions::JOURS_PERIODE." days')::text, 1, 7)
                 GROUP BY 1
             ), nonaffecte AS (
                SELECT v.societe_id, sum(v.montant) AS montant
                  FROM affectation.virement v
                  LEFT JOIN affectation.decision d ON d.virement_id = v.id
                 WHERE d.virement_id IS NULL OR d.decision <> 'automatique'
                 GROUP BY 1
             ), nonlettre AS (
                SELECT e.etablissement_id, sum(e.montant) AS montant, count(*) AS lignes
                  FROM lettrage.ecriture e
                 WHERE e.lettrage IS NULL AND e.sens = 'C' GROUP BY 1
             ), bloques AS (
                SELECT etablissement_id, count(*) AS n
                  FROM pilotage.cause_ouverture
                 WHERE cause IN ('piece_manquante', 'decision_concession')
                   AND NOT soldee_par_suite GROUP BY 1
             )
             SELECT et.id AS etablissement_id, et.nom, et.societe_id,
                    coalesce(encours.montant, 0) AS encours,
                    coalesce(encours.factures, 0) AS factures,
                    coalesce(encours.plus90, 0) AS plus90,
                    coalesce(ca.ca, 0) AS ca,
                    CASE WHEN coalesce(ca.ca, 0) > 0
                         THEN coalesce(encours.montant, 0) / (ca.ca / ".Conventions::JOURS_PERIODE.')
                         ELSE 0 END AS dso,
                    coalesce(nonlettre.montant, 0) AS non_lettre,
                    coalesce(nonlettre.lignes, 0) AS lignes_non_lettrees,
                    coalesce(bloques.n, 0) AS dossiers_bloques
               FROM affectation.etablissement et
               LEFT JOIN encours ON encours.etablissement_id = et.id
               LEFT JOIN ca ON ca.etablissement_id = et.id
               LEFT JOIN nonlettre ON nonlettre.etablissement_id = et.id
               LEFT JOIN bloques ON bloques.etablissement_id = et.id
              ORDER BY encours DESC');
    }

    /**
     * Les causes d'ouverture, et l'outil qui prend la suite.
     *
     * @return list<array<string, mixed>>
     */
    public function causes(): array
    {
        return $this->cnx->fetchAllAssociative(
            'SELECT cause, suite, count(*) AS factures, coalesce(sum(montant), 0) AS montant
               FROM pilotage.cause_ouverture
              WHERE NOT soldee_par_suite GROUP BY 1, 2 ORDER BY 4 DESC');
    }

    /**
     * Les plus gros payeurs de l'exposition.
     *
     * @return list<array<string, mixed>>
     */
    public function topPayeurs(int $limite = 12): array
    {
        return $this->cnx->fetchAllAssociative(
            'SELECT c.client_id, cl.nom, cl.type,
                    coalesce(sum(c.montant), 0) AS montant, count(*) AS factures,
                    count(DISTINCT c.etablissement_id) AS etablissements
               FROM pilotage.cause_ouverture c
               LEFT JOIN affectation.client cl ON cl.id = c.client_id
              WHERE NOT c.soldee_par_suite
              GROUP BY 1, 2, 3 ORDER BY 4 DESC LIMIT '.$limite);
    }

    /**
     * L'etat AVANT fiabilisation : ce que le cockpit afficherait si les outils
     * 4 et 5 n'avaient rien decide.
     *
     * @return array<string, mixed>
     */
    public function avant(): array
    {
        return [
            'encours_apparent' => (float) $this->cnx->fetchOne(
                "SELECT coalesce(sum(montant), 0) FROM affectation.facture WHERE statut <> 'soldee'"),
            'factures_ouvertes' => (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM affectation.facture WHERE statut <> 'soldee'"),
            'non_affecte' => (float) $this->cnx->fetchOne(
                'SELECT coalesce(sum(montant), 0) FROM affectation.virement'),
            'virements_non_affectes' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM affectation.virement'),
            'non_lettre' => (float) $this->cnx->fetchOne(
                "SELECT coalesce(sum(montant), 0) FROM lettrage.ecriture WHERE lettrage IS NULL AND sens = 'C'"),
            'lignes_non_lettrees' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM lettrage.ecriture WHERE lettrage IS NULL'),
        ];
    }

    /**
     * L'etat APRES application des decisions reellement prises par 4 et 5.
     *
     * Rien n'est simule : on compte ce que les deux moteurs ont decide, et on
     * en tire les consequences sur les grandeurs du cockpit.
     *
     * @return array<string, mixed>
     */
    public function apres(): array
    {
        $affecte = $this->cnx->fetchAssociative(
            "SELECT count(*) AS n, coalesce(sum(v.montant), 0) AS montant
               FROM affectation.decision d
               JOIN affectation.virement v ON v.id = d.virement_id
              WHERE d.decision = 'automatique'");
        $lettre = $this->cnx->fetchAssociative(
            "SELECT count(*) AS lots, coalesce(sum(l.montant), 0) AS montant,
                    coalesce(sum(ld.nb_ecritures), 0) AS ecritures
               FROM lettrage.decision ld
               JOIN lettrage.lot l ON l.id = ld.lot_id
              WHERE ld.verdict = 'automatique'");
        $humain = $this->cnx->fetchAssociative(
            "SELECT count(*) AS lots, coalesce(sum(l.montant), 0) AS montant
               FROM lettrage.decision ld
               JOIN lettrage.lot l ON l.id = ld.lot_id
              WHERE ld.verdict <> 'automatique'");
        $virementsHumain = $this->cnx->fetchAssociative(
            "SELECT count(*) AS n, coalesce(sum(v.montant), 0) AS montant
               FROM affectation.decision d
               JOIN affectation.virement v ON v.id = d.virement_id
              WHERE d.decision <> 'automatique'");

        return [
            'affecte_n' => (int) ($affecte['n'] ?? 0),
            'affecte_montant' => (float) ($affecte['montant'] ?? 0),
            'non_affecte_restant_n' => (int) ($virementsHumain['n'] ?? 0),
            'non_affecte_restant' => (float) ($virementsHumain['montant'] ?? 0),
            'lettre_lots' => (int) ($lettre['lots'] ?? 0),
            'lettre_montant' => (float) ($lettre['montant'] ?? 0),
            'lettre_ecritures' => (int) ($lettre['ecritures'] ?? 0),
            'non_lettre_restant_lots' => (int) ($humain['lots'] ?? 0),
            'non_lettre_restant' => (float) ($humain['montant'] ?? 0),
            'exposition' => (float) $this->cnx->fetchOne(
                "SELECT coalesce(sum(montant), 0) FROM pilotage.cause_ouverture
                  WHERE cause = 'reellement_due' AND NOT soldee_par_suite"),
            'factures_reellement_dues' => (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM pilotage.cause_ouverture
                  WHERE cause = 'reellement_due' AND NOT soldee_par_suite"),
            'soldees_par_la_chaine' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM pilotage.cause_ouverture WHERE soldee_par_suite'),
        ];
    }

    /**
     * Construit la clause de filtre et ses parametres.
     *
     * @param array<string, string> $filtres
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function filtre(array $filtres, string $alias): array
    {
        $ou = ['1=1'];
        $params = [];
        if ('' !== ($filtres['societe_id'] ?? '')) {
            $ou[] = "{$alias}.societe_id = ?";
            $params[] = $filtres['societe_id'];
        }
        if ('' !== ($filtres['etablissement_id'] ?? '')) {
            $ou[] = "{$alias}.etablissement_id = ?";
            $params[] = $filtres['etablissement_id'];
        }
        if ('' !== ($filtres['cycle'] ?? '') && 'ca' === $alias) {
            $ou[] = "{$alias}.cycle = ?";
            $params[] = $filtres['cycle'];
        }
        if ('' !== ($filtres['cycle'] ?? '') && 'c' === $alias) {
            // La table des causes ne porte pas le cycle : le filtre passe par
            // la facture. On ne filtre donc pas ici, et on le dit plutot que de
            // laisser croire a un filtrage silencieux.
            $ou[] = "{$alias}.facture_id IN (SELECT id FROM affectation.facture WHERE type = ?)";
            $params[] = $filtres['cycle'];
        }
        if ('' !== ($filtres['cycle'] ?? '') && 'f' === $alias) {
            $ou[] = "{$alias}.type = ?";
            $params[] = $filtres['cycle'];
        }

        return [implode(' AND ', $ou), $params];
    }
}
