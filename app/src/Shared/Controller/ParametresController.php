<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace « Paramètres » : point d'entree des referentiels et reglages de Synthauto
 * Finance. Accessible a tout utilisateur connecte. Une carte par domaine de
 * configuration (pour l'instant : coordonnees des etablissements ; d'autres a venir).
 */
final class ParametresController extends AbstractController
{
    #[Route('/parametres', name: 'app_parametres', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        return $this->render('parametres/index.html.twig');
    }
}
