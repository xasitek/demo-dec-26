<?php

declare(strict_types=1);

namespace App\Recouvrement\Controller;

use App\Recouvrement\Entity\PreparationRun;
use App\Recouvrement\Entity\RegleRelance;
use App\Recouvrement\Message\LancerPreparation;
use App\Recouvrement\Regle\MoteurFiltre;
use App\Recouvrement\Repository\PreparationRunRepository;
use App\Recouvrement\Repository\RegleRelanceRepository;
use App\Recouvrement\Service\SelectionRelanceService;
use App\Shared\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pilotage des RÈGLES DE RELANCE (vue Stratégies) : la stratégie devient de la
 * donnée éditable. Lister les règles avec un aperçu live des volumes, créer /
 * modifier / réordonner / activer / supprimer, tester des filtres en direct.
 *
 * Accès comptable ou manager (comme le reste du module).
 */
#[Route('/recouvrement/admin/strategies')]
final class RegleRelanceController extends AbstractController
{
    public function __construct(
        private readonly RegleRelanceRepository $regles,
        private readonly MoteurFiltre $moteurFiltre,
        private readonly PreparationRunRepository $runs,
        private readonly MessageBusInterface $bus,
        private readonly SelectionRelanceService $selection,
    ) {
    }

    #[Route('', name: 'app_recouvrement_admin_strategies', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessRecouvrement();

        $regles = $this->regles->findToutesOrdonnees();
        $volumes = [];
        foreach ($regles as $regle) {
            $volumes[(int) $regle->getId()] = $this->regles->apercuVolumes($regle);
        }

        $runEnCours = $this->runs->enCours();

        return $this->render('recouvrement/strategies/index.html.twig', [
            'regles' => $regles,
            'volumes' => $volumes,
            'champs' => MoteurFiltre::LIBELLES_CHAMPS,
            'operateurs' => MoteurFiltre::LIBELLES_OPERATEURS,
            'run_en_cours' => $runEnCours,
        ]);
    }

    #[Route('/nouvelle', name: 'app_recouvrement_regle_nouvelle', methods: ['GET'])]
    public function nouvelle(): Response
    {
        $this->denyUnlessRecouvrement();

        return $this->render('recouvrement/strategies/form.html.twig', [
            'regle' => null,
            'champs' => MoteurFiltre::LIBELLES_CHAMPS,
            'operateurs' => MoteurFiltre::LIBELLES_OPERATEURS,
            'options_valeurs' => $this->selection->optionsValeursChamps(),
            'champs_categoriels' => MoteurFiltre::CHAMPS_CATEGORIELS,
        ]);
    }

    #[Route('/{id}/editer', name: 'app_recouvrement_regle_editer', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editer(RegleRelance $regle): Response
    {
        $this->denyUnlessRecouvrement();

        return $this->render('recouvrement/strategies/form.html.twig', [
            'regle' => $regle,
            'champs' => MoteurFiltre::LIBELLES_CHAMPS,
            'operateurs' => MoteurFiltre::LIBELLES_OPERATEURS,
            'options_valeurs' => $this->selection->optionsValeursChamps(),
            'champs_categoriels' => MoteurFiltre::CHAMPS_CATEGORIELS,
        ]);
    }

    #[Route('/enregistrer', name: 'app_recouvrement_regle_enregistrer', methods: ['POST'])]
    public function enregistrer(Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        $this->verifierJeton($request);

        $id = $request->request->getInt('id');
        $regle = $id > 0 ? $this->regles->find($id) : null;
        if (null === $regle) {
            $regle = new RegleRelance($this->nomSaisi($request));
            $regle->setPriorite($this->regles->prochainePriorite());
        } else {
            $regle->setNom($this->nomSaisi($request));
            $regle->toucherModifieLe();
        }

        $filtres = $this->moteurFiltre->normaliserFiltres($request->request->all('filtres'));
        $regle->setFiltres($filtres);

        // Garde-fou : une règle SANS filtre valide viserait TOUS les comptes
        // (catch-all) et court-circuiterait les règles spécifiques via la résolution
        // « 1re règle qui matche ». On refuse de l'activer dans ce cas.
        $actif = $request->request->getBoolean('actif');
        if ([] === $filtres && $actif) {
            $actif = false;
            $this->addFlash('warning', 'Règle enregistrée mais DÉSACTIVÉE : aucun filtre valide (elle viserait tous les comptes). Ajoutez au moins un filtre pour l\'activer.');
        }
        $regle->setActif($actif);

        $regle->setDelaiInitial(max(0, $request->request->getInt('delai_initial')));
        $regle->setIntervalle(max(1, $request->request->getInt('intervalle', 15)));
        $regle->setSeuilMed(max(0, $request->request->getInt('seuil_med', 90)));
        $regle->setMontantMin(max(0, $request->request->getInt('montant_min', 100)));
        $regle->setContinuerApresMed($request->request->getBoolean('continuer_apres_med'));
        $regle->setReleveSeul($request->request->getBoolean('releve_seul'));

        $this->regles->sauvegarder($regle);
        $this->addFlash('success', sprintf('Règle « %s » enregistrée.', $regle->getNom()));

        return $this->redirectToRoute('app_recouvrement_admin_strategies');
    }

    #[Route('/{id}/supprimer', name: 'app_recouvrement_regle_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(RegleRelance $regle, Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        $this->verifierJeton($request);

        $nom = $regle->getNom();
        $this->regles->supprimer($regle);
        $this->addFlash('success', sprintf('Règle « %s » supprimée.', $nom));

        return $this->redirectToRoute('app_recouvrement_admin_strategies');
    }

    #[Route('/{id}/basculer', name: 'app_recouvrement_regle_basculer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function basculer(RegleRelance $regle, Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        $this->verifierJeton($request);

        $regle->setActif(!$regle->isActif());
        $regle->toucherModifieLe();
        $this->regles->sauvegarder($regle);

        return $this->redirectToRoute('app_recouvrement_admin_strategies');
    }

    /**
     * Lance MANUELLEMENT une strategie (bouton "Lancer maintenant", pour les
     * premiers tests en prod) : cree un run de suivi puis dispatch le travail au
     * worker interne (preparation + dispatch des relances de CETTE regle, meme si
     * elle est en pause). La progression s'affiche via la barre globale (Mercure).
     */
    #[Route('/{id}/lancer', name: 'app_recouvrement_regle_lancer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lancer(RegleRelance $regle, Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        $this->verifierJeton($request);

        $regleId = (int) $regle->getId();

        // Nettoie d'abord les runs zombies (worker mort avant la fin) pour ne pas
        // bloquer inutilement le relancement de cette strategie.
        $this->runs->expirerZombies();

        // Anti-doublon : un seul lancement vivant a la fois par strategie.
        if ($this->runs->enCoursPourRegle($regleId)) {
            $this->addFlash('warning', sprintf('Un lancement de « %s » est déjà en cours.', $regle->getNom()));

            return $this->redirectToRoute('app_recouvrement_admin_strategies');
        }

        $user = $this->getUser();
        $lancePar = $user instanceof User ? ($user->getFullName() ?: $user->getEmail()) : null;

        $run = new PreparationRun($regleId, $regle->getNom(), $lancePar);
        try {
            $this->runs->save($run);
        } catch (UniqueConstraintViolationException) {
            // Course (double-clic / deux comptables) : l'index unique partiel a
            // bloque le 2e run EN_COURS pour cette regle.
            $this->addFlash('warning', sprintf('Un lancement de « %s » vient de démarrer.', $regle->getNom()));

            return $this->redirectToRoute('app_recouvrement_admin_strategies');
        }

        $runId = $run->getId();
        if (null === $runId) {
            $this->addFlash('error', 'Lancement impossible : run non créé.');

            return $this->redirectToRoute('app_recouvrement_admin_strategies');
        }

        $this->bus->dispatch(new LancerPreparation($runId, $regleId));
        $this->addFlash('success', sprintf(
            'Lancement de « %s » démarré. La progression s\'affiche en haut de page.',
            $regle->getNom(),
        ));

        return $this->redirectToRoute('app_recouvrement_admin_strategies');
    }

    /**
     * Réordonne une règle (monter/descendre) en échangeant sa priorité avec sa
     * voisine. L'ordre de priorité = ordre d'évaluation (1re règle qui matche gagne).
     */
    #[Route('/{id}/deplacer', name: 'app_recouvrement_regle_deplacer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deplacer(RegleRelance $regle, Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        $this->verifierJeton($request);

        $sens = 'haut' === $request->request->get('sens') ? 'haut' : 'bas';
        $ordonnees = $this->regles->findToutesOrdonnees();
        $index = null;
        foreach ($ordonnees as $i => $r) {
            if ($r->getId() === $regle->getId()) {
                $index = $i;
                break;
            }
        }

        if (null !== $index) {
            $voisinIndex = 'haut' === $sens ? $index - 1 : $index + 1;
            if (isset($ordonnees[$voisinIndex])) {
                $voisin = $ordonnees[$voisinIndex];
                $p = $regle->getPriorite();
                $regle->setPriorite($voisin->getPriorite());
                $voisin->setPriorite($p);
                // sauvegarder() flushe toutes les entités gérées : les 2 priorités partent.
                $this->regles->sauvegarder($regle);
            }
        }

        return $this->redirectToRoute('app_recouvrement_admin_strategies');
    }

    /**
     * Aperçu live (JSON) : combien de comptes / factures / encours les filtres
     * saisis attrapent. Appelé par le constructeur de filtres à chaque changement.
     */
    #[Route('/apercu', name: 'app_recouvrement_regle_apercu', methods: ['POST'])]
    public function apercu(Request $request): JsonResponse
    {
        $this->denyUnlessRecouvrement();

        $filtres = $this->moteurFiltre->normaliserFiltres($request->request->all('filtres'));

        return $this->json($this->regles->apercuPourFiltres($filtres));
    }

    private function nomSaisi(Request $request): string
    {
        $nom = trim((string) $request->request->get('nom'));

        return '' !== $nom ? mb_substr($nom, 0, 120) : 'Règle sans nom';
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('regle-relance', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function denyUnlessRecouvrement(): void
    {
        if (!$this->isGranted('ROLE_COMPTABLE') && !$this->isGranted('ROLE_MANAGER')) {
            throw $this->createAccessDeniedException();
        }
        // Trace le rôle sans effet de bord : garantit un utilisateur applicatif.
        if (!$this->getUser() instanceof User) {
            throw $this->createAccessDeniedException();
        }
    }
}
