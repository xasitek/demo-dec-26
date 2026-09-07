<?php

declare(strict_types=1);

namespace App\Demo\Controller;

use App\Demo\Visites\JournalVisites;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LE COMPTEUR DE VISITES, POUR L'AUTEUR SEUL.
 *
 * POURQUOI UNE URL A CLE, ET PAS UN ROLE. Toute la demonstration vit derriere
 * UNE seule porte partagee, et n'importe quel visiteur peut ensuite prendre
 * n'importe quel poste — c'est le principe meme de la demonstration. Un role
 * ne protegerait donc rien ici. La clef, elle, ne figure sur aucune page,
 * aucun menu, aucun plan du site : seul celui qui la connait ouvre l'ecran.
 *
 * ELLE SE CHANGE SANS TOUCHER AU CODE : variable d'environnement
 * `DEMO_VISITES_CLE`. Videe, l'ecran devient inaccessible, ce qui est le bon
 * comportement par defaut si quelqu'un deploie cette copie sans y penser.
 *
 * CE QUE L'ECRAN MONTRE. Des comptes et des horodatages, jamais une identite :
 * ni adresse IP, ni nom, ni identifiant de session. Voir le commentaire de
 * `JournalVisites`, qui dit ce qui est stocke et ce qui ne l'est pas.
 */
final class VisitesController extends AbstractController
{
    public function __construct(
        private readonly JournalVisites $journal,
        #[Autowire('%env(default::DEMO_VISITES_CLE)%')]
        private readonly ?string $cle,
    ) {
    }

    #[Route('/demo/visites/{cle}', name: 'demo_visites', methods: ['GET'],
        requirements: ['cle' => '[A-Za-z0-9_-]{8,120}'])]
    public function __invoke(string $cle): Response
    {
        $attendue = (string) $this->cle;
        if ('' === $attendue || !hash_equals($attendue, $cle)) {
            // Introuvable, et non « interdit » : un ecran dont on ignore la clef
            // n'a pas a confirmer son existence.
            throw $this->createNotFoundException();
        }

        return $this->render('demo/visites.html.twig', [
            'resume' => $this->journal->resume(),
            'visites' => $this->journal->visites(100),
            'evenements' => $this->journal->evenements(200),
        ]);
    }
}
