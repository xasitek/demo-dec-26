<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\Action;
use App\Creances\Entity\Note;
use App\Creances\Entity\Promesse;
use App\Creances\Enum\ActionResultat;
use App\Creances\Enum\ActionType;
use App\Creances\Enum\PromesseStatut;
use App\Creances\Repository\ActionRepository;
use App\Creances\Repository\CompteStrategieRepository;
use App\Creances\Repository\CreancesRepository;
use App\Creances\Repository\DossierRepository;
use App\Creances\Repository\EmailReponseRepository;
use App\Creances\Repository\NoteRepository;
use App\Creances\Repository\PromesseRepository;
use App\Creances\Repository\ScoreIaRepository;
use App\Creances\Repository\StrategieRepository;
use App\Creances\Service\IndicateursService;
use App\Creances\Service\PriorisationService;
use App\Creances\Service\RecouvrementNotifier;
use App\Creances\Service\RelanceExpertService;
use App\Creances\Service\RelanceInterneService;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module Recouvrement (creances) : pilotage du poste client a partir du
 * mirror Progiciel. Lecture seule sur `mirror.*`, ecritures uniquement dans
 * `creances.*` (annotations, actions, dossiers).
 *
 * Cible : ROLE_COMPTABLE.
 */
#[Route('/creances')]
#[IsGranted('ROLE_COMPTABLE')]
final class CreancesController extends AbstractController
{
    private const PAR_PAGE = 200;

    public function __construct(
        private readonly CreancesRepository $creances,
        private readonly IndicateursService $indicateurs,
        private readonly NoteRepository $notes,
        private readonly ActionRepository $actions,
        private readonly PromesseRepository $promesses,
        private readonly DossierRepository $dossiers,
        private readonly StrategieRepository $strategies,
        private readonly CompteStrategieRepository $compteStrategies,
        private readonly ScoreIaRepository $scoreIa,
        private readonly EmailReponseRepository $reponses,
        private readonly PriorisationService $priorisation,
        private readonly RelanceInterneService $relanceInterne,
        private readonly RelanceExpertService $relanceExpert,
        private readonly RecouvrementNotifier $notifier,
    ) {
    }

    /**
     * Page principale : liste paginee des creances ouvertes + filtres +
     * synthese. Renvoie le partial des lignes si `?fragment=1` (scroll
     * infini, sans rerendre les filtres ni la synthese).
     */
    #[Route('', name: 'app_creances_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtres = $this->extraireFiltres($request);
        $tri = self::texte($request->query->get('tri')) ?? 'retard';
        $sens = 'asc' === $request->query->get('sens') ? 'asc' : 'desc';
        $vue = 'client' === $request->query->get('vue') ? 'client' : 'facture';

        if ('client' === $vue) {
            $total = $this->creances->compterComptes($filtres);
            $totalPages = max(1, (int) ceil($total / self::PAR_PAGE));
            $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);
            $rows = $this->creances->pageComptes($page, self::PAR_PAGE, $filtres, $tri, $sens);
            $rendu = [
                'comptes' => $rows,
                'tranches_libelle' => CreancesRepository::TRANCHES_LIBELLE,
            ];
            if ($request->query->getBoolean('fragment')) {
                return $this->render('creances/_rows_comptes.html.twig', $rendu);
            }
        } else {
            $total = $this->creances->compterCreances($filtres);
            $totalPages = max(1, (int) ceil($total / self::PAR_PAGE));
            $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);
            $rows = $this->creances->pageCreances($page, self::PAR_PAGE, $filtres, $tri, $sens);
            $rendu = [
                'lignes' => $rows,
                'tranches_libelle' => CreancesRepository::TRANCHES_LIBELLE,
            ];
            if ($request->query->getBoolean('fragment')) {
                return $this->render('creances/_rows.html.twig', $rendu);
            }
        }

        $filtresActifs = array_filter($filtres, static fn ($v): bool => null !== $v && [] !== $v);

        return $this->render('creances/index.html.twig', $rendu + [
            'vue' => $vue,
            'synthese' => $this->creances->synthese($filtres),
            'indicateurs' => $this->indicateurs->syntheseGlobale($this->filtresIndicateurs($filtres)),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filtres' => $filtresActifs,
            'tri' => $tri,
            'sens' => $sens,
            'tranches' => CreancesRepository::TRANCHES_LIBELLE,
            'etablissements' => $this->creances->listerEtablissements(),
            'marques' => $this->creances->listerMarques(),
            'pills' => $this->construirePills($filtres, $tri, $sens),
        ]);
    }

    /**
     * Tableau de bord Recouvrement : vue synthese du portefeuille avec
     * widgets KPI + balance agee + top 10 + mes actions a faire / en retard.
     */
    #[Route('/pilotage', name: 'app_creances_pilotage', methods: ['GET'])]
    public function pilotage(Request $request): Response
    {
        $filtres = [
            'etablissement' => self::tableau($request->query->all('etablissement')),
        ];
        /** @var array{etablissement?: list<string>} $filtresInd */
        $filtresInd = array_filter($filtres, static fn ($v): bool => !empty($v));

        $user = $this->getUser();
        $mesActions = [];
        $nbEnRetard = 0;
        if ($user instanceof User) {
            $mesActions = $this->actions->findAFaire($user);
            $nbEnRetard = $this->actions->countEnRetard($user);
        }

        return $this->render('creances/pilotage.html.twig', [
            'indicateurs' => $this->indicateurs->syntheseGlobale($filtresInd),
            'balance_agee' => $this->creances->balanceAgeeGlobale(),
            'top_clients' => $this->creances->topComptesParEncours(10),
            'mes_actions' => $mesActions,
            'mes_actions_en_retard' => $nbEnRetard,
            'top_criticite' => $this->priorisation->topCriticite(8),
            'nb_inactifs' => $this->priorisation->compterInactifs(),
            'filtres' => $filtresInd,
            'etablissements' => $this->creances->listerEtablissements(),
        ]);
    }

    /**
     * Fiche tiers : carte d'identite + indicateurs + 5 onglets (Creances,
     * Ecritures, Actions, Dossiers, Annotations). Le rendu est full-server
     * (pas de fetch ajax pour les onglets) — les volumes sont raisonnables
     * (max ~quelques centaines d'ecritures par tiers). On laisse Stimulus
     * gerer le switch d'onglets cote client sans rechargement.
     */
    #[Route('/tiers/{code}', name: 'app_creances_fiche', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function fiche(string $code): Response
    {
        return $this->render('creances/fiche.html.twig', [
            'compte_code' => $code,
            'tiers' => $this->creances->tiers($code),
            'indicateurs' => $this->indicateurs->syntheseCompte($code),
            'historique' => $this->indicateurs->historiqueCompte($code),
            'creances_ouvertes' => $this->creances->creancesOuvertesDuTiers($code),
            'ecritures' => $this->creances->ecrituresDuTiers($code),
            'notes' => $this->notes->findByCompte($code),
            'actions' => $this->actions->findByCompte($code),
            'promesses' => $this->promesses->findByCompte($code),
            'dossiers' => $this->dossiers->findByCompte($code),
            'compte_strategie' => $this->compteStrategies->findByCompte($code),
            'strategies_disponibles' => $this->strategies->findActives(),
            'score_ia' => $this->scoreIa->findByCompte($code),
            'reponses' => $this->reponses->findByCompte($code),
            'score_criticite' => $this->priorisation->scoreCompte($code),
            'derniere_activite' => $this->priorisation->derniereActivite($code),
            'intensite_relance' => $this->priorisation->intensiteRelance($code),
            'destinataires_internes' => $this->relanceInterne->destinatairesConnus($code),
        ]);
    }

    /**
     * Renvoie le HTML du detail d'une ecriture (pour le slide-in panneau).
     * Appele en AJAX par le Stimulus detail-panel.
     */
    #[Route('/ecriture/{numero}/detail', name: 'app_creances_ecriture_detail', methods: ['GET'], requirements: ['numero' => '[^/]+'])]
    public function ecritureDetail(string $numero): Response
    {
        $ecriture = $this->creances->ecriture($numero);
        if (null === $ecriture) {
            throw new NotFoundHttpException(sprintf('Ecriture %s introuvable.', $numero));
        }

        $compteCode = '';
        $donnees = $this->decoderDonnees($ecriture['donnees'] ?? null);
        if (isset($donnees['compte']) && is_string($donnees['compte'])) {
            $compteCode = $donnees['compte'];
        }

        return $this->render('creances/_ecriture_detail.html.twig', [
            'ecriture' => $ecriture,
            'donnees' => $donnees,
            'numero' => $numero,
            'notes' => $this->notes->findByEcriture($numero),
            'promesse_active' => $this->promesses->findActiveByEcriture($numero),
            'compte_code' => $compteCode,
        ]);
    }

    /**
     * Export CSV stream (memoire constante) respectant les filtres et le tri
     * courants.
     */
    #[Route('/export.csv', name: 'app_creances_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $filtres = $this->extraireFiltres($request);
        $tri = self::texte($request->query->get('tri')) ?? 'retard';
        $sens = 'asc' === $request->query->get('sens') ? 'asc' : 'desc';

        $response = new StreamedResponse(function () use ($filtres, $tri, $sens): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            // BOM UTF-8 pour qu'Excel reconnaisse les accents.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Compte', 'Nom', 'Prenom', 'Raison sociale', 'Email', 'Telephone',
                'Piece', 'Reference facture', 'Date piece', 'Date echeance',
                'Etablissement', 'Marque', 'Immatriculation',
                'Montant solde', 'Tranche', 'Libelle',
            ], ';');

            foreach ($this->creances->iterer($filtres, $tri, $sens) as $row) {
                fputcsv($out, [
                    (string) ($row['compte'] ?? ''),
                    (string) ($row['nom'] ?? ''),
                    (string) ($row['prenom'] ?? ''),
                    (string) ($row['raison_sociale'] ?? ''),
                    (string) ($row['email'] ?? ''),
                    (string) ($row['telephone'] ?? ''),
                    (string) ($row['numpiece'] ?? ''),
                    (string) ($row['reference_facture'] ?? ''),
                    (string) ($row['date_piece'] ?? ''),
                    (string) ($row['date_echeance'] ?? ''),
                    (string) ($row['codeetab'] ?? ''),
                    (string) ($row['marque'] ?? ''),
                    (string) ($row['numimmat'] ?? ''),
                    (string) ($row['montant_solde'] ?? '0'),
                    (string) ($row['retard'] ?? ''),
                    (string) ($row['libelle'] ?? ''),
                ], ';');
            }
            fclose($out);
        });

        $nom = sprintf('recouvrement-creances-%s.csv', (new DateTimeImmutable())->format('Y-m-d-His'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $nom));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    // ============================================================
    // Saisie : annotations, actions, promesses (POST)
    // ============================================================

    /**
     * Ajoute une annotation libre attachee au compte (ou a une ecriture si
     * `ecriture_numero` est fourni). CSRF obligatoire.
     */
    #[Route('/tiers/{code}/note', name: 'app_creances_note_compte', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function noteCompte(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_note_'.$code);
        $contenu = self::texte($request->request->get('contenu'));
        if (null === $contenu) {
            $this->addFlash('warning', 'Le contenu de la note est obligatoire.');

            return $this->redirectFiche($code, 'annotations');
        }
        $ecritureNumero = self::texte($request->request->get('ecriture_numero'));
        $auteur = $this->utilisateurCourant();

        $this->notes->save(new Note($code, $contenu, $auteur, $ecritureNumero));
        $this->notifier->notifierTiers($code, 'note_ajoutee', ['ecriture' => $ecritureNumero]);
        $this->addFlash('success', 'Annotation ajoutee.');

        return $this->redirectFiche($code, 'annotations');
    }

    /**
     * Planifie une action de relance sur un compte. CSRF obligatoire.
     */
    #[Route('/tiers/{code}/action', name: 'app_creances_action_creer', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function actionCreer(string $code, Request $request, ActionRepository $repo): Response
    {
        $this->verifierCsrf($request, 'creances_action_'.$code);

        $type = ActionType::tryFrom((string) $request->request->get('type', ''));
        $libelle = self::texte($request->request->get('libelle'));
        $echeance = self::date($request->request->get('echeance'));
        if (null === $type || null === $libelle || null === $echeance) {
            $this->addFlash('warning', 'Type, libelle et echeance sont obligatoires pour creer une action.');

            return $this->redirectFiche($code, 'actions');
        }
        $description = self::texte($request->request->get('description'));
        $ecritureNumero = self::texte($request->request->get('ecriture_numero'));
        $destinataireId = $request->request->getInt('destinataire_id');
        $destinataire = $destinataireId > 0 ? $this->resoudreUtilisateur($destinataireId) : null;
        $auteur = $this->utilisateurCourant();

        $action = new Action($code, $type, $libelle, $echeance, $auteur, $destinataire, $ecritureNumero, $description);
        $repo->save($action);

        $this->notifier->notifierTiers($code, 'action_creee', ['type' => $type->value]);
        if (null !== $destinataire && null !== $destinataire->getId()) {
            $this->notifier->notifierUtilisateur($destinataire->getId(), 'action_attribuee', ['compte' => $code]);
        }
        $this->addFlash('success', 'Action planifiee.');

        return $this->redirectFiche($code, 'actions');
    }

    /**
     * Cloture une action existante avec resultat + commentaire. CSRF
     * obligatoire.
     */
    #[Route('/action/{id}/cloturer', name: 'app_creances_action_cloturer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function actionCloturer(int $id, Request $request, ActionRepository $repo): Response
    {
        $this->verifierCsrf($request, 'creances_action_cloturer_'.$id);
        $action = $repo->find($id);
        if (null === $action) {
            throw new NotFoundHttpException('Action introuvable.');
        }
        $resultat = ActionResultat::tryFrom((string) $request->request->get('resultat', ''));
        if (null === $resultat) {
            $this->addFlash('warning', 'Choisis un resultat pour cloturer l\'action.');

            return $this->redirectFiche($action->getCompteCode(), 'actions');
        }
        $commentaire = self::texte($request->request->get('commentaire'));

        $action->cloturer($resultat, $commentaire);
        $repo->save($action);

        $this->notifier->notifierTiers($action->getCompteCode(), 'action_cloturee', ['action_id' => $id, 'resultat' => $resultat->value]);
        $this->addFlash('success', 'Action cloturee.');

        return $this->redirectFiche($action->getCompteCode(), 'actions');
    }

    /**
     * Rouvre une action precedemment cloturee (efface resultat + horodatage).
     */
    #[Route('/action/{id}/rouvrir', name: 'app_creances_action_rouvrir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function actionRouvrir(int $id, Request $request, ActionRepository $repo): Response
    {
        $this->verifierCsrf($request, 'creances_action_rouvrir_'.$id);
        $action = $repo->find($id);
        if (null === $action) {
            throw new NotFoundHttpException('Action introuvable.');
        }
        $action->rouvrir();
        $repo->save($action);

        $this->notifier->notifierTiers($action->getCompteCode(), 'action_rouverte', ['action_id' => $id]);
        $this->addFlash('success', 'Action reouverte.');

        return $this->redirectFiche($action->getCompteCode(), 'actions');
    }

    /**
     * Enregistre une promesse de paiement sur une ecriture (depuis le panneau
     * detail typiquement).
     */
    #[Route('/ecriture/{numero}/promesse', name: 'app_creances_promesse_creer', methods: ['POST'], requirements: ['numero' => '[^/]+'])]
    public function promesseCreer(string $numero, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_promesse_'.$numero);
        $compteCode = self::texte($request->request->get('compte_code'));
        $datePromesse = self::date($request->request->get('date_promesse'));
        if (null === $compteCode || null === $datePromesse) {
            $this->addFlash('warning', 'Compte et date de promesse sont obligatoires.');

            return $this->redirectToRoute('app_creances_index');
        }
        $montant = self::texte($request->request->get('montant'));
        $commentaire = self::texte($request->request->get('commentaire'));
        $auteur = $this->utilisateurCourant();

        $this->promesses->save(new Promesse($compteCode, $numero, $datePromesse, $auteur, $montant, $commentaire));

        $this->notifier->notifierTiers($compteCode, 'promesse_creee', ['ecriture' => $numero, 'date' => $datePromesse->format('Y-m-d')]);
        $this->addFlash('success', 'Promesse enregistree.');

        return $this->redirectFiche($compteCode, 'actions');
    }

    /**
     * Change le statut d'une promesse (tenue / non_tenue / annulee).
     */
    #[Route('/promesse/{id}/statut', name: 'app_creances_promesse_statut', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function promesseStatut(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_promesse_statut_'.$id);
        $promesse = $this->promesses->find($id);
        if (null === $promesse) {
            throw new NotFoundHttpException('Promesse introuvable.');
        }
        $statut = PromesseStatut::tryFrom((string) $request->request->get('statut', ''));
        if (null === $statut) {
            $this->addFlash('warning', 'Statut invalide.');

            return $this->redirectFiche($promesse->getCompteCode(), 'actions');
        }
        $promesse->changerStatut($statut);
        $this->promesses->save($promesse);

        $this->notifier->notifierTiers($promesse->getCompteCode(), 'promesse_statut', ['promesse_id' => $id, 'statut' => $statut->value]);
        $this->addFlash('success', 'Statut de la promesse mis a jour.');

        return $this->redirectFiche($promesse->getCompteCode(), 'actions');
    }

    /**
     * Acces rapide depuis la fiche tiers : prepare la prochaine relance pour
     * ce compte selon sa strategie attachee. Redirige vers le tableau de
     * bord "Relance expert" pour envoyer effectivement.
     */
    #[Route('/tiers/{code}/relancer', name: 'app_creances_tiers_relancer', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function relancerTiers(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_tiers_relancer_'.$code);
        $cs = $this->compteStrategies->findByCompte($code);
        if (null === $cs) {
            $this->addFlash('warning', 'Aucune strategie attachee a ce compte. Affecte-en une depuis l\'onglet Actions ou la page Strategies.');

            return $this->redirectFiche($code, 'actions');
        }
        $auteur = $this->utilisateurCourant();
        $envoi = $this->relanceExpert->preparerProchaineRelance($cs, $auteur);
        if (null === $envoi) {
            $this->addFlash('info', 'Aucune relance a preparer (toutes les etapes ont deja ete declenchees ou aucune n\'est due maintenant).');

            return $this->redirectFiche($code, 'actions');
        }
        $this->addFlash('success', 'Relance preparee, en attente d\'envoi dans le tableau de bord Relance Expert.');

        return $this->redirectToRoute('app_creances_relance_expert');
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function verifierCsrf(Request $request, string $intention): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intention, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function utilisateurCourant(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }

    private function resoudreUtilisateur(int $id): ?User
    {
        $em = $this->container->get('doctrine')->getManager();
        /** @var User|null $u */
        $u = $em->getRepository(User::class)->find($id);

        return $u;
    }

    private function redirectFiche(string $code, ?string $tab = null): Response
    {
        $url = $this->generateUrl('app_creances_fiche', ['code' => $code]);
        if (null !== $tab) {
            $url .= '#tab='.$tab;
        }

        return $this->redirect($url);
    }

    private static function date(mixed $valeur): ?DateTimeImmutable
    {
        if (!is_string($valeur) || '' === trim($valeur)) {
            return null;
        }
        try {
            return new DateTimeImmutable($valeur);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Extrait + valide les filtres depuis la query string. Anti-injection :
     * les valeurs multi-select sont filtrees a posteriori contre les listes
     * de reference via les ANY(:...) bindings DBAL.
     *
     * @return array{
     *     tranches: list<string>,
     *     etablissement: list<string>,
     *     marque: list<string>,
     *     code: ?string,
     *     texte: ?string,
     *     montant_min: ?float
     * }
     */
    private function extraireFiltres(Request $request): array
    {
        return [
            'tranches' => $this->valeursValides($request->query->all('tranches'), array_keys(CreancesRepository::TRANCHES)),
            'etablissement' => self::tableau($request->query->all('etablissement')),
            'marque' => self::tableau($request->query->all('marque')),
            'code' => self::texte($request->query->get('code')),
            'texte' => self::texte($request->query->get('texte')),
            'montant_min' => self::montantPositif($request->query->get('montant_min')),
        ];
    }

    /**
     * @param array{
     *     tranches: list<string>, etablissement: list<string>, marque: list<string>,
     *     code: ?string, texte: ?string, montant_min: ?float
     * } $filtres
     *
     * @return array{etablissement?: list<string>}
     */
    private function filtresIndicateurs(array $filtres): array
    {
        $out = [];
        if (!empty($filtres['etablissement'])) {
            $out['etablissement'] = $filtres['etablissement'];
        }

        return $out;
    }

    /**
     * Decode la valeur brute d'un champ JSONB (DBAL peut le rendre tableau ou
     * chaine suivant les versions).
     *
     * @return array<string, mixed>
     */
    private function decoderDonnees(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Construit la liste des pills (filtres actifs) avec, pour chacune, l'URL
     * vers la meme page sans ce filtre + le name + valeur_brute pour permettre
     * au JS de decocher l'input correspondant cote formulaire.
     *
     * @param array{
     *     tranches: list<string>, etablissement: list<string>, marque: list<string>,
     *     code: ?string, texte: ?string, montant_min: ?float
     * } $filtres
     *
     * @return list<array{source: string, label: string, name: string, valeur: string, valeur_brute: string, url: string}>
     */
    private function construirePills(array $filtres, string $tri, string $sens): array
    {
        $pills = [];

        foreach ($filtres['tranches'] as $v) {
            $pills[] = [
                'source' => 'sage',
                'label' => 'Anciennete',
                'name' => 'tranches[]',
                'valeur' => CreancesRepository::TRANCHES_LIBELLE[$v] ?? $v,
                'valeur_brute' => $v,
                'url' => $this->urlSansValeur($filtres, $tri, $sens, 'tranches', $v),
            ];
        }
        foreach ($filtres['etablissement'] as $v) {
            $pills[] = [
                'source' => 'sage',
                'label' => 'Etablissement',
                'name' => 'etablissement[]',
                'valeur' => $v,
                'valeur_brute' => $v,
                'url' => $this->urlSansValeur($filtres, $tri, $sens, 'etablissement', $v),
            ];
        }
        foreach ($filtres['marque'] as $v) {
            $pills[] = [
                'source' => 'sage',
                'label' => 'Marque',
                'name' => 'marque[]',
                'valeur' => $v,
                'valeur_brute' => $v,
                'url' => $this->urlSansValeur($filtres, $tri, $sens, 'marque', $v),
            ];
        }
        if (null !== $filtres['code']) {
            $pills[] = [
                'source' => 'sage',
                'label' => 'Compte',
                'name' => 'code',
                'valeur' => $filtres['code'],
                'valeur_brute' => $filtres['code'],
                'url' => $this->urlSansValeur($filtres, $tri, $sens, 'code', $filtres['code']),
            ];
        }
        if (null !== $filtres['texte']) {
            $pills[] = [
                'source' => 'sage',
                'label' => 'Recherche',
                'name' => 'texte',
                'valeur' => $filtres['texte'],
                'valeur_brute' => $filtres['texte'],
                'url' => $this->urlSansValeur($filtres, $tri, $sens, 'texte', $filtres['texte']),
            ];
        }
        if (null !== $filtres['montant_min']) {
            $pills[] = [
                'source' => 'sage',
                'label' => 'Montant min',
                'name' => 'montant_min',
                'valeur' => sprintf('>= %s EUR', number_format($filtres['montant_min'], 0, ',', ' ')),
                'valeur_brute' => (string) $filtres['montant_min'],
                'url' => $this->urlSansValeur($filtres, $tri, $sens, 'montant_min', (string) $filtres['montant_min']),
            ];
        }

        return $pills;
    }

    /**
     * Genere l'URL courante en retirant *une valeur* d'un filtre (multi ou
     * scalaire).
     *
     * @param array<string, mixed> $filtres
     */
    private function urlSansValeur(array $filtres, string $tri, string $sens, string $champ, string $valeur): string
    {
        $copy = $filtres;
        $current = $copy[$champ] ?? null;
        if (is_array($current)) {
            $copy[$champ] = array_values(array_filter($current, static fn ($v): bool => $v !== $valeur));
        } else {
            $copy[$champ] = null;
        }

        $params = array_filter([
            'tranches' => $copy['tranches'] ?? null,
            'etablissement' => $copy['etablissement'] ?? null,
            'marque' => $copy['marque'] ?? null,
            'code' => $copy['code'] ?? null,
            'texte' => $copy['texte'] ?? null,
            'montant_min' => $copy['montant_min'] ?? null,
            'tri' => $tri,
            'sens' => $sens,
        ], static fn ($v): bool => null !== $v && [] !== $v);

        return $this->generateUrl('app_creances_index', $params);
    }

    /**
     * @param array<mixed> $valeurs
     * @param list<string> $autorisees
     *
     * @return list<string>
     */
    private function valeursValides(array $valeurs, array $autorisees): array
    {
        $out = [];
        foreach ($valeurs as $v) {
            if (is_string($v) && in_array($v, $autorisees, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    /**
     * @param array<mixed> $valeurs
     *
     * @return list<string>
     */
    private static function tableau(array $valeurs): array
    {
        $out = [];
        foreach ($valeurs as $v) {
            if (is_string($v) && '' !== trim($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function montantPositif(mixed $valeur): ?float
    {
        if (!is_string($valeur) && !is_numeric($valeur)) {
            return null;
        }
        $f = (float) $valeur;

        return $f > 0 ? $f : null;
    }
}
