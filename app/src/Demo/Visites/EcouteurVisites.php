<?php

declare(strict_types=1);

namespace App\Demo\Visites;

use App\Demo\Security\PorteAcces;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CE QUI COMPTE LES VISITES, pose autour de la porte et de rien d'autre.
 *
 * OU IL SE PLACE, ET POURQUOI LA. Juste APRES la porte d'acces (priorite 64),
 * donc a 63 : une requete qui n'a pas franchi la porte n'est pas une visite,
 * c'est un inconnu qu'on renvoie. Et si la porte a deja pose une reponse, on
 * ne compte rien.
 *
 * IL N'A PAS FALLU TOUCHER AU CONTROLEUR DE LA PORTE. La premiere visite se
 * reconnait a un fait simple : la session est ouverte et ne porte pas encore
 * d'empreinte de visite. On la lui donne, et on date l'entree. La porte fait
 * migrer la session a l'ouverture, si bien qu'une empreinte n'est jamais
 * heritee d'avant l'authentification.
 *
 * CE QU'IL NE COMPTE PAS. Les fichiers d'habillage, les flux temps reel, les
 * appels du profiler, les images. Une visite se mesure en pages consultees,
 * pas en requetes techniques.
 */
final readonly class EcouteurVisites implements EventSubscriberInterface
{
    /** Ce qui n'est pas une page : on ne le compte pas. */
    private const IGNORES = [
        '/assets/', '/favicon', '/demo/mercure', '/_wdt', '/_profiler',
        '/bundles/', '/donnees/',
    ];

    public function __construct(
        private JournalVisites $journal,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['surRequete', 63]];
    }

    public function surRequete(RequestEvent $evenement): void
    {
        if (!$evenement->isMainRequest() || $evenement->hasResponse()) {
            return;
        }
        $requete = $evenement->getRequest();
        $chemin = $requete->getPathInfo();

        foreach (self::IGNORES as $ignore) {
            if (str_starts_with($chemin, $ignore)) {
                return;
            }
        }

        if (!$requete->hasSession()) {
            return;
        }
        $session = $requete->getSession();
        if (true !== $session->get(PorteAcces::CLE_OUVERTE)) {
            return;
        }

        $empreinte = $session->get(JournalVisites::CLE_EMPREINTE);
        if (!\is_string($empreinte) || 16 !== \strlen($empreinte)) {
            $empreinte = JournalVisites::empreinteNeuve();
            $session->set(JournalVisites::CLE_EMPREINTE, $empreinte);
            $this->journal->entree($empreinte, $requete->headers->get('User-Agent'));
        }

        $this->journal->page($empreinte, $chemin);

        // La prise de poste est le fait le plus parlant : elle dit qu'on n'a pas
        // seulement ouvert la page d'accueil, on est entre dans un metier.
        if (str_starts_with($chemin, '/demo/voir-comme/')) {
            $persona = trim(substr($chemin, \strlen('/demo/voir-comme/')), '/');
            if ('' !== $persona && 1 === preg_match('/^[a-z0-9-]{1,60}$/', $persona)) {
                $this->journal->poste($empreinte, $persona);
            }
        }
    }
}
