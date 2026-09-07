<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Secretaire\RegistreEspaces;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accueil du poste secretaire : une URL neutre, hors de tout module, ou elle
 * choisit son espace.
 *
 * C'est le point d'entree unique a donner aux secretaires — un lien qui ne change
 * pas quand un module s'ajoute, et qui ne privilegie aucun metier. Les espaces
 * viennent du RegistreEspaces : cette page n'en connait aucun.
 *
 * Reserve aux connectes depuis le 2026-09-03 : seuls les deux formulaires de depot
 * restent publics, parce qu'on donne leur lien direct aux secretaires. Tout le
 * reste passe par la page de connexion, ou LoginEntryPoint renvoie les anonymes.
 */
final class PosteSecretaireController extends AbstractController
{
    public function __construct(private readonly RegistreEspaces $registre)
    {
    }

    #[Route('/espace', name: 'app_poste_secretaire', methods: ['GET'])]
    public function accueil(): Response
    {
        return $this->render('espace/accueil.html.twig', [
            'espaces' => $this->registre->espacesAccessibles(),
        ]);
    }
}
