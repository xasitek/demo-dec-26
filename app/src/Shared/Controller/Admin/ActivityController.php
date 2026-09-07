<?php

declare(strict_types=1);

namespace App\Shared\Controller\Admin;

use App\Shared\Entity\User;
use App\Shared\Repository\ActiviteJourRepository;
use App\Shared\Repository\ActivityLogRepository;
use App\Shared\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal d'activite (connexions/deconnexions), consultable par les managers.
 * Voir docs/SECURITY.md.
 */
#[Route('/admin/activite')]
#[IsGranted('ROLE_MANAGER')]
final class ActivityController extends AbstractController
{
    private const PAR_PAGE = 10;

    /** Periodes d'analyse proposees (en jours) pour le tableau de bord par personne. */
    private const JOURS_OPTIONS = [7, 30, 90];

    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly ActiviteJourRepository $activiteJour,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_admin_activite', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $userId = $request->query->getInt('user');
        $cible = $userId > 0 ? $this->users->find($userId) : null;

        $total = $this->logs->countAll($cible);
        $totalPages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        $params = [
            'logs' => $this->logs->findPage($page, self::PAR_PAGE, $cible),
            'cible' => $cible,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ];

        if ($request->query->getBoolean('fragment')) {
            return $this->render('admin/activite/_rows.html.twig', $params);
        }

        // Tableau de bord par personne : KPIs + serie quotidienne sur la periode.
        if (null !== $cible) {
            $params['stats'] = $this->statsUtilisateur($cible, $request->query->getInt('jours', 30));
            $params['jours_options'] = self::JOURS_OPTIONS;
        }

        return $this->render('admin/activite/index.html.twig', $params);
    }

    /**
     * Bascule la visibilite d'un utilisateur dans la pile d'avatars de presence.
     */
    #[Route('/{id}/visibilite', name: 'app_admin_activite_visibilite', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function basculerVisibilite(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('presence_visibilite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $cible = $this->users->find($id);
        if (null === $cible) {
            throw $this->createNotFoundException();
        }

        $cible->setPresenceVisible(!$cible->isPresenceVisible());
        $this->em->flush();

        return $this->redirectToRoute('app_admin_activite', ['user' => $id]);
    }

    /**
     * Statistiques de presence active d'un utilisateur sur une periode.
     *
     * @return array{jours: int, total: int, moyenne: int, joursActifs: int, connexions: int, max: int, serie: list<array{jour: DateTimeImmutable, secondes: int}>}
     */
    private function statsUtilisateur(User $cible, int $jours): array
    {
        if (!\in_array($jours, self::JOURS_OPTIONS, true)) {
            $jours = 30;
        }

        $today = new DateTimeImmutable('today');
        $depuis = $today->modify('-'.($jours - 1).' days');

        $parJour = [];
        foreach ($this->activiteJour->detailParJour($cible, $depuis) as $d) {
            $parJour[$d['jour']->format('Y-m-d')] = $d['secondes'];
        }

        $serie = [];
        $total = 0;
        $joursActifs = 0;
        $max = 0;
        for ($i = 0; $i < $jours; ++$i) {
            $jour = $depuis->modify('+'.$i.' days');
            $secondes = $parJour[$jour->format('Y-m-d')] ?? 0;
            $serie[] = ['jour' => $jour, 'secondes' => $secondes];
            $total += $secondes;
            if ($secondes > 0) {
                ++$joursActifs;
            }
            if ($secondes > $max) {
                $max = $secondes;
            }
        }

        return [
            'jours' => $jours,
            'total' => $total,
            'moyenne' => $joursActifs > 0 ? intdiv($total, $joursActifs) : 0,
            'joursActifs' => $joursActifs,
            'connexions' => $this->logs->compterConnexionsDepuis($cible, $depuis),
            'max' => $max,
            'serie' => $serie,
        ];
    }
}
