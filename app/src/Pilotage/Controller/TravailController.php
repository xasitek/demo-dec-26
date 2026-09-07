<?php

declare(strict_types=1);

namespace App\Pilotage\Controller;

use App\Pilotage\Moteur\Conventions;
use App\Pilotage\Moteur\FilesDeTravail;
use App\Pilotage\Moteur\Gestes;
use App\Pilotage\Moteur\Perimetre;
use App\Pilotage\Moteur\Recherche;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes créances à traiter » : le poste de travail du comptable.
 *
 *   /mes-creances                     les dix files, la recherche, la file ouverte
 *   /mes-creances/dossier/{type}/{id} un dossier, ses ecritures, son historique
 *   /mes-creances/geste               POST : inscrit une note ou une consultation
 *   /mes-creances/journal             ce que j'ai inscrit, et ou j'ai envoye du travail
 *
 * Ce n'est pas un tableau de bord, et ce n'est pas non plus un moteur. L'outil
 * 6 ORCHESTRE : il dit quoi traiter et dans quel ordre, puis il envoie au
 * module qui execute -- l'outil 4 pour affecter, l'outil 5 pour lettrer. Il
 * n'inscrit jamais une decision metier qu'il n'a pas realisee.
 */
#[Route('/mes-creances')]
#[IsGranted('MODULE_COCKPIT')]
final class TravailController extends AbstractController
{
    public function __construct(
        private readonly Connection $cnx,
        private readonly FilesDeTravail $files,
        private readonly Gestes $gestes,
        private readonly Recherche $recherche,
        private readonly Perimetre $perimetre,
    ) {
    }

    #[Route('', name: 'app_travail', methods: ['GET'])]
    public function mesCreances(Request $requete): Response
    {
        $t0 = microtime(true);
        $filtres = $this->perimetre->appliquer($this->filtres($requete));

        $file = (string) $requete->query->get('file', 'virements_non_affectes');
        if (null === $this->files->definition($file)) {
            $file = 'virements_non_affectes';
        }

        // ---- La recherche. Un terme qui EST un identifiant ne merite pas une
        // page de resultats : il merite le dossier. C'est ce qui permet de
        // taper un numero de facture devant le jury et d'ouvrir la creance.
        $q = trim((string) $requete->query->get('q', ''));
        if ('' !== $q) {
            $saut = $this->recherche->saut($q);
            if (null !== $saut) {
                return $this->redirectToRoute('app_travail_dossier', [
                    'type' => $saut['type'], 'id' => $saut['id'], 'file' => $file,
                ]);
            }
        }
        $resultats = '' !== $q ? $this->recherche->chercher($q, $filtres) : null;

        // ---- La pagination : une file se parcourt jusqu'au bout, sinon ce
        // n'est pas une file de travail mais un echantillon.
        $sommaire = $this->files->sommaire($filtres);
        $volume = 0;
        foreach ($sommaire as $f) {
            if ($f['code'] === $file) {
                $volume = $f['lignes'];
            }
        }
        $parPage = FilesDeTravail::PAR_PAGE;
        $pages = max(1, (int) ceil($volume / $parPage));
        $page = max(1, min((int) $requete->query->get('page', 1), $pages));

        $definition = $this->files->definition($file);
        \assert(null !== $definition);
        $lignes = $this->files->lignes($file, $filtres, $parPage, ($page - 1) * $parPage);

        return $this->render('pilotage/travail/mes_creances.html.twig', [
            'sommaire' => $sommaire,
            'file' => $file,
            'definition' => $definition,
            'lignes' => $this->avecActions($lignes, $definition['objet'], $file),
            'page' => $page,
            'pages' => $pages,
            'volume' => $volume,
            'par_page' => $parPage,
            'q' => $q,
            'resultats' => $resultats,
            'champs_recherche' => Recherche::CHAMPS,
            'catalogue' => Gestes::CATALOGUE,
            'niveaux' => Gestes::NIVEAUX,
            'causes' => Conventions::causes(),
            'journal' => $this->gestes->journal(),
            'filtres' => $filtres,
            'perimetre' => $this->perimetre->etablissement(),
            'etablissements' => $this->cnx->fetchAllKeyValue(
                'SELECT id, nom FROM affectation.etablissement ORDER BY id'),
            'arrete' => Conventions::ARRETE,
            'mention_perimetres' => Conventions::MENTION_PERIMETRES,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /**
     * Un dossier ouvert : ce qu'il est, pourquoi il est la, ce qu'on peut en faire.
     *
     * Les cinq natures d'objet aboutissent au meme ecran, parce que le travail
     * du comptable est le meme : comprendre, puis agir la ou l'action se fait.
     */
    #[Route('/dossier/{type}/{id}', name: 'app_travail_dossier', methods: ['GET'],
        requirements: ['type' => 'facture|virement|lot|ecriture|compte'])]
    public function dossier(string $type, string $id, Request $requete): Response
    {
        $t0 = microtime(true);
        $dossier = $this->dossierDe($type, $id);
        if ([] === $dossier) {
            throw $this->createNotFoundException('Dossier inconnu : '.$type.' '.$id);
        }

        // Le perimetre PRIME : un directeur de concession ne doit pas ouvrir
        // un dossier d'un autre site en devinant son identifiant.
        $mien = $this->perimetre->etablissement();
        if (null !== $mien && isset($dossier['etablissement_id'])
            && $mien !== $dossier['etablissement_id']) {
            throw $this->createAccessDeniedException('Dossier hors de votre périmètre.');
        }

        $clientId = isset($dossier['client_id']) ? (string) $dossier['client_id'] : null;
        $file = (string) $requete->query->get('file', '');

        return $this->render('pilotage/travail/dossier.html.twig', [
            'type' => $type,
            'id' => $id,
            'dossier' => $dossier,
            'ecritures' => null !== $clientId ? $this->ecrituresDuCompte($clientId) : [],
            'creances' => null !== $clientId ? $this->creancesDuCompte($clientId) : [],
            'situation' => null !== $clientId ? $this->situationDuCompte($clientId) : [],
            'historique' => $this->gestes->historique($type, $id),
            'catalogue' => Gestes::CATALOGUE,
            'niveaux' => Gestes::NIVEAUX,
            'actions' => $this->gestes->pour($type, $dossier, $file),
            'file' => $file,
            'causes' => Conventions::causes(),
            'arrete' => Conventions::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /**
     * Inscrire une note, ou tracer une consultation. POST uniquement.
     *
     * L'outil 6 n'ecrit que ce qu'il fait lui-meme. Affecter et lettrer sont
     * des LIENS vers les outils 4 et 5, pas des formulaires : la decision se
     * prend et se journalise dans le module qui l'execute.
     */
    #[Route('/geste', name: 'app_travail_geste', methods: ['POST'])]
    public function geste(Request $requete): RedirectResponse
    {
        $type = (string) $requete->request->get('type', '');
        $objetType = (string) $requete->request->get('objet_type', '');
        $objetId = (string) $requete->request->get('objet_id', '');

        if (!$this->gestes->ecritLuiMeme($type)) {
            $this->addFlash('erreur',
                "L'outil 6 n'inscrit que des notes et des consultations. Une décision métier "
                ."est prise et journalisée par le module qui l'exécute : rien n'a été écrit.");

            return $this->redirectToRoute('app_travail');
        }

        $dossier = $this->dossierDe($objetType, $objetId);
        if ([] === $dossier) {
            $this->addFlash('erreur', "Objet introuvable : rien n'a été écrit.");

            return $this->redirectToRoute('app_travail');
        }

        $mien = $this->perimetre->etablissement();
        if (null !== $mien && isset($dossier['etablissement_id'])
            && $mien !== $dossier['etablissement_id']) {
            throw $this->createAccessDeniedException('Dossier hors de votre périmètre.');
        }

        $module = (string) $requete->request->get('module', '');

        $this->gestes->poser(
            $type,
            $objetType,
            $objetId,
            isset($dossier['client_id']) ? (string) $dossier['client_id'] : null,
            isset($dossier['etablissement_id']) ? (string) $dossier['etablissement_id'] : null,
            isset($dossier['montant']) ? (float) $dossier['montant'] : null,
            (string) $requete->request->get('note', ''),
            (string) $requete->request->get('file', '') ?: null,
            '' !== $module ? $module : null,
        );

        $this->addFlash('fait', 'note' === $type
            ? 'Note consignée sur '.$objetId.', datée et signée.'
            : 'Consultation tracée sur '.$objetId.'.');

        $retour = (string) $requete->request->get('retour', '');
        if (str_starts_with($retour, '/mes-creances')) {
            return $this->redirect($retour);
        }

        return $this->redirectToRoute('app_travail_dossier', ['type' => $objetType, 'id' => $objetId]);
    }

    /** Ce que j'ai inscrit ici, et ou j'ai envoye du travail. */
    #[Route('/journal', name: 'app_travail_journal', methods: ['GET'])]
    public function journal(): Response
    {
        $t0 = microtime(true);

        return $this->render('pilotage/travail/journal.html.twig', [
            'journal' => $this->gestes->journal(),
            'catalogue' => Gestes::CATALOGUE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    // ------------------------------------------------------------------ interne

    /**
     * Attache a chaque ligne les gestes ouverts, avec leur adresse reelle.
     *
     * Les files n'ont pas les memes colonnes : le compte propose d'un virement
     * s'appelle `client_propose`, celui d'une creance `client_id`. On ramene
     * donc chaque ligne a un socle commun avant de demander ses gestes --
     * sinon le lien « voir le compte » pointerait dans le vide une fois sur
     * deux, et personne ne le verrait avant le jury.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array<string, mixed>>
     */
    private function avecActions(array $lignes, string $objetDeLaFile, string $file): array
    {
        foreach ($lignes as $i => $l) {
            $type = 'exception' === $objetDeLaFile
                ? (string) ($l['source'] ?? 'virement')
                : $objetDeLaFile;

            $client = (string) ($l['client_id'] ?? $l['client_propose'] ?? '');
            $id = (string) ($l['id'] ?? $l['facture_id'] ?? $client);
            if ('compte' === $type) {
                $id = $client;
            }

            $socle = [
                'id' => $id,
                'client_id' => $client,
                'cause' => (string) ($l['cause'] ?? ''),
                'lot_demo' => (string) ($l['lot_demo'] ?? ''),
            ];
            $lignes[$i]['actions'] = $this->gestes->pour($type, $socle, $file);
            $lignes[$i]['_type'] = $type;
            $lignes[$i]['_id'] = $id;
            $lignes[$i]['_client'] = $client;
        }

        return $lignes;
    }

    /**
     * L'objet, quelle que soit sa nature, ramene a un socle commun.
     *
     * @return array<string, mixed>
     */
    private function dossierDe(string $type, string $id): array
    {
        $sql = match ($type) {
            'facture' => "SELECT co.facture_id AS id, 'facture' AS nature, co.client_id,
                                 co.societe_id, co.etablissement_id, co.montant,
                                 co.date_facture, co.echeance, co.cause, co.suite,
                                 co.soldee_par_suite, co.soldee_par,
                                 (:arrete::date - co.echeance) AS retard,
                                 f.numero, f.type AS type_facture, f.statut,
                                 c.nom AS client_nom, c.type AS client_type, c.code_balance,
                                 e.nom AS etablissement_nom,
                                 v.immatriculation, v.serie AS vin, v.modele,
                                 (SELECT x.lot_demo FROM lettrage.ecriture x
                                   WHERE x.facture_id = co.facture_id AND x.lot_demo IS NOT NULL
                                   LIMIT 1) AS lot_demo
                            FROM pilotage.cause_ouverture co
                            LEFT JOIN affectation.facture f ON f.id = co.facture_id
                            LEFT JOIN affectation.client c ON c.id = co.client_id
                            LEFT JOIN affectation.etablissement e ON e.id = co.etablissement_id
                            LEFT JOIN affectation.vehicule v ON v.id = f.vehicule_id
                           WHERE co.facture_id = :id",
            'virement' => "SELECT v.id, 'virement' AS nature, d.client_propose AS client_id,
                                  v.societe_id, NULL AS etablissement_id, v.montant,
                                  v.date_operation AS date_facture, v.date_operation AS echeance,
                                  d.decision AS cause, 'outil-4' AS suite,
                                  v.libelle, v.nom_donneur_ordre, v.reference_bout_en_bout,
                                  d.score, d.score_suivant, d.nb_candidats, d.factures,
                                  c.nom AS client_nom, c.type AS client_type
                             FROM affectation.virement v
                             LEFT JOIN affectation.decision d
                                    ON d.virement_id = v.id AND d.mode = 'ENRICHED'
                             LEFT JOIN affectation.client c ON c.id = d.client_propose
                            WHERE v.id = :id",
            'lot' => "SELECT l.id, 'lot' AS nature, l.client_id, l.societe_id, l.etablissement_id,
                             l.montant, NULL::date AS date_facture, NULL::date AS echeance,
                             ld.verdict AS cause, 'outil-5' AS suite,
                             ld.methode, ld.arret, ld.motif, ld.nb_indices, ld.nb_indices_forts,
                             ld.solde, l.nb_ecritures, c.nom AS client_nom, c.type AS client_type
                        FROM lettrage.lot l
                        LEFT JOIN lettrage.decision ld ON ld.lot_id = l.id
                        LEFT JOIN affectation.client c ON c.id = l.client_id
                       WHERE l.id = :id",
            'ecriture' => "SELECT e.id, 'ecriture' AS nature, e.client_id, e.societe_id,
                                  e.etablissement_id, e.montant,
                                  e.date_ecriture AS date_facture, e.date_ecriture AS echeance,
                                  e.sens AS cause, 'outil-5' AS suite,
                                  e.compte, e.journal, e.reference_piece, e.immatriculation,
                                  e.vin8, e.lettrage, e.lot_demo,
                                  c.nom AS client_nom, c.type AS client_type
                             FROM lettrage.ecriture e
                             LEFT JOIN affectation.client c ON c.id = e.client_id
                            WHERE e.id = :id",
            'compte' => "SELECT c.id, 'compte' AS nature, c.id AS client_id, NULL AS societe_id,
                                NULL AS etablissement_id,
                                (SELECT coalesce(sum(CASE WHEN x.sens='D' THEN x.montant
                                                          ELSE -x.montant END), 0)
                                   FROM lettrage.ecriture x
                                  WHERE x.client_id = c.id AND x.compte LIKE '411%') AS montant,
                                NULL::date AS date_facture, NULL::date AS echeance,
                                'solde' AS cause, 'outil-5' AS suite,
                                c.nom AS client_nom, c.type AS client_type, c.code_balance,
                                (SELECT x.lot_demo FROM lettrage.ecriture x
                                  WHERE x.client_id = c.id AND x.lot_demo IS NOT NULL
                                  LIMIT 1) AS lot_demo
                           FROM affectation.client c WHERE c.id = :id",
            default => null,
        };
        if (null === $sql) {
            return [];
        }

        return $this->cnx->fetchAssociative($sql, ['id' => $id, 'arrete' => Conventions::ARRETE]) ?: [];
    }

    /**
     * Les ecritures du compte client : le niveau ou une question se tranche.
     *
     * @return list<array<string, mixed>>
     */
    private function ecrituresDuCompte(string $clientId): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            "SELECT e.id, e.date_ecriture, e.compte, e.journal, e.sens, e.montant,
                    e.reference_piece, e.immatriculation, e.vin8, e.lettrage, e.lot_demo,
                    e.etablissement_id
               FROM lettrage.ecriture e
              WHERE e.client_id = ? AND e.compte LIKE '411%'
              ORDER BY e.date_ecriture DESC, e.id LIMIT 40",
            [$clientId]);

        return $lignes;
    }

    /**
     * Les creances du compte, avec leur cause.
     *
     * @return list<array<string, mixed>>
     */
    private function creancesDuCompte(string $clientId): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT co.facture_id, co.montant, co.echeance, co.cause, co.soldee_par_suite,
                    co.soldee_par, co.etablissement_id, (?::date - co.echeance) AS retard
               FROM pilotage.cause_ouverture co
              WHERE co.client_id = ? ORDER BY co.montant DESC LIMIT 25',
            [Conventions::ARRETE, $clientId]);

        return $lignes;
    }

    /**
     * La situation du compte, dans la forme d'une fiche client.
     *
     * @return array<string, mixed>
     */
    private function situationDuCompte(string $clientId): array
    {
        $ligne = $this->cnx->fetchAssociative(
            'SELECT count(*) creances,
                    coalesce(sum(co.montant), 0) total,
                    coalesce(sum(co.montant) FILTER (WHERE NOT co.soldee_par_suite), 0) ouvert,
                    coalesce(sum(co.montant) FILTER (WHERE co.soldee_par_suite), 0) solde_par_chaine,
                    coalesce(sum(co.montant) FILTER (WHERE co.echeance >= ?::date), 0) a_echoir,
                    coalesce(sum(co.montant) FILTER (WHERE co.echeance < ?::date
                                                       AND NOT co.soldee_par_suite), 0) echu,
                    min(co.echeance) plus_ancienne, max(co.echeance) plus_recente,
                    count(DISTINCT co.etablissement_id) etablissements
               FROM pilotage.cause_ouverture co WHERE co.client_id = ?',
            [Conventions::ARRETE, Conventions::ARRETE, $clientId]) ?: [];

        $solde = $this->cnx->fetchOne(
            "SELECT coalesce(sum(CASE WHEN e.sens='D' THEN e.montant ELSE -e.montant END), 0)
               FROM lettrage.ecriture e WHERE e.client_id = ? AND e.compte LIKE '411%'",
            [$clientId]);

        $ligne['solde_comptable'] = (float) $solde;

        return $ligne;
    }

    /**
     * Les filtres de l'ecran, bornes a ce qu'on sait traiter.
     *
     * @return array<string, string>
     */
    private function filtres(Request $requete): array
    {
        $out = [];
        foreach (['societe_id', 'etablissement_id', 'origine'] as $cle) {
            $valeur = (string) $requete->query->get($cle, '');
            if ('' !== $valeur) {
                $out[$cle] = $valeur;
            }
        }

        return $out;
    }
}
