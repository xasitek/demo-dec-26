<?php

declare(strict_types=1);

namespace App\Livraison\Controller;

use App\Livraison\Entity\Declaration;
use App\Livraison\Entity\Piece;
use App\Livraison\Repository\DeclarationRepository;
use App\Livraison\Repository\VehiculeALivrerRepository;
use App\Shared\Repository\EtablissementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ecran comptable du module Livraison : controler les declarations avant que le
 * dossier ne partre chez le loueur.
 *
 * Reprend l'ecran `/verification` du service Python, qui est bon et connu des
 * utilisatrices : la liste a gauche, le dossier avec ses pieces, l'apercu a droite.
 *
 * Difference de fond avec le circuit precedent : la conformite n'est plus une
 * valeur PAR DEFAUT. Le tableur ecrivait « Conforme » des qu'aucune anomalie
 * n'etait connue, si bien qu'un dossier jamais regarde et un dossier valide se
 * ressemblaient. Ici l'etat est explicite, horodate et attribue.
 *
 * Le controle automatique des pieces (12 regles, modele de langage) n'est pas
 * encore branche : la comptable arbitre seule, comme elle le fait deja. Ses
 * propositions viendront s'afficher ici, elles ne la remplaceront pas.
 */
#[Route('/livraison/controle')]
#[IsGranted('MODULE_LIVRAISON')]
final class ControleController extends AbstractController
{
    public function __construct(
        private readonly DeclarationRepository $declarations,
        private readonly VehiculeALivrerRepository $vehicules,
        private readonly EtablissementRepository $etablissements,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_livraison_controle', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $statut = (string) $request->query->get('statut', Declaration::STATUT_DEPOSEE);
        if (!\in_array($statut, [Declaration::STATUT_DEPOSEE, Declaration::STATUT_CONFORME, Declaration::STATUT_ANOMALIE], true)) {
            $statut = Declaration::STATUT_DEPOSEE;
        }

        return $this->render('livraison/controle.html.twig', [
            'statut' => $statut,
            'declarations' => $this->declarations->parStatut($statut),
            'comptes' => $this->declarations->comptesParStatut(),
            'libelles_etab' => $this->libellesEtablissements(),
        ]);
    }

    #[Route('/{id}', name: 'app_livraison_controle_dossier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dossier(Declaration $declaration): Response
    {
        // Les factures ne sont pas stockees : on les relit dans la vue, donc dans la
        // comptabilite. Elles peuvent avoir change depuis la declaration, et c'est
        // l'etat du jour qui compte pour juger le dossier.
        $factures = $this->vehicules->facturesDuVehicule($declaration->getIdentifiantVehicule());

        return $this->render('livraison/controle_dossier.html.twig', [
            'd' => $declaration,
            'factures' => $factures,
            'libelles_etab' => $this->libellesEtablissements(),
        ]);
    }

    /**
     * Sert une piece EN LIGNE (et non en telechargement) : la comptable la lit dans
     * le volet de droite sans quitter l'ecran.
     */
    #[Route('/piece/{id}', name: 'app_livraison_controle_piece', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function piece(Piece $piece): Response
    {
        $reponse = new Response($piece->getContenu());
        $reponse->headers->set('Content-Type', $piece->getMimeType());
        $reponse->headers->set('Content-Disposition', sprintf(
            'inline; filename="%s"',
            str_replace('"', '', $piece->getNomFichier()),
        ));
        // Contenu comptable : jamais mis en cache par un intermediaire.
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }

    #[Route('/{id}/arbitrer', name: 'app_livraison_controle_arbitrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function arbitrer(Request $request, Declaration $declaration): Response
    {
        if (!$this->isCsrfTokenValid('livraison_controle', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, merci de recommencer.');

            return $this->redirectToRoute('app_livraison_controle_dossier', ['id' => $declaration->getId()]);
        }

        $par = (string) $this->getUser()?->getUserIdentifier();
        $decision = (string) $request->request->get('decision', '');

        switch ($decision) {
            case 'conforme':
                $declaration->marquerConforme($par);
                $this->addFlash('success', sprintf('%s : dossier conforme.', $declaration->libelleVehicule()));
                break;

            case 'anomalie':
                $declaration->marquerAnomalie($par);
                $this->addFlash('success', sprintf('%s : anomalie signalée.', $declaration->libelleVehicule()));
                break;

            case 'rouvrir':
                $declaration->remettreEnAttente();
                $this->addFlash('success', sprintf('%s : remis en attente de contrôle.', $declaration->libelleVehicule()));
                break;

            default:
                $this->addFlash('error', 'Décision inconnue.');

                return $this->redirectToRoute('app_livraison_controle_dossier', ['id' => $declaration->getId()]);
        }

        $this->em->flush();

        return $this->redirectToRoute('app_livraison_controle');
    }

    /**
     * Code d'etablissement -> libelle lisible. Les ecrans comptables gardent le
     * referentiel COMPLET, desactives compris : sans quoi l'historique afficherait
     * des codes nus des qu'un etablissement ferme.
     *
     * @return array<string, string>
     */
    private function libellesEtablissements(): array
    {
        $libelles = [];
        foreach ($this->etablissements->findAll() as $etablissement) {
            $libelles[$etablissement->getCodeEtab()] = $etablissement->getLibelle();
        }

        return $libelles;
    }
}
