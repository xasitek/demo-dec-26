<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\CompteStrategie;
use App\Creances\Entity\Strategie;
use App\Creances\Entity\StrategieNiveau;
use App\Creances\Enum\ActionType;
use App\Creances\Enum\CompteStrategieEtat;
use App\Creances\Repository\CompteStrategieRepository;
use App\Creances\Repository\StrategieNiveauRepository;
use App\Creances\Repository\StrategieRepository;
use App\Creances\Service\RecouvrementNotifier;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration des strategies de relance et affectation a un compte.
 *
 * Acces ROLE_COMPTABLE. CSRF sur tous les POST. Mercure best-effort sur
 * l'affectation et l'etat des comptes.
 */
#[Route('/creances')]
#[IsGranted('ROLE_COMPTABLE')]
final class StrategieController extends AbstractController
{
    public function __construct(
        private readonly StrategieRepository $strategies,
        private readonly StrategieNiveauRepository $niveaux,
        private readonly CompteStrategieRepository $comptes,
        private readonly RecouvrementNotifier $notifier,
    ) {
    }

    // ============================================================
    // Liste + creation/edition de strategies
    // ============================================================

    #[Route('/strategies', name: 'app_creances_strategies', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('creances/strategies.html.twig', [
            'strategies' => $this->strategies->findToutes(),
        ]);
    }

    #[Route('/strategie/{id}', name: 'app_creances_strategie_voir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function voir(int $id): Response
    {
        $s = $this->strategies->find($id);
        if (null === $s) {
            throw new NotFoundHttpException('Strategie introuvable.');
        }

        return $this->render('creances/strategie_detail.html.twig', [
            'strategie' => $s,
        ]);
    }

    #[Route('/strategie', name: 'app_creances_strategie_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_strategie_creer');
        $code = self::texte($request->request->get('code'));
        $libelle = self::texte($request->request->get('libelle'));
        if (null === $code || null === $libelle) {
            $this->addFlash('warning', 'Code et libelle sont obligatoires.');

            return $this->redirectToRoute('app_creances_strategies');
        }
        $description = self::texte($request->request->get('description'));
        $auteur = $this->utilisateurCourant();

        $s = new Strategie($code, $libelle, $auteur, $description);
        $this->strategies->save($s);
        $this->addFlash('success', 'Strategie creee. Ajoute maintenant les niveaux de relance.');

        return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $s->getId()]);
    }

    #[Route('/strategie/{id}/modifier', name: 'app_creances_strategie_modifier', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function modifier(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_strategie_modifier_'.$id);
        $s = $this->strategies->find($id);
        if (null === $s) {
            throw new NotFoundHttpException('Strategie introuvable.');
        }
        $libelle = self::texte($request->request->get('libelle'));
        if (null === $libelle) {
            $this->addFlash('warning', 'Libelle obligatoire.');

            return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
        }
        $s->setLibelle($libelle);
        $s->setDescription(self::texte($request->request->get('description')));
        $s->setActive($request->request->getBoolean('active'));
        $this->strategies->save($s);
        $this->addFlash('success', 'Strategie mise a jour.');

        return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
    }

    #[Route('/strategie/{id}/supprimer', name: 'app_creances_strategie_supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_strategie_supprimer_'.$id);
        $s = $this->strategies->find($id);
        if (null === $s) {
            throw new NotFoundHttpException('Strategie introuvable.');
        }
        $this->strategies->remove($s);
        $this->addFlash('success', 'Strategie supprimee.');

        return $this->redirectToRoute('app_creances_strategies');
    }

    // ============================================================
    // Niveaux d'une strategie
    // ============================================================

    #[Route('/strategie/{id}/niveau', name: 'app_creances_niveau_creer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function niveauCreer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_niveau_'.$id);
        $s = $this->strategies->find($id);
        if (null === $s) {
            throw new NotFoundHttpException('Strategie introuvable.');
        }
        $type = ActionType::tryFrom((string) $request->request->get('type', ''));
        $libelle = self::texte($request->request->get('libelle'));
        if (null === $type || null === $libelle) {
            $this->addFlash('warning', 'Type et libelle obligatoires.');

            return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
        }
        $joursDelai = $request->request->getInt('jours_delai', 0);
        $condition = self::texte($request->request->get('condition_libelle'));
        $ordre = $s->getNiveaux()->count() + 1;

        $niveau = new StrategieNiveau($s, $ordre, $libelle, $type, $joursDelai, $condition);
        $this->niveaux->save($niveau);
        $this->addFlash('success', 'Niveau ajoute.');

        return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
    }

    #[Route('/strategie/{id}/niveau/{niveauId}/modifier', name: 'app_creances_niveau_modifier', methods: ['POST'], requirements: ['id' => '\d+', 'niveauId' => '\d+'])]
    public function niveauModifier(int $id, int $niveauId, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_niveau_modifier_'.$niveauId);
        $niveau = $this->niveaux->find($niveauId);
        if (null === $niveau || $niveau->getStrategie()->getId() !== $id) {
            throw new NotFoundHttpException('Niveau introuvable.');
        }
        $type = ActionType::tryFrom((string) $request->request->get('type', $niveau->getTypeAction()->value));
        $libelle = self::texte($request->request->get('libelle'));
        if (null === $type || null === $libelle) {
            $this->addFlash('warning', 'Type et libelle obligatoires.');

            return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
        }
        $niveau->setLibelle($libelle);
        $niveau->setTypeAction($type);
        $niveau->setJoursDelai($request->request->getInt('jours_delai', $niveau->getJoursDelai()));
        $niveau->setConditionLibelle(self::texte($request->request->get('condition_libelle')));
        $this->niveaux->save($niveau);
        $this->addFlash('success', 'Niveau modifie.');

        return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
    }

    #[Route('/strategie/{id}/niveau/{niveauId}/supprimer', name: 'app_creances_niveau_supprimer', methods: ['POST'], requirements: ['id' => '\d+', 'niveauId' => '\d+'])]
    public function niveauSupprimer(int $id, int $niveauId, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_niveau_supprimer_'.$niveauId);
        $niveau = $this->niveaux->find($niveauId);
        if (null === $niveau || $niveau->getStrategie()->getId() !== $id) {
            throw new NotFoundHttpException('Niveau introuvable.');
        }
        $this->niveaux->remove($niveau);

        // Reorganiser les ordres restants pour eviter les trous.
        $i = 1;
        foreach ($niveau->getStrategie()->getNiveaux() as $n) {
            $n->setOrdre($i++);
        }
        $this->niveaux->save($niveau);
        $this->addFlash('success', 'Niveau supprime.');

        return $this->redirectToRoute('app_creances_strategie_voir', ['id' => $id]);
    }

    // ============================================================
    // Affectation strategie a un compte (depuis la fiche tiers)
    // ============================================================

    #[Route('/tiers/{code}/strategie', name: 'app_creances_affecter_strategie', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function affecter(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_affecter_strategie_'.$code);
        $strategieId = $request->request->getInt('strategie_id');
        $strategie = $strategieId > 0 ? $this->strategies->find($strategieId) : null;

        $cs = $this->comptes->findByCompte($code);
        if (null === $cs) {
            $cs = new CompteStrategie($code, $strategie);
        } else {
            $cs->affecterStrategie($strategie);
        }
        $this->comptes->save($cs);

        $this->notifier->notifierTiers($code, 'strategie_affectee', ['strategie_id' => $strategieId]);
        $this->addFlash('success', null !== $strategie ? 'Strategie affectee.' : 'Strategie retiree (relance desactivee).');

        return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=actions');
    }

    #[Route('/tiers/{code}/strategie/repositionner', name: 'app_creances_repositionner', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function repositionner(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_repositionner_'.$code);
        $cs = $this->comptes->findByCompte($code);
        if (null === $cs) {
            throw new NotFoundHttpException('Aucune strategie affectee.');
        }
        $jusquAu = self::date($request->request->get('jusqu_au'));
        $cs->repositionner($jusquAu);
        $this->comptes->save($cs);

        $this->notifier->notifierTiers($code, 'strategie_repositionnee', ['jusqu_au' => $jusquAu?->format('Y-m-d')]);
        $this->addFlash('success', null !== $jusquAu ? 'Relance repositionnee.' : 'Relance reactivee.');

        return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=actions');
    }

    #[Route('/tiers/{code}/strategie/etat', name: 'app_creances_strategie_etat', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function changerEtat(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_strategie_etat_'.$code);
        $cs = $this->comptes->findByCompte($code);
        if (null === $cs) {
            throw new NotFoundHttpException('Aucune strategie affectee.');
        }
        $etat = CompteStrategieEtat::tryFrom((string) $request->request->get('etat', ''));
        if (null === $etat) {
            $this->addFlash('warning', 'Etat invalide.');

            return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=actions');
        }
        $cs->changerEtat($etat);
        $this->comptes->save($cs);

        $this->addFlash('success', 'Etat de la relance mis a jour.');

        return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=actions');
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

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
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
}
