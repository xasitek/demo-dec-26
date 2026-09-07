<?php

declare(strict_types=1);

namespace App\Demo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Concentrateur temps reel INERTE, propre a l'environnement de demonstration.
 *
 * L'application publie normalement ses evenements sur un concentrateur Mercure.
 * En demonstration, il n'y en a aucun : ce controleur en tient lieu. Il accepte
 * les publications et ne les diffuse a personne, et il repond aux abonnements
 * par un flux vide qui ne se reconnecte pas en boucle.
 *
 * Consequence : toute la chaine applicative fonctionne sans modification, et
 * AUCUN evenement ne quitte la machine. C'est le principe applique partout dans
 * cette copie : on neutralise la sortie, jamais la logique.
 */
final class MercureInerteController extends AbstractController
{
    #[Route('/demo/mercure', name: 'demo_mercure', methods: ['GET', 'POST'])]
    public function __invoke(): Response
    {
        if ('POST' === $this->container->get('request_stack')->getCurrentRequest()?->getMethod()) {
            // Une publication reussie renvoie un identifiant d'evenement.
            return new Response('urn:uuid:'.bin2hex(random_bytes(16)), 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // Abonnement : un flux valide, definitivement vide.
        $reponse = new StreamedResponse(static function (): void {
            echo ": environnement de demonstration, aucun evenement temps reel\n\n";
            flush();
        });
        $reponse->headers->set('Content-Type', 'text/event-stream');
        $reponse->headers->set('Cache-Control', 'no-cache');
        $reponse->headers->set('X-Accel-Buffering', 'no');

        return $reponse;
    }
}
