<?php

declare(strict_types=1);

namespace App\Demo\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Porte d'acces de la demonstration.
 *
 * Un identifiant et un mot de passe sont exiges avant tout acces a la
 * plateforme. Ce n'est pas une securite au sens ou il y aurait des donnees a
 * proteger : il n'y en a aucune. C'est une porte, et elle sert a trois choses.
 * Eviter qu'un visiteur arrive ici par hasard. Donner un cadre professionnel.
 * Et ne pas exposer la plateforme au premier qui reçoit le lien.
 *
 * La verification est reelle : le mot de passe n'est jamais stocke en clair,
 * la session vit cote serveur, elle expire, et la deconnexion la detruit.
 *
 * Cette porte est INDEPENDANTE du choix de poste. La porte dit « vous pouvez
 * entrer » ; le poste dit « voici avec quels yeux vous regardez ».
 */
final class PorteAcces implements EventSubscriberInterface
{
    public const CLE_OUVERTE = 'demo_acces_ouvert';
    public const CLE_EXPIRE = 'demo_acces_expire_le';
    public const CLE_ESSAIS = 'demo_acces_essais';
    public const CLE_BLOQUE = 'demo_acces_bloque_jusqua';

    /** Chemins joignables sans avoir franchi la porte. */
    private const LIBRES = [
        '/acces',
        '/assets/',
        '/favicon',
        '/demo/mercure',
        '/_wdt',
        '/_profiler',
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(int:DEMO_ACCES_DUREE_MINUTES)%')]
        private readonly int $dureeMinutes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorite 64 : APRES le demarrage de la session (128), donc la session
        // existe ; et AVANT le pare-feu applicatif (8), donc la porte se ferme
        // avant que le moindre controleur ne soit sollicite.
        return [KernelEvents::REQUEST => ['verifier', 64]];
    }

    public function verifier(RequestEvent $evenement): void
    {
        if (!$evenement->isMainRequest()) {
            return;
        }
        $requete = $evenement->getRequest();
        $chemin = $requete->getPathInfo();

        foreach (self::LIBRES as $libre) {
            if (str_starts_with($chemin, $libre)) {
                return;
            }
        }

        $session = $requete->getSession();
        $ouvert = true === $session->get(self::CLE_OUVERTE);
        $expire = (int) $session->get(self::CLE_EXPIRE, 0);

        if ($ouvert && $expire > time()) {
            // Session glissante : la duree repart a chaque page consultee.
            $session->set(self::CLE_EXPIRE, time() + $this->dureeMinutes * 60);

            return;
        }

        if ($ouvert) {
            $session->remove(self::CLE_OUVERTE);
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('acces_erreur', 'Votre session de démonstration a expiré. Reconnectez-vous.');
            }
        }

        $evenement->setResponse(new RedirectResponse(
            $this->urls->generate('demo_acces', 'GET' === $requete->getMethod() && '/' !== $chemin ? ['vers' => $chemin] : [])
        ));
    }
}
