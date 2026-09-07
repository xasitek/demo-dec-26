<?php

declare(strict_types=1);

namespace App\Pilotage\Controller;

use App\GrandsComptes\Moteur\Pont;
use App\Pilotage\Moteur\Cockpit;
use App\Pilotage\Moteur\Conventions;
use App\Pilotage\Moteur\DrillDown;
use App\Pilotage\Moteur\Perimetre;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le cockpit de pilotage : ce qu'il reste reellement a piloter.
 *
 *   /cockpit               les grandeurs, par profil
 *   /cockpit/multi-sites   la matrice des etablissements
 *   /cockpit/avant-apres   l'effet reel des outils 4 et 5
 *   /cockpit/risque        la lecture du credit manager
 *   /cockpit/methode       les conventions, les retraitements, les limites
 *   /cockpit/detail        la descente jusqu'a l'ecriture
 *   /cockpit/facture/{id}  pourquoi cette creance reste ouverte
 *
 * Chaque duree affichee est chronometree sur l'appareil du visiteur.
 */
#[Route('/cockpit')]
#[IsGranted('MODULE_COCKPIT')]
// Le consolide du groupe est reserve aux postes qui pilotent : manager,
// directeur, auditeur. Un comptable n'y a pas affaire, et sa vue de travail
// est ailleurs (/mes-creances). On le REFUSE ici au lieu de simplement cacher
// le lien : un ecran masque dont l'adresse repond reste un ecran accessible.
#[IsGranted(new Expression("is_granted('ROLE_MANAGER') or is_granted('ROLE_DIRECTEUR')"))]
final class CockpitController extends AbstractController
{
    public function __construct(
        private readonly Connection $cnx,
        private readonly Cockpit $cockpit,
        private readonly DrillDown $descente,
        private readonly Perimetre $perimetre,
        private readonly Pont $pont,
    ) {
    }

    #[Route('', name: 'app_cockpit', methods: ['GET'])]
    public function cockpit(Request $requete): Response
    {
        $t0 = microtime(true);
        $filtres = $this->perimetre->appliquer($this->filtres($requete));

        $encours = $this->cockpit->encours($filtres);
        $dso = $this->cockpit->dso($filtres);
        $bfr = $this->cockpit->bfr($filtres);
        $anciennete = $this->cockpit->anciennete($filtres);

        return $this->render('pilotage/cockpit.html.twig', [
            'encours' => $encours,
            'dso' => $dso,
            'bfr' => $bfr,
            'anciennete' => $anciennete,
            'passerelle' => $this->cockpit->passerelle($filtres),
            'mention_perimetres' => Conventions::MENTION_PERIMETRES,
            'causes' => $this->cockpit->causes(),
            // Le pont vers l'outil 7 : quelle part des creances bloquees par une
            // piece est reellement suivie par un dossier documentaire.
            'pont' => $this->pont->sousTotal($filtres),
            'qualites_pont' => Pont::QUALITES,
            'natures' => Conventions::NATURES,
            'libelles_causes' => Conventions::causes(),
            'retraitements' => Conventions::retraitements(),
            'filtres' => $filtres,
            'perimetre' => $this->perimetre->etablissement(),
            'perimetre_par_defaut' => $this->perimetre->resoluParDefaut(),
            'societes' => $this->cnx->fetchFirstColumn(
                'SELECT DISTINCT societe_id FROM affectation.etablissement ORDER BY 1'),
            'etablissements' => $this->cnx->fetchAllKeyValue(
                'SELECT id, nom FROM affectation.etablissement ORDER BY id'),
            'cycles' => $this->cnx->fetchAllAssociative('SELECT * FROM pilotage.cycle ORDER BY code'),
            'arrete' => Conventions::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    #[Route('/multi-sites', name: 'app_cockpit_multisites', methods: ['GET'])]
    public function multiSites(): Response
    {
        $t0 = microtime(true);
        $lignes = $this->cockpit->multiSites();

        // Le quartile le plus degrade, mesure et non decrete : on classe les
        // etablissements par delai de recouvrement et on retient le quart le
        // plus lent. Aucune couleur n'est posee sans cette definition.
        $dso = array_values(array_filter(array_map(
            static fn (array $l): float => (float) $l['dso'], $lignes), static fn (float $x): bool => $x > 0));
        sort($dso);
        $seuilQuartile = [] !== $dso ? $dso[(int) floor(\count($dso) * 0.75)] ?? end($dso) : 0.0;
        $encoursTotal = array_sum(array_map(static fn (array $l): float => (float) $l['encours'], $lignes));

        return $this->render('pilotage/multi_sites.html.twig', [
            'lignes' => $lignes,
            'seuil_quartile' => $seuilQuartile,
            'encours_total' => $encoursTotal,
            'perimetre' => $this->perimetre->etablissement(),
            'arrete' => Conventions::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    #[Route('/avant-apres', name: 'app_cockpit_avant_apres', methods: ['GET'])]
    public function avantApres(Request $requete): Response
    {
        $t0 = microtime(true);
        $applique = '1' === $requete->query->get('applique', '0');

        return $this->render('pilotage/avant_apres.html.twig', [
            'applique' => $applique,
            'avant' => $this->cockpit->avant(),
            'apres' => $this->cockpit->apres(),
            'encours' => $this->cockpit->encours($this->perimetre->appliquer([])),
            'passerelle' => $this->cockpit->passerelle($this->perimetre->appliquer([])),
            'mention_perimetres' => Conventions::MENTION_PERIMETRES,
            'natures' => Conventions::NATURES,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    #[Route('/risque', name: 'app_cockpit_risque', methods: ['GET'])]
    public function risque(): Response
    {
        $t0 = microtime(true);
        $filtres = $this->perimetre->appliquer([]);

        return $this->render('pilotage/risque.html.twig', [
            'payeurs' => $this->cockpit->topPayeurs(20),
            'anciennete' => $this->cockpit->anciennete($filtres),
            'encours' => $this->cockpit->encours($filtres),
            'causes' => $this->cockpit->causes(),
            'libelles_causes' => Conventions::causes(),
            'passerelle' => $this->cockpit->passerelle($filtres),
            'mention_perimetres' => Conventions::MENTION_PERIMETRES,
            'concentration' => $this->cnx->fetchAllAssociative(
                'SELECT cl.type, coalesce(sum(c.montant), 0) AS montant, count(*) AS factures,
                        count(DISTINCT c.client_id) AS comptes
                   FROM pilotage.cause_ouverture c
                   LEFT JOIN affectation.client cl ON cl.id = c.client_id
                  WHERE NOT c.soldee_par_suite
                  GROUP BY 1 ORDER BY 2 DESC'),
            'arrete' => Conventions::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    #[Route('/methode', name: 'app_cockpit_methode', methods: ['GET'])]
    public function methode(): Response
    {
        $t0 = microtime(true);

        return $this->render('pilotage/methode.html.twig', [
            'dso' => Conventions::dso(),
            'bfr' => Conventions::bfr(),
            'retraitements' => Conventions::retraitements(),
            'natures' => Conventions::NATURES,
            'tranches' => Conventions::TRANCHES,
            'causes' => Conventions::causes(),
            'chiffres_dso' => $this->cockpit->dso(),
            'chiffres_bfr' => $this->cockpit->bfr(),
            'chiffres_encours' => $this->cockpit->encours(),
            'passerelle' => $this->cockpit->passerelle(),
            'mention_perimetres' => Conventions::MENTION_PERIMETRES,
            'comptes_creance' => Conventions::COMPTES_CREANCE,
            'comptes_hors' => Conventions::COMPTES_HORS_CREANCE,
            'effets' => $this->cockpit->apres(),
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    #[Route('/detail', name: 'app_cockpit_detail', methods: ['GET'])]
    public function detail(Request $requete): Response
    {
        $niveau = (string) $requete->query->get('niveau', 'societe');
        if (!isset(DrillDown::NIVEAUX[$niveau])) {
            $niveau = 'societe';
        }
        $nature = (string) $requete->query->get('nature', 'encours');
        $filtres = $this->perimetre->appliquer($this->filtres($requete));

        $r = $this->descente->lignes($niveau, $filtres, $nature);

        return $this->render('pilotage/detail.html.twig', [
            'niveau' => $niveau,
            'niveaux' => DrillDown::NIVEAUX,
            'nature' => $nature,
            'filtres' => $filtres,
            'chemin' => $this->descente->chemin($filtres),
            'lignes' => $r['lignes'],
            'total' => $r['total'],
            'ms' => $r['ms'],
            'etablissements' => $this->cnx->fetchAllKeyValue(
                'SELECT id, nom FROM affectation.etablissement'),
        ]);
    }

    #[Route('/facture/{id}', name: 'app_cockpit_facture', methods: ['GET'], requirements: ['id' => 'FAC-\d+'])]
    public function facture(string $id): Response
    {
        $t0 = microtime(true);
        $cause = $this->cnx->fetchAssociative(
            'SELECT c.*, f.numero, f.type, f.date_facture AS emise_le, cl.nom AS client_nom, cl.type AS client_type
               FROM pilotage.cause_ouverture c
               LEFT JOIN affectation.facture f ON f.id = c.facture_id
               LEFT JOIN affectation.client cl ON cl.id = c.client_id
              WHERE c.facture_id = ?', [$id]);
        if (false === $cause) {
            throw $this->createNotFoundException("Créance inconnue : {$id}");
        }

        return $this->render('pilotage/facture.html.twig', [
            'c' => $cause,
            'causes' => Conventions::causes(),
            'ecritures' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM lettrage.ecriture WHERE facture_id = ? ORDER BY sens DESC, date_ecriture', [$id]),
            'jours_retard' => (int) $this->cnx->fetchOne(
                "SELECT DATE '".Conventions::ARRETE."' - ?::date", [$cause['echeance']]),
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function filtres(Request $requete): array
    {
        $f = [];
        foreach (['societe_id', 'etablissement_id', 'client_id', 'facture_id', 'cycle', 'cause'] as $champ) {
            $v = trim((string) $requete->query->get($champ, ''));
            if ('' !== $v) {
                $f[$champ] = $v;
            }
        }

        return $f;
    }
}
