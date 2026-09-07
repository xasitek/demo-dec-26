<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Compte connecte mais pas encore habilite : ecran d'attente / demande.
        if (!$user->isHabilite()) {
            return $this->render('security/pending.html.twig');
        }

        // Derniere synchro Progiciel = dernier vu_le du mirror (mis a jour par l'ETL).
        // vu_le est un timestamp SANS fuseau qui stocke de l'UTC : on l'ancre en UTC
        // pour que l'affichage puisse le convertir en heure locale (Europe/Paris).
        $derniereMajSage = null;
        try {
            $brut = $this->connection->fetchOne('SELECT MAX(vu_le) FROM mirror.bal_eloficash');
            if (\is_string($brut) && '' !== $brut) {
                $derniereMajSage = new DateTimeImmutable($brut, new DateTimeZone('UTC'));
            }
        } catch (Throwable) {
            $derniereMajSage = null;
        }

        return $this->render('dashboard/index.html.twig', [
            'derniere_maj_sage' => $derniereMajSage,
        ]);
    }
}
