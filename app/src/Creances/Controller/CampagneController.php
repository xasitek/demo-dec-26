<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\Campagne;
use App\Creances\Entity\CampagneExecution;
use App\Creances\Repository\CampagneExecutionRepository;
use App\Creances\Repository\CampagneRepository;
use App\Creances\Repository\CompteStrategieRepository;
use App\Creances\Repository\CreancesRepository;
use App\Creances\Repository\ModeleCourrierRepository;
use App\Creances\Repository\StrategieNiveauRepository;
use App\Creances\Service\RelanceExpertService;
use App\Shared\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD + execution manuelle des campagnes de relance. La planification
 * automatique (cron via Symfony Scheduler) sera ajoutee en iteration
 * ulterieure.
 */
#[Route('/creances/campagnes')]
#[IsGranted('ROLE_COMPTABLE')]
final class CampagneController extends AbstractController
{
    public function __construct(
        private readonly CampagneRepository $campagnes,
        private readonly CampagneExecutionRepository $executions,
        private readonly CompteStrategieRepository $compteStrategies,
        private readonly CreancesRepository $creances,
        private readonly ModeleCourrierRepository $modeles,
        private readonly StrategieNiveauRepository $niveaux,
        private readonly RelanceExpertService $relanceExpert,
    ) {
    }

    #[Route('', name: 'app_creances_campagnes', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('creances/campagnes.html.twig', [
            'campagnes' => $this->campagnes->findToutes(),
            'executions' => $this->executions->findRecentes(20),
            'modeles' => $this->modeles->findActifs(),
            'tranches' => CreancesRepository::TRANCHES_LIBELLE,
            'etablissements' => $this->creances->listerEtablissements(),
            'marques' => $this->creances->listerMarques(),
        ]);
    }

    #[Route('/creer', name: 'app_creances_campagne_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_campagne_creer');
        $libelle = self::texte($request->request->get('libelle'));
        if (null === $libelle) {
            $this->addFlash('warning', 'Libelle obligatoire.');

            return $this->redirectToRoute('app_creances_campagnes');
        }
        $auteur = $this->utilisateurCourant();

        $c = new Campagne($libelle, $auteur);
        $c->setDescription(self::texte($request->request->get('description')));
        $c->setFrequence(self::texte($request->request->get('frequence')) ?? 'ponctuelle');

        // Selection multi-criteres : tranches, marques, etablissement. Tous
        // stockes dans le champ JSONB `selection` (pas de migration). Niveau
        // auto = un flag qui dit a l'execution d'utiliser le nb d'actions
        // menees du compte pour choisir le niveau, plutot que
        // `niveauCourant + 1`.
        $c->setSelection([
            'tranches' => self::extraireListeChaine($request, 'tranches'),
            'marques' => self::extraireListeChaine($request, 'marques'),
            'etablissement' => self::extraireListeChaine($request, 'etablissement'),
            'niveau_auto' => $request->request->getBoolean('niveau_auto'),
        ]);

        $modeleId = $request->request->getInt('modele_courrier_id');
        if ($modeleId > 0) {
            $c->setModeleCourrier($this->modeles->find($modeleId));
        }
        $niveauId = $request->request->getInt('strategie_niveau_id');
        if ($niveauId > 0) {
            $c->setStrategieNiveau($this->niveaux->find($niveauId));
        }

        $this->campagnes->save($c);
        $this->addFlash('success', 'Campagne creee.');

        return $this->redirectToRoute('app_creances_campagnes');
    }

    #[Route('/{id}/executer', name: 'app_creances_campagne_executer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function executer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_campagne_executer_'.$id);
        $c = $this->campagnes->find($id);
        if (null === $c) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }
        if (!$c->isActive()) {
            $this->addFlash('warning', 'Campagne inactive.');

            return $this->redirectToRoute('app_creances_campagnes');
        }

        $auteur = $this->utilisateurCourant();
        $execution = new CampagneExecution($c, $auteur);
        $this->executions->save($execution);

        // Selection multi-criteres lue depuis le JSONB `selection`. Tous les
        // filtres existants de CreancesRepository::pageCreances sont
        // supportes (tranches, marques, etablissement).
        $selection = $c->getSelection();
        $filtresCreances = [];
        foreach (['tranches', 'marques', 'etablissement'] as $cle) {
            if (isset($selection[$cle]) && is_array($selection[$cle])) {
                $valeurs = array_values(array_filter(
                    $selection[$cle],
                    static fn ($v): bool => is_string($v) && '' !== $v
                ));
                if ([] !== $valeurs) {
                    // Singulier dans le filtre repo : marque (pas marques).
                    $filtresCreances['marques' === $cle ? 'marque' : $cle] = $valeurs;
                }
            }
        }
        $niveauAuto = (bool) ($selection['niveau_auto'] ?? false);

        $envoyes = 0;
        $erreurs = 0;
        $comptesTraites = [];
        $rows = $this->creances->pageCreances(1, 200, $filtresCreances, 'retard', 'desc');
        foreach ($rows as $row) {
            $d = is_array($row['donnees']) ? $row['donnees'] : (is_string($row['donnees']) ? (json_decode($row['donnees'], true) ?: []) : []);
            $code = isset($d['compte']) && is_string($d['compte']) ? $d['compte'] : null;
            if (null === $code || isset($comptesTraites[$code])) {
                continue;
            }
            $comptesTraites[$code] = true;

            $cs = $this->compteStrategies->findByCompte($code);
            if (null === $cs) {
                continue;
            }
            $niveau = $c->getStrategieNiveau();
            if (null !== $niveau) {
                $envoi = $this->relanceExpert->preparerPourNiveau($code, $niveau, $auteur);
            } elseif ($niveauAuto) {
                // Niveau auto : on choisit le niveau de la strategie selon le
                // nombre d'actions deja menees sur le compte (toutes sources).
                $envoi = $this->relanceExpert->preparerNiveauAutoPourCompte($cs, $auteur);
            } else {
                $envoi = $this->relanceExpert->preparerProchaineRelance($cs, $auteur);
            }
            if (null !== $envoi && $this->relanceExpert->envoyer($envoi)) {
                ++$envoyes;
            } else {
                ++$erreurs;
            }
        }

        $execution->finir($envoyes, $erreurs, count($comptesTraites));
        $this->executions->save($execution);

        $c->enregistrerExecution();
        $this->campagnes->save($c);

        $this->addFlash('success', sprintf('Campagne executee : %d envoye(s), %d erreur(s) sur %d compte(s).', $envoyes, $erreurs, count($comptesTraites)));

        return $this->redirectToRoute('app_creances_campagnes');
    }

    #[Route('/{id}/supprimer', name: 'app_creances_campagne_supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_campagne_supprimer_'.$id);
        $c = $this->campagnes->find($id);
        if (null === $c) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }
        $this->campagnes->remove($c);
        $this->addFlash('success', 'Campagne supprimee.');

        return $this->redirectToRoute('app_creances_campagnes');
    }

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

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    /**
     * Extrait une liste de chaines depuis `name[]=...` dans la requete,
     * filtre les vides. Renvoie une liste indexee numeriquement (list<string>).
     *
     * @return list<string>
     */
    private static function extraireListeChaine(Request $request, string $name): array
    {
        $brut = (array) $request->request->all($name);
        $out = [];
        foreach ($brut as $v) {
            if (is_string($v) && '' !== $v) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
