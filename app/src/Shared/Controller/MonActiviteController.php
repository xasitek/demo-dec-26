<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\User;
use App\Shared\Repository\ActiviteJourRepository;
use App\Shared\Repository\ActivityLogRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page "Mon activite" : chaque utilisateur consulte son propre temps de presence
 * active et ses connexions. Repond au droit d'acces RGPD du salarie (donnee de
 * surveillance). Voir docs/SECURITY.md et [[project-logs-activite]].
 */
#[Route('/mon-activite', name: 'app_mon_activite', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class MonActiviteController extends AbstractController
{
    private const LOGS_PAR_PAGE = 5;

    public function __construct(
        private readonly ActiviteJourRepository $activiteJour,
        private readonly ActivityLogRepository $logs,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $total = $this->logs->countAll($user);
        $totalPages = max(1, (int) ceil($total / self::LOGS_PAR_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        $params = [
            'logs' => $this->logs->findPage($page, self::LOGS_PAR_PAGE, $user),
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
        ];

        if ($request->query->getBoolean('fragment')) {
            return $this->render('admin/activite/_rows.html.twig', $params);
        }

        $today = new DateTimeImmutable('today');
        $detail = $this->activiteJour->detailParJour($user, $today->modify('-29 days'));
        $debutSemaine = $today->modify('-6 days');
        $aujourdhui = 0;
        $semaine = 0;
        foreach ($detail as $d) {
            if ($d['jour'] >= $today) {
                $aujourdhui += $d['secondes'];
            }
            if ($d['jour'] >= $debutSemaine) {
                $semaine += $d['secondes'];
            }
        }

        return $this->render('mon_activite/index.html.twig', $params + [
            'detail' => $detail,
            'aujourdhui' => $aujourdhui,
            'semaine' => $semaine,
        ]);
    }
}
