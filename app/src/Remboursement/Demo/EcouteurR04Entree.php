<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * R04 A L'ENTREE — le premier des deux etages, pose AUTOUR du module.
 *
 * POURQUOI UN ECOUTEUR, ET PAS UNE LIGNE DANS LE CONTROLEUR. La validation du
 * depot est une methode privee du controleur herite, et le mandat est clair :
 * on n'y touche pas. Cet ecouteur travaille donc en amont, sur la requete : il
 * regarde les seules routes qui portent un IBAN, et si la cle est fausse il
 * repond a la place du controleur, qui n'est jamais appele.
 *
 * IL PARLE LA LANGUE DE CHAQUE ECRAN. Le formulaire de depot attend une reponse
 * JSON quand il travaille en arriere-plan, et des messages flashes sinon : la
 * reponse produite ici respecte ce contrat, route par route, pour que la
 * personne lise son erreur la ou elle l'attend et non sur une page d'erreur.
 *
 * CE QU'IL NE FAIT PAS. Il ne juge pas un IBAN vide, ni un IBAN hors structure :
 * le module herite s'en charge deja, et R04 ne parle que de la cle de controle.
 * Il ne bloque aucune route de lecture. Il ne remplace pas le garde-fou de
 * dernier ressort pose devant la generation du paiement : les dossiers anciens,
 * eux, n'ont jamais franchi cet ecouteur.
 */
final readonly class EcouteurR04Entree implements EventSubscriberInterface
{
    /**
     * Les routes qui font progresser un dossier avec un IBAN, et pour chacune :
     * le champ qui le porte, et la route ou renvoyer la personne.
     *
     * @var array<string, array{champ: string, retour: string, parametre: ?string}>
     */
    private const ROUTES = [
        'app_remboursement_deposer' => [
            'champ' => 'iban_client',
            'retour' => 'app_remboursement_deposer_formulaire',
            'parametre' => null,
        ],
        'app_remboursement_mon_dossier_corriger' => [
            'champ' => 'iban_client',
            'retour' => 'app_remboursement_mon_dossier',
            'parametre' => 'id',
        ],
        'app_remboursement_valider' => [
            'champ' => 'iban',
            'retour' => 'app_remboursement_dossier',
            'parametre' => 'id',
        ],
    ];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private LoggerInterface $journal,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Apres le routage (priorite 32) et apres le pare-feu (priorite 8) :
        // l'authentification et le controle d'acces gardent la main d'abord.
        return [KernelEvents::REQUEST => ['surRequete', 4]];
    }

    public function surRequete(RequestEvent $evenement): void
    {
        if (!$evenement->isMainRequest()) {
            return;
        }
        $requete = $evenement->getRequest();
        if (!$requete->isMethod('POST')) {
            return;
        }
        $route = (string) $requete->attributes->get('_route');
        if (!isset(self::ROUTES[$route])) {
            return;
        }

        $regle = self::ROUTES[$route];
        $saisi = $requete->request->get($regle['champ']);
        if (!\is_string($saisi)) {
            return;
        }
        if (null === ControleIbanMod97::reproche($saisi)) {
            return;
        }

        $this->journal->error('R04 : {message}', [
            'message' => ControleIbanMod97::MESSAGE,
            'route' => $route,
            'controle' => ControleIbanMod97::CODE,
        ]);

        $evenement->setResponse($this->refus($requete, $regle));
    }

    /**
     * Le refus, dans la forme que l'ecran attend.
     *
     * @param array{champ: string, retour: string, parametre: ?string} $regle
     */
    private function refus(Request $requete, array $regle): Response
    {
        $message = ControleIbanMod97::MESSAGE;

        if ($requete->isXmlHttpRequest()) {
            // Le formulaire de depot affiche `erreurs` sous le champ concerne :
            // on respecte son contrat plutot que d'inventer le notre.
            return new JsonResponse(['ok' => false, 'erreurs' => [$message]],
                Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Le message flashe, quand la session sait les porter. Sans session --
        // un appel technique, par exemple -- on ne perd rien d'utile : le refus
        // tient a la reponse, pas au message.
        $session = $requete->hasSession() ? $requete->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $message);
        }

        $parametres = [];
        if (null !== $regle['parametre']) {
            $parametres[$regle['parametre']] = $requete->attributes->get($regle['parametre']);
        }

        return new RedirectResponse($this->urls->generate($regle['retour'], $parametres));
    }
}
