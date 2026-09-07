<?php

declare(strict_types=1);

namespace App\Demo\Controller;

use App\Shared\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connexion de DEMONSTRATION.
 *
 * En production, l'application s'authentifie aupres du fournisseur d'identite
 * de l'entreprise. Cette copie n'a aucun fournisseur d'identite, et c'est
 * voulu : l'examinateur ne doit creer aucun compte, ne recevoir aucun courriel
 * et ne retenir aucun mot de passe. Il choisit un poste, il entre.
 *
 * Ce controleur n'existe que dans l'environnement de demonstration.
 */
final class ConnexionDemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route('/demo/connexion', name: 'demo_connexion', methods: ['GET'])]
    public function choisir(): Response
    {
        return $this->render('demo/connexion.html.twig', [
            'profils' => $this->em->getRepository(User::class)->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/demo/connexion/{id}', name: 'demo_connexion_entrer', methods: ['POST'])]
    public function entrer(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('demo_connexion', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('demo_connexion');
        }
        $utilisateur = $this->em->getRepository(User::class)->find($id);
        if (!$utilisateur instanceof User) {
            return $this->redirectToRoute('demo_connexion');
        }
        $this->security->login($utilisateur, 'remember_me', 'main');

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Entree directe par lien, pour un poste et un ecran donnes.
     *
     * Elle sert au jury : un lien du memoire peut ouvrir un dossier precis, vu
     * depuis le bon poste, sans que l'examinateur ait a se connecter puis a
     * naviguer. Elle n'existe que parce qu'il n'y a rien a proteger : la copie
     * ne contient aucune donnee reelle.
     */
    #[Route('/demo/entrer/{id}', name: 'demo_entrer', methods: ['GET'])]
    public function entrerDirect(int $id, Request $request): Response
    {
        $utilisateur = $this->em->getRepository(User::class)->find($id);
        if (!$utilisateur instanceof User) {
            return $this->redirectToRoute('demo_connexion');
        }
        $this->security->login($utilisateur, 'remember_me', 'main');
        $vers = (string) $request->query->get('vers', '');

        // Seuls des chemins internes sont acceptes : jamais une adresse externe.
        return $this->redirect(str_starts_with($vers, '/') && !str_starts_with($vers, '//')
            ? $vers
            : $this->generateUrl('app_dashboard'));
    }

    #[Route('/demo/changer-de-poste', name: 'demo_changer_poste', methods: ['GET'])]
    public function changer(): Response
    {
        $this->security->logout(false);

        return $this->redirectToRoute('demo_connexion');
    }
}
