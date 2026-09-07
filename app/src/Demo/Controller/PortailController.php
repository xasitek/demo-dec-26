<?php

declare(strict_types=1);

namespace App\Demo\Controller;

use App\Demo\Outil;
use App\Demo\Persona;
use App\Demo\Service\IndicateursPortail;
use App\Shared\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le parcours du jury : choisir l'outil, choisir le poste, utiliser l'outil.
 *
 * Trois niveaux, et le troisieme est l'application elle-meme. On n'arrive
 * jamais sur un ecran de connexion : les postes sont des profils prepares,
 * et un clic ouvre leur session.
 */
final class PortailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly IndicateursPortail $indicateurs,
        private readonly KernelInterface $kernelDemo,
    ) {
    }

    /** Niveau 1 : les dix outils, dans la chaine qui les relie. */
    #[Route('/demo', name: 'demo_portail', methods: ['GET'])]
    public function portail(): Response
    {
        $chiffres = [];
        foreach (Outil::tous() as $o) {
            $chiffres[$o->cle] = $this->indicateurs->pour($o->indicateur);
        }

        return $this->render('demo/portail.html.twig', [
            'etapes' => Outil::ETAPES,
            'groupes' => Outil::parEtape(),
            'chiffres' => $chiffres,
        ]);
    }

    /** Niveau 2 : avec quel poste decouvrir cet outil. */
    #[Route('/demo/outil/{cle}', name: 'demo_outil_profils', methods: ['GET'])]
    public function profils(string $cle): Response
    {
        $outil = Outil::parCle($cle);
        if (null === $outil) {
            return $this->redirectToRoute('demo_portail');
        }

        return $this->render('demo/profils.html.twig', [
            'outil' => $outil,
            'personas' => $outil->personas(),
        ]);
    }

    /** Niveau 3 : on entre dans l'application, avec ce poste. */
    #[Route('/demo/outil/{cle}/entrer/{persona}', name: 'demo_outil_entrer', methods: ['GET'])]
    public function entrer(string $cle, string $persona, Request $request): Response
    {
        $outil = Outil::parCle($cle);
        $poste = Persona::tryFrom($persona);
        $vue = null !== $outil && null !== $poste ? $outil->vuePour($poste) : null;
        if (null === $outil || null === $poste || null === $vue) {
            return $this->redirectToRoute('demo_portail');
        }
        $this->connecter($poste);
        $request->getSession()->set('demo_outil', $outil->cle);

        return $this->redirect($vue);
    }

    /**
     * « Voir comme… » : le meme ecran, un autre poste.
     *
     * C'est le geste le plus parlant de la demonstration : le dossier ne change
     * pas, seule change la personne qui le regarde, et donc ce qu'elle voit et
     * ce qu'elle peut faire.
     */
    #[Route('/demo/voir-comme/{persona}', name: 'demo_voir_comme', methods: ['GET'])]
    public function voirComme(string $persona, Request $request): Response
    {
        $poste = Persona::tryFrom($persona);
        if (null === $poste) {
            return $this->redirectToRoute('demo_portail');
        }
        $this->connecter($poste);
        $vers = (string) $request->query->get('vers', '');

        return $this->redirect(str_starts_with($vers, '/') && !str_starts_with($vers, '//')
            ? $vers : $this->generateUrl('demo_portail'));
    }

    /** Ecran d'attente d'un outil dont le lot n'est pas encore livre. */
    #[Route('/demo/outil/{cle}/ecran', name: 'demo_outil_ecran', methods: ['GET'])]
    public function ecranAVenir(string $cle): Response
    {
        $outil = Outil::parCle($cle);
        if (null === $outil) {
            return $this->redirectToRoute('demo_portail');
        }

        return $this->render('demo/outil_a_venir.html.twig', [
            'outil' => $outil,
            'chiffres' => $this->indicateurs->pour($outil->indicateur),
        ]);
    }

    /**
     * Remet la demonstration dans son etat initial.
     *
     * Le jury peut donc tout essayer : valider, refuser, corriger, sans crainte
     * d'abimer quoi que ce soit pour le suivant.
     */
    #[Route('/demo/reinitialiser', name: 'demo_reinitialiser', methods: ['GET'])]
    public function reinitialiser(Request $request): Response
    {
        $noyau = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernelDemo);
        $noyau->setAutoExit(false);
        foreach ([
            ['command' => 'app:remboursement:donnees-demo', '--force' => true],
            ['command' => 'app:creances:seed-demo', '--confirm' => true],
        ] as $appel) {
            $noyau->run(new \Symfony\Component\Console\Input\ArrayInput($appel),
                new \Symfony\Component\Console\Output\NullOutput());
        }
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', 'Démonstration réinitialisée.');
        }

        return $this->redirectToRoute('demo_portail');
    }

    private function connecter(Persona $poste): void
    {
        $u = $this->em->getRepository(User::class)->findOneBy(['email' => $poste->email()]);
        if ($u instanceof User) {
            $this->security->login($u, 'remember_me', 'main');
        }
    }
}
