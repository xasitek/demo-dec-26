<?php

declare(strict_types=1);

namespace App\Affectation\Controller;

use App\Affectation\Moteur\Bareme;
use App\Affectation\Moteur\Decision;
use App\Affectation\Moteur\MoteurAffectation;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Outil n° 4 — identification du payeur et affectation des reglements.
 *
 * Quatre ecrans, un par usage :
 *   la file            le comptable traite les virements
 *   la fiche           il en ouvre un, l'analyse, decide
 *   le pilotage        le directeur comptable regarde les volumes et les ecarts
 *   la methode         l'expert-comptable audite les regles et la performance
 *
 * Le meme virement se regarde depuis n'importe lequel de ces postes : c'est
 * « Voir comme… » qui bascule, et la fiche s'adapte au poste.
 */
#[Route('/affectation')]
#[IsGranted('MODULE_AFFECTATION')]
final class AffectationController extends AbstractController
{
    /**
     * Le resultat du test independant final de l'outil 4, arrete le 07/09/2026.
     *
     * Ce sont des grandeurs OBSERVEES sur une population precise : deux mille
     * virements tires d'une graine reservee a ce test, mesures une seule fois,
     * apres gel du generateur, des poids, des seuils et des regles d'arret.
     *
     * Elles ne se generalisent pas, et la formulation le dit. « Cent pour cent
     * de precision observee sur mille six cent quatre-vingt-dix-neuf
     * affectations » est un constat ; « le moteur est precis a cent pour cent »
     * serait une affirmation, et elle serait fausse.
     *
     * @var array<string, string>
     */
    public const RESULTAT_PUBLIE = [
        'population' => 'BLIND_TEST_4',
        'virements' => '2 000',
        'automatiques' => '1 699',
        'precision' => '100,00',
        'automatisation' => '85,0',
        'humain' => '15,0',
        'montant_juste' => '38,1',
        'montant_a_tort' => '0',
        'date' => '07/09/2026',
    ];

    public function __construct(
        private readonly Connection $cnx,
        private readonly MoteurAffectation $moteur,
    ) {
    }

    /** La file de travail du comptable. */
    #[Route('', name: 'app_affectation_file', methods: ['GET'])]
    public function fileDeTravail(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $decision = (string) $request->query->get('decision', '');
        $cohorte = (string) $request->query->get('cohorte', '');

        $ou = ['1=1'];
        $params = [];
        if ('' !== $q) {
            $ou[] = '(v.id ILIKE :q OR v.libelle ILIKE :q OR v.nom_donneur_ordre ILIKE :q OR c.nom ILIKE :q)';
            $params['q'] = '%'.$q.'%';
        }
        if ('' !== $decision) {
            $ou[] = 'd.decision = :dec';
            $params['dec'] = $decision;
        }
        if ('' !== $cohorte) {
            $ou[] = 'v.cohorte = :coh';
            $params['coh'] = $cohorte;
        }

        $sql = 'SELECT v.*, d.decision, d.score, d.score_suivant, d.nb_factures, d.montant_explique,
                       d.duree_ms, d.nb_candidats, c.nom AS client_nom, c.type AS client_type
                  FROM affectation.virement v
                  LEFT JOIN affectation.decision d ON d.virement_id = v.id
                  LEFT JOIN affectation.client c ON c.id = d.client_propose
                 WHERE '.implode(' AND ', $ou).'
                 ORDER BY v.vedette DESC, d.score DESC NULLS LAST, v.montant DESC
                 LIMIT 120';

        return $this->render('affectation/file.html.twig', [
            'lignes' => $this->cnx->fetchAllAssociative($sql, $params),
            'recap' => $this->recap(),
            'q' => $q, 'decision' => $decision, 'cohorte' => $cohorte,
            'publie' => self::RESULTAT_PUBLIE,
            'decisions' => Decision::toutes(),
        ]);
    }

    /** Le pilotage : volumes, ecarts, etablissements, descente jusqu'au virement. */
    #[Route('/pilotage', name: 'app_affectation_pilotage', methods: ['GET'])]
    public function pilotage(): Response
    {
        return $this->render('affectation/pilotage.html.twig', [
            'recap' => $this->recap(),
            'parCohorte' => $this->cnx->fetchAllAssociative(
                "SELECT v.cohorte,
                        count(*) AS n,
                        count(*) FILTER (WHERE d.decision = 'automatique') AS auto,
                        count(*) FILTER (WHERE d.decision = 'validation') AS validation,
                        count(*) FILTER (WHERE d.decision IN ('exception','refus')) AS humain,
                        coalesce(sum(v.montant), 0) AS montant,
                        coalesce(sum(v.montant) FILTER (WHERE d.decision = 'automatique'), 0) AS montant_auto,
                        round(avg(d.duree_ms)::numeric, 1) AS duree
                   FROM affectation.decision d JOIN affectation.virement v ON v.id = d.virement_id
                  GROUP BY 1 ORDER BY 1"),
            'parSociete' => $this->cnx->fetchAllAssociative(
                "SELECT coalesce(v.societe_id, '—') AS societe,
                        count(*) AS n,
                        count(*) FILTER (WHERE d.decision = 'automatique') AS auto,
                        count(*) FILTER (WHERE d.decision IN ('exception','refus')) AS humain,
                        coalesce(sum(v.montant), 0) AS montant
                   FROM affectation.decision d JOIN affectation.virement v ON v.id = d.virement_id
                  GROUP BY 1 ORDER BY humain DESC, montant DESC LIMIT 12"),
            'aTraiter' => $this->cnx->fetchAllAssociative(
                "SELECT v.id, v.montant, v.libelle, d.decision, d.score, c.nom AS client_nom
                   FROM affectation.decision d
                   JOIN affectation.virement v ON v.id = d.virement_id
                   LEFT JOIN affectation.client c ON c.id = d.client_propose
                  WHERE d.decision IN ('exception','refus')
                  ORDER BY v.montant DESC LIMIT 12"),
        ]);
    }

    /** La methode : regles, seuils, performance mesuree, brut contre enrichi. */
    #[Route('/methode', name: 'app_affectation_methode', methods: ['GET'])]
    public function methode(): Response
    {
        return $this->render('affectation/methode.html.twig', [
            'publie' => self::RESULTAT_PUBLIE,
            'signaux' => Bareme::SIGNAUX,
            // Ce que chaque signal prouve : le lecteur doit pouvoir le lire sans
            // ouvrir le code.
            'compte' => Bareme::SIGNAUX_DE_COMPTE,
            'identite' => Bareme::SIGNAUX_D_IDENTITE,
            'contradictoires' => Bareme::SIGNAUX_CONTRADICTOIRES,
            'seuils' => [
                'automatique' => Bareme::SEUIL_AUTOMATIQUE,
                'validation' => Bareme::SEUIL_VALIDATION,
                'rejet' => Bareme::SEUIL_REJET,
                'ecart' => Bareme::ECART_MINIMAL_CANDIDATS,
                'marge_auto' => Bareme::MARGE_AUTOMATIQUE,
                'marge_partage' => Bareme::MARGE_IBAN_PARTAGE,
                'combinaison' => Bareme::COMBINAISON_MAX_FACTURES,
            ],
            'frequence' => $this->cnx->fetchAllAssociative(
                'SELECT p.signal, count(*) AS n, min(p.poids) AS poids, min(p.origine) AS origine
                   FROM affectation.preuve p GROUP BY 1 ORDER BY 2 DESC'),
            'cohortes' => $this->cnx->fetchAllAssociative(
                "SELECT v.cohorte,
                        count(*) AS n,
                        count(*) FILTER (WHERE t.decision_attendue = 'auto') AS automatisables,
                        count(*) FILTER (WHERE d.client_propose IS NOT NULL) AS identifies,
                        count(*) FILTER (WHERE d.decision = 'automatique') AS auto,
                        count(*) FILTER (WHERE d.decision = 'validation') AS validation,
                        count(*) FILTER (WHERE d.decision IN ('exception','refus')) AS humain,
                        count(*) FILTER (WHERE d.decision = 'automatique'
                                           AND t.decision_attendue = 'auto'
                                           AND d.client_propose = t.vrai_client) AS auto_justes,
                        count(*) FILTER (WHERE d.decision = 'automatique'
                                           AND (t.decision_attendue <> 'auto'
                                                OR t.vrai_client IS NULL
                                                OR d.client_propose <> t.vrai_client)) AS faux_positifs,
                        coalesce(sum(v.montant) FILTER (WHERE d.decision = 'automatique'
                                           AND t.decision_attendue = 'auto'
                                           AND d.client_propose = t.vrai_client), 0) AS montant_juste,
                        coalesce(sum(v.montant) FILTER (WHERE d.decision = 'automatique'
                                           AND (t.decision_attendue <> 'auto'
                                                OR t.vrai_client IS NULL
                                                OR d.client_propose <> t.vrai_client)), 0) AS montant_faux,
                        coalesce(sum(v.montant) FILTER (WHERE d.decision <> 'automatique'), 0) AS montant_humain,
                        round(avg(d.duree_ms)::numeric, 1) AS duree
                   FROM affectation.decision d
                   JOIN affectation.virement v ON v.id = d.virement_id
                   JOIN affectation_verite.virement t ON t.objet_id = d.virement_id
                  GROUP BY 1 ORDER BY 1"),
            'matrice' => $this->cnx->fetchAllAssociative(
                "SELECT t.decision_attendue AS attendu, d.decision AS obtenu, count(*) AS n
                   FROM affectation.decision d
                   JOIN affectation.virement v ON v.id = d.virement_id
                   JOIN affectation_verite.virement t ON t.objet_id = d.virement_id
                  WHERE v.cohorte = 'BLIND_TEST_4'
                  GROUP BY 1, 2 ORDER BY 3 DESC"),
            // Ce que le compte bancaire partage represente dans le referentiel.
            'partage' => $this->cnx->fetchAssociative(
                'SELECT count(*) FILTER (WHERE n > 1) AS groupes,
                        coalesce(sum(n) FILTER (WHERE n > 1), 0) AS comptes
                   FROM (SELECT i.empreinte, count(*) n
                           FROM affectation.client c
                           JOIN affectation.iban i ON i.id = c.iban_id
                          GROUP BY 1) x'),
        ]);
    }

    /**
     * La fiche d'un virement : brut, enrichi, candidats, decision.
     *
     * L'analyse est REJOUEE a l'ouverture, et chronometree sur l'appareil du
     * visiteur. Aucune duree n'est stockee pour l'affichage.
     */
    #[Route('/{id}', name: 'app_affectation_virement', methods: ['GET'], requirements: ['id' => 'TX-\d+'])]
    public function virement(string $id, Request $request): Response
    {
        $mode = MoteurAffectation::MODE_BRUT === $request->query->get('mode')
            ? MoteurAffectation::MODE_BRUT : MoteurAffectation::MODE_ENRICHI;

        $analyse = $this->moteur->analyser($id, $mode);

        // La comparaison brut / enrichi, sur ce virement precis.
        $comparaison = null;
        if ($request->query->getBoolean('comparer')) {
            $autre = $this->moteur->analyser($id,
                MoteurAffectation::MODE_ENRICHI === $mode ? MoteurAffectation::MODE_BRUT : MoteurAffectation::MODE_ENRICHI);
            $comparaison = $autre;
        }

        return $this->render('affectation/virement.html.twig', [
            'a' => $analyse,
            'comparaison' => $comparaison,
            'mode' => $mode,
            'seuils' => ['automatique' => Bareme::SEUIL_AUTOMATIQUE, 'validation' => Bareme::SEUIL_VALIDATION,
                'rejet' => Bareme::SEUIL_REJET, 'ecart' => Bareme::ECART_MINIMAL_CANDIDATS,
                'marge_auto' => Bareme::MARGE_AUTOMATIQUE, 'marge_partage' => Bareme::MARGE_IBAN_PARTAGE],
            'etablissements' => $this->cnx->fetchAllKeyValue('SELECT id, nom FROM affectation.etablissement'),
            // La suite du parcours : une fois le payeur identifie, quelles
            // ecritures son reglement solde-t-il ? C'est l'outil 5, et on y
            // arrive sur le MEME compte, sans repasser par un menu.
            'lotLettrage' => null !== $analyse['retenu']
                ? $this->cnx->fetchOne(
                    'SELECT id FROM lettrage.lot WHERE client_id = ? ORDER BY montant DESC LIMIT 1',
                    [$analyse['retenu']['client']['id']]) ?: null
                : null,
            'clientLettrage' => null !== $analyse['retenu'] ? $analyse['retenu']['client']['id'] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function recap(): array
    {
        $r = $this->cnx->fetchAssociative(
            "SELECT count(*) AS n,
                    count(*) FILTER (WHERE d.client_propose IS NOT NULL) AS identifies,
                    count(*) FILTER (WHERE d.decision = 'automatique') AS auto,
                    count(*) FILTER (WHERE d.decision = 'validation') AS validation,
                    count(*) FILTER (WHERE d.decision = 'exception') AS exception,
                    count(*) FILTER (WHERE d.decision = 'refus') AS refus,
                    coalesce(sum(v.montant), 0) AS montant,
                    coalesce(sum(v.montant) FILTER (WHERE d.decision = 'automatique'), 0) AS montant_auto,
                    coalesce(sum(v.montant) FILTER (WHERE d.decision IN ('exception','refus')), 0) AS montant_humain,
                    round(avg(d.duree_ms)::numeric, 1) AS duree_moyenne
               FROM affectation.decision d JOIN affectation.virement v ON v.id = d.virement_id");

        if (!\is_array($r)) {
            return [];
        }
        $r['total_virements'] = (int) $this->cnx->fetchOne('SELECT count(*) FROM affectation.virement');
        $r['factures_non_affectees'] = (int) $this->cnx->fetchOne('SELECT count(*) FROM affectation.facture WHERE NOT affectee');

        return $r;
    }
}
