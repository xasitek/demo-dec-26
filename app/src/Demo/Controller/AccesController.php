<?php

declare(strict_types=1);

namespace App\Demo\Controller;

use App\Demo\Security\PorteAcces;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'ecran d'acces : identifiant, mot de passe, rien d'autre.
 *
 * Pas de creation de compte, pas de mot de passe oublie, pas de courriel, pas
 * de second facteur. Un seul compte, transmis separement du lien.
 *
 * Le mot de passe n'est pas dans le code : seule son empreinte l'est, et la
 * comparaison se fait en temps constant. C'est peu de chose, mais un ecran
 * d'acces qui compare deux chaines en clair n'est pas un ecran d'acces.
 */
final class AccesController extends AbstractController
{
    /** Au-dela, on ferme quelques minutes : une porte ne se force pas au hasard. */
    private const ESSAIS_MAX = 8;
    private const BLOCAGE_MINUTES = 5;

    public function __construct(
        #[Autowire('%env(DEMO_ACCES_IDENTIFIANT)%')]
        private readonly string $identifiantAttendu,
        #[Autowire('%env(base64:DEMO_ACCES_EMPREINTE_B64)%')]
        private readonly string $empreinte,
        #[Autowire('%env(int:DEMO_ACCES_DUREE_MINUTES)%')]
        private readonly int $dureeMinutes,
    ) {
    }

    #[Route('/acces', name: 'demo_acces', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $vers = (string) $request->get('vers', '');
        $vers = str_starts_with($vers, '/') && !str_starts_with($vers, '//') ? $vers : '/demo';

        if (true === $session->get(PorteAcces::CLE_OUVERTE)) {
            return $this->redirect($vers);
        }

        $bloqueJusqua = (int) $session->get(PorteAcces::CLE_BLOQUE, 0);
        $bloque = $bloqueJusqua > time();

        if ($request->isMethod('POST') && !$bloque) {
            $identifiant = trim((string) $request->request->get('identifiant', ''));
            $motDePasse = (string) $request->request->get('mot_de_passe', '');

            $identifiantOk = hash_equals($this->identifiantAttendu, $identifiant);
            $motDePasseOk = '' !== $this->empreinte && password_verify($motDePasse, $this->empreinte);

            if ($identifiantOk && $motDePasseOk) {
                // Nouvelle session : on ne recycle jamais l'identifiant de session
                // qui a servi avant l'authentification.
                $session->migrate(true);
                $session->set(PorteAcces::CLE_OUVERTE, true);
                $session->set(PorteAcces::CLE_EXPIRE, time() + $this->dureeMinutes * 60);
                $session->remove(PorteAcces::CLE_ESSAIS);

                return $this->redirect($vers);
            }

            $essais = 1 + (int) $session->get(PorteAcces::CLE_ESSAIS, 0);
            $session->set(PorteAcces::CLE_ESSAIS, $essais);
            if ($essais >= self::ESSAIS_MAX) {
                $session->set(PorteAcces::CLE_BLOQUE, time() + self::BLOCAGE_MINUTES * 60);
                $session->set(PorteAcces::CLE_ESSAIS, 0);
                $bloque = true;
            }
            // Temporisation croissante : elle ne gene pas un lecteur legitime.
            usleep(min(600000, 120000 * $essais));
            $this->addFlash('acces_erreur', $bloque
                ? sprintf('Trop de tentatives. Nouvel essai possible dans %d minutes.', self::BLOCAGE_MINUTES)
                : 'Identifiant ou mot de passe incorrect.');
        }

        return $this->render('demo/acces.html.twig', [
            'vers' => $vers,
            'bloque' => $bloque,
            'minutes' => $this->dureeMinutes,
        ]);
    }

    #[Route('/acces/quitter', name: 'demo_acces_quitter', methods: ['GET'])]
    public function quitter(Request $request): Response
    {
        $request->getSession()->invalidate();

        return $this->redirectToRoute('demo_acces');
    }
}
