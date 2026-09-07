<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Enum\RelanceStatut;
use App\Creances\Repository\CompteStrategieRepository;
use App\Creances\Repository\RelanceEnvoiRepository;
use App\Creances\Service\RelanceExpertService;
use App\Shared\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dashboard "Relance Expert" : comptes a relancer + envois en cours +
 * envois archives. Permet de preparer une selection, d'envoyer en masse,
 * de supprimer ou d'archiver.
 */
#[Route('/creances/relance-expert')]
#[IsGranted('ROLE_COMPTABLE')]
final class RelanceExpertController extends AbstractController
{
    public function __construct(
        private readonly CompteStrategieRepository $compteStrategies,
        private readonly RelanceEnvoiRepository $envois,
        private readonly RelanceExpertService $relanceExpert,
    ) {
    }

    #[Route('', name: 'app_creances_relance_expert', methods: ['GET'])]
    public function dashboard(): Response
    {
        $aRelancer = $this->compteStrategies->findARelancer();
        $aEnvoyer = $this->envois->findAEnvoyer();
        $envoyesRecents = $this->envois->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', RelanceStatut::Envoye)
            ->orderBy('r.envoyeLe', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->render('creances/relance_expert.html.twig', [
            'a_relancer' => $aRelancer,
            'a_envoyer' => $aEnvoyer,
            'envoyes_recents' => $envoyesRecents,
        ]);
    }

    #[Route('/preparer', name: 'app_creances_relance_preparer', methods: ['POST'])]
    public function preparer(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_relance_preparer');
        $auteur = $this->utilisateurCourant();
        $ids = self::idsEntiers($request->request->all('compte_strategie_id'));

        $prepares = 0;
        foreach ($ids as $id) {
            $cs = $this->compteStrategies->find($id);
            if (null === $cs) {
                continue;
            }
            if (null !== $this->relanceExpert->preparerProchaineRelance($cs, $auteur)) {
                ++$prepares;
            }
        }
        $this->addFlash('success', $prepares > 0 ? $prepares.' relance(s) preparee(s) (a envoyer).' : 'Aucune relance preparee.');

        return $this->redirectToRoute('app_creances_relance_expert');
    }

    #[Route('/envoyer-selection', name: 'app_creances_relance_envoyer_selection', methods: ['POST'])]
    public function envoyerSelection(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_relance_envoyer');
        $ids = self::idsEntiers($request->request->all('envoi_id'));

        $ok = 0;
        $ko = 0;
        foreach ($ids as $id) {
            $envoi = $this->envois->find($id);
            if (null === $envoi) {
                continue;
            }
            if ($this->relanceExpert->envoyer($envoi)) {
                ++$ok;
            } else {
                ++$ko;
            }
        }
        $this->addFlash('success', sprintf('%d envoi(s) reussi(s), %d en erreur.', $ok, $ko));

        return $this->redirectToRoute('app_creances_relance_expert');
    }

    /**
     * @param array<mixed> $valeurs
     *
     * @return list<int>
     */
    private static function idsEntiers(array $valeurs): array
    {
        $out = [];
        foreach ($valeurs as $v) {
            if (is_string($v) || is_int($v)) {
                $id = (int) $v;
                if ($id > 0) {
                    $out[] = $id;
                }
            }
        }

        return $out;
    }

    #[Route('/{id}/supprimer', name: 'app_creances_relance_supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_relance_supprimer_'.$id);
        $envoi = $this->envois->find($id);
        if (null === $envoi) {
            throw new NotFoundHttpException('Envoi introuvable.');
        }
        $envoi->marquerSupprime();
        $this->envois->save($envoi);
        $this->addFlash('success', 'Envoi supprime (ne sera pas expedie).');

        return $this->redirectToRoute('app_creances_relance_expert');
    }

    #[Route('/archiver-envoyes', name: 'app_creances_relance_archiver', methods: ['POST'])]
    public function archiverEnvoyes(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_relance_archiver');
        $envoyes = $this->envois->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', RelanceStatut::Envoye)
            ->getQuery()
            ->getResult();

        $n = 0;
        foreach ($envoyes as $e) {
            $e->archiver();
            $this->envois->save($e);
            ++$n;
        }
        $this->addFlash('success', $n.' envoi(s) archive(s).');

        return $this->redirectToRoute('app_creances_relance_expert');
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
}
