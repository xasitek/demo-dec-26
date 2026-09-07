<?php

declare(strict_types=1);

namespace App\Lettrage\Controller;

use App\Lettrage\Moteur\Catalogue;
use App\Lettrage\Moteur\MoteurLettrage;
use App\Lettrage\Moteur\Tolerances;
use App\Lettrage\Moteur\Verdict;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les quatre ecrans du lettrage.
 *
 *   /lettrage            la file des propositions -- le poste du comptable
 *   /lettrage/pilotage   par methode, par etablissement, causes du residuel
 *   /lettrage/methode    les 28 methodes, la cascade, le test independant
 *   /lettrage/relecture  les lettrages deja poses, a revoir
 *   /lettrage/{id}       un lot : les ecritures, les indices, la decision
 */
#[Route('/lettrage')]
#[IsGranted('MODULE_LETTRAGE')]
final class LettrageController extends AbstractController
{
    /**
     * Le resultat du test independant final du lettrage.
     *
     * Comme pour l'outil 4 : ce sont des grandeurs OBSERVEES sur une population
     * precise, mesuree une seule fois apres gel. Elles ne se generalisent pas.
     *
     * @var array<string, string>
     */
    public const RESULTAT_PUBLIE = [
        'population' => 'BLIND_O5_3',
        'date' => '07/09/2026',
    ];

    public function __construct(
        private readonly Connection $cnx,
        private readonly MoteurLettrage $moteur,
    ) {
    }

    /** La file du comptable : les propositions, et ce qu'elles soldent. */
    #[Route('', name: 'app_lettrage_file', methods: ['GET'])]
    public function fileDeTravail(Request $requete): Response
    {
        $verdict = (string) $requete->query->get('verdict', '');
        $q = trim((string) $requete->query->get('q', ''));

        $ou = ['1=1'];
        $params = [];
        if ('' !== $verdict) {
            $ou[] = 'd.verdict = :v';
            $params['v'] = $verdict;
        }
        if ('' !== $q) {
            $ou[] = '(d.lot_id ILIKE :q OR c.nom ILIKE :q OR l.client_id ILIKE :q)';
            $params['q'] = '%'.$q.'%';
        }

        return $this->render('lettrage/file.html.twig', [
            'lignes' => $this->cnx->fetchAllAssociative(
                'SELECT d.*, l.montant, l.nb_ecritures AS lignes_lot, l.cohorte, l.client_id, c.nom AS client_nom
                   FROM lettrage.decision d
                   JOIN lettrage.lot l ON l.id = d.lot_id
                   LEFT JOIN affectation.client c ON c.id = l.client_id
                  WHERE '.implode(' AND ', $ou).'
                  ORDER BY l.montant DESC LIMIT 120', $params),
            'recap' => $this->recap(),
            'verdict' => $verdict,
            'q' => $q,
            'verdicts' => Verdict::tous(),
            'vedettes' => $this->vedettes(),
        ]);
    }

    /** Le pilotage : par methode, par etablissement, causes du residuel. */
    #[Route('/pilotage', name: 'app_lettrage_pilotage', methods: ['GET'])]
    public function pilotage(): Response
    {
        return $this->render('lettrage/pilotage.html.twig', [
            'recap' => $this->recap(),
            'cascade' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM lettrage.cascade_etape ORDER BY rang'),
            'parMethode' => $this->cnx->fetchAllAssociative(
                "SELECT e.methode, e.rang, e.lignes_consommees, e.groupes, e.montant, e.duree_ms,
                        count(d.lot_id) AS lots,
                        count(d.lot_id) FILTER (WHERE d.verdict = 'automatique') AS auto,
                        count(d.lot_id) FILTER (WHERE d.verdict <> 'automatique') AS exceptions
                   FROM lettrage.cascade_etape e
                   LEFT JOIN lettrage.decision d ON d.methode = e.methode
                  GROUP BY 1,2,3,4,5,6 ORDER BY e.rang"),
            'parEtablissement' => $this->cnx->fetchAllAssociative(
                "SELECT l.etablissement_id,
                        count(*) AS lots,
                        count(*) FILTER (WHERE d.verdict = 'automatique') AS auto,
                        count(*) FILTER (WHERE d.verdict IN ('humain','refus')) AS residuel,
                        count(*) FILTER (WHERE d.arret = 'contradiction') AS anomalies,
                        coalesce(sum(l.montant), 0) AS montant
                   FROM lettrage.decision d
                   JOIN lettrage.lot l ON l.id = d.lot_id
                  GROUP BY 1 ORDER BY residuel DESC, lots DESC LIMIT 14"),
            'causes' => $this->cnx->fetchAllAssociative(
                "SELECT coalesce(d.arret, 'aucun') AS cause,
                        count(*) AS lots, coalesce(sum(l.montant), 0) AS montant
                   FROM lettrage.decision d
                   JOIN lettrage.lot l ON l.id = d.lot_id
                  WHERE d.verdict <> 'automatique'
                  GROUP BY 1 ORDER BY 3 DESC"),
            'relecture' => $this->cnx->fetchAllAssociative(
                'SELECT verdict, count(*) AS n, coalesce(sum(abs(solde)), 0) AS solde
                   FROM lettrage.relecture GROUP BY 1 ORDER BY 2 DESC'),
        ]);
    }

    /** La methode : les 28 fiches, la cascade, le test independant. */
    #[Route('/methode', name: 'app_lettrage_methode', methods: ['GET'])]
    public function methode(): Response
    {
        return $this->render('lettrage/methode.html.twig', [
            'catalogue' => Catalogue::toutes(),
            'compte' => Catalogue::compte(),
            'publie' => self::RESULTAT_PUBLIE,
            'cascade' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM lettrage.cascade_etape ORDER BY rang'),
            'bareme1v1' => Tolerances::AGE_1V1,
            'baremeGroupe' => Tolerances::AGE_NVN,
            'cohortes' => $this->cnx->fetchAllAssociative(
                "SELECT l.cohorte,
                        count(*) AS lots,
                        sum(d.nb_ecritures) AS ecritures,
                        count(*) FILTER (WHERE t.verdict = 'TRUE_MATCH') AS lettrables,
                        count(*) FILTER (WHERE d.verdict = 'automatique') AS auto,
                        count(*) FILTER (WHERE d.verdict = 'automatique' AND t.verdict = 'TRUE_MATCH') AS auto_justes,
                        count(*) FILTER (WHERE d.verdict = 'automatique' AND t.verdict <> 'TRUE_MATCH') AS faux_positifs,
                        coalesce(sum(d.nb_ecritures) FILTER (WHERE d.verdict = 'automatique' AND t.verdict <> 'TRUE_MATCH'), 0) AS ecritures_fausses,
                        count(*) FILTER (WHERE d.verdict <> 'automatique' AND t.verdict = 'TRUE_MATCH') AS faux_negatifs,
                        coalesce(sum(l.montant) FILTER (WHERE d.verdict = 'automatique' AND t.verdict = 'TRUE_MATCH'), 0) AS montant_juste,
                        coalesce(sum(l.montant) FILTER (WHERE d.verdict = 'automatique' AND t.verdict <> 'TRUE_MATCH'), 0) AS montant_faux,
                        coalesce(sum(l.montant) FILTER (WHERE d.verdict <> 'automatique'), 0) AS montant_humain,
                        round(avg(d.duree_ms)::numeric, 2) AS duree
                   FROM lettrage.decision d
                   JOIN lettrage.lot l ON l.id = d.lot_id
                   JOIN lettrage_verite.lot t ON t.objet_id = d.lot_id
                  GROUP BY 1 ORDER BY 1"),
            'matrice' => $this->cnx->fetchAllAssociative(
                "SELECT t.verdict AS attendu, d.verdict AS obtenu, count(*) AS n
                   FROM lettrage.decision d
                   JOIN lettrage.lot l ON l.id = d.lot_id
                   JOIN lettrage_verite.lot t ON t.objet_id = d.lot_id
                  WHERE l.cohorte = 'BLIND_O5_3'
                  GROUP BY 1,2 ORDER BY 3 DESC"),
            'refus' => $this->cnx->fetchAllAssociative(
                'SELECT d.arret, count(*) AS n, min(d.motif) AS exemple
                   FROM lettrage.decision d
                  WHERE d.arret IS NOT NULL GROUP BY 1 ORDER BY 2 DESC'),
            'horsSolde' => (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM lettrage.decision WHERE verdict = 'automatique' AND abs(solde) > 500"),
        ]);
    }

    /** La relecture des lettrages deja poses. */
    #[Route('/relecture', name: 'app_lettrage_relecture', methods: ['GET'])]
    public function relecture(): Response
    {
        return $this->render('lettrage/relecture.html.twig', [
            'lignes' => $this->cnx->fetchAllAssociative(
                "SELECT r.*, h.montant_debit, h.montant_credit, h.pose_le, h.client_id, c.nom AS client_nom
                   FROM lettrage.relecture r
                   JOIN lettrage.historique h ON h.code_lettrage = r.code_lettrage
                   LEFT JOIN affectation.client c ON c.id = h.client_id
                  WHERE r.verdict <> 'conforme'
                  ORDER BY abs(r.solde) DESC, r.code_lettrage LIMIT 80"),
            'recap' => $this->cnx->fetchAllAssociative(
                'SELECT verdict, count(*) AS n, coalesce(sum(abs(solde)), 0) AS solde
                   FROM lettrage.relecture GROUP BY 1 ORDER BY 2 DESC'),
            'total' => (int) $this->cnx->fetchOne('SELECT count(*) FROM lettrage.relecture'),
        ]);
    }

    /**
     * La fiche d'un lot : la cascade est REJOUEE, et chronometree sur l'appareil
     * du visiteur. Aucune duree n'est stockee pour l'affichage.
     */
    #[Route('/{id}', name: 'app_lettrage_lot', methods: ['GET'], requirements: ['id' => 'LOT-\d+'])]
    public function lot(string $id): Response
    {
        $a = $this->moteur->analyser($id);

        return $this->render('lettrage/lot.html.twig', [
            'a' => $a,
            'catalogue' => null !== $a['methode'] ? Catalogue::parCode((string) $a['methode']) : null,
            'convergence' => MoteurLettrage::CONVERGENCE_MINIMALE,
            'etablissements' => $this->cnx->fetchAllKeyValue('SELECT id, nom FROM affectation.etablissement'),
        ]);
    }

    /** @return array<string, mixed> */
    private function recap(): array
    {
        $r = $this->cnx->fetchAssociative(
            "SELECT count(*) AS lots,
                    coalesce(sum(nb_ecritures), 0) AS ecritures,
                    count(*) FILTER (WHERE verdict = 'automatique') AS auto,
                    count(*) FILTER (WHERE verdict = 'proposition') AS proposition,
                    count(*) FILTER (WHERE verdict = 'humain') AS humain,
                    count(*) FILTER (WHERE verdict = 'refus') AS refus,
                    coalesce(sum(nb_ecritures) FILTER (WHERE verdict = 'automatique'), 0) AS ecritures_auto,
                    round(avg(duree_ms)::numeric, 2) AS duree
               FROM lettrage.decision");
        if (!\is_array($r)) {
            return [];
        }
        $r['stock'] = (int) $this->cnx->fetchOne('SELECT count(*) FROM lettrage.ecriture');
        $r['non_lettrees'] = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM lettrage.ecriture WHERE lettrage IS NULL');
        $r['montant_auto'] = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(l.montant), 0) FROM lettrage.decision d
               JOIN lettrage.lot l ON l.id = d.lot_id WHERE d.verdict = 'automatique'");
        $r['montant_humain'] = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(l.montant), 0) FROM lettrage.decision d
               JOIN lettrage.lot l ON l.id = d.lot_id WHERE d.verdict <> 'automatique'");
        $r['a_revoir'] = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM lettrage.relecture WHERE verdict <> 'conforme'");

        return $r;
    }

    /**
     * Les trois cas a decouvrir, choisis pour ce qu'ils demontrent.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function vedettes(): array
    {
        $un = function (string $scenario, string $verdict): ?array {
            $r = $this->cnx->fetchAssociative(
                'SELECT d.lot_id, d.verdict, d.methode, d.arret, d.nb_indices, l.montant, l.nb_ecritures
                   FROM lettrage.decision d
                   JOIN lettrage.lot l ON l.id = d.lot_id
                   JOIN lettrage_verite.lot t ON t.objet_id = d.lot_id
                  WHERE t.code_scenario = ? AND d.verdict = ?
                  ORDER BY l.montant DESC LIMIT 1', [$scenario, $verdict]);

            return false === $r ? null : $r;
        };

        return [
            // A. aucune preuve suffisante isolement, cinq indices convergent
            'convergence' => $un('SC-05-07', Verdict::AUTOMATIQUE),
            // B. deux montants identiques, un numero de serie qui contredit
            'contradiction' => $un('SC-05-08', Verdict::HUMAIN),
            // C. un reglement global egale la somme de trois ecritures
            'sousensemble' => $un('SC-05-06', Verdict::AUTOMATIQUE),
            // D. le meme ecart de douze euros, deux anciennetes
            'jeune' => $un('SC-05-09', Verdict::HUMAIN),
            'ancien' => $un('SC-05-09', Verdict::AUTOMATIQUE),
            // E. tout concorde, le solde ne tombe pas
            'solde' => $un('SC-05-10', Verdict::REFUS),
            // F. deux sous-ensembles equilibrent le meme total
            'ambigu' => $un('SC-05-11', Verdict::HUMAIN),
        ];
    }
}
