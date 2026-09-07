<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\FusionPieces;
use App\Remboursement\Service\WorkflowRemboursement;
use App\Shared\Repository\EtablissementContactRepository;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Actions du DIRECTEUR depuis l'e-mail, SANS connexion : chaque lien est une URL
 * SIGNEE (UriSigner + secret app, avec expiration). La signature vaut authentification
 * pour ce dossier / ce directeur. Aucune donnee sensible n'est exposee (juste un id).
 *
 * Prefixe /remboursement/directeur laisse PUBLIC dans security.yaml (methods GET).
 */
#[Route('/remboursement/directeur')]
final class DirecteurController extends AbstractController
{
    public function __construct(
        private readonly UriSigner $signer,
        private readonly DossierRepository $dossiers,
        private readonly WorkflowRemboursement $workflow,
        private readonly EtablissementContactRepository $contacts,
        private readonly FusionPieces $fusion,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/{id}/valider', name: 'app_remboursement_directeur_valider', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function valider(int $id, Request $request): Response
    {
        $this->verifierSignature($request);
        $dossier = $this->trouver($id);

        try {
            $this->workflow->appliquer($dossier, 'valider_directeur', 'Directeur (e-mail)', null, false, true, true);
            // Dossier valide -> generation auto des fichiers comptables (async) + passage en paiement.
            $this->bus->dispatch(new GenererFichiers($id));

            return $this->confirmation('Dossier validé', sprintf('Le dossier %s a bien été validé.', $dossier->getReference()), 'valide');
        } catch (DomainException) {
            return $this->confirmation('Déjà traité', sprintf('Le dossier %s a déjà été traité, aucune action effectuée.', $dossier->getReference()), 'neutre');
        }
    }

    #[Route('/{id}/refuser', name: 'app_remboursement_directeur_refuser', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function refuser(int $id, Request $request): Response
    {
        $this->verifierSignature($request);
        $dossier = $this->trouver($id);

        try {
            $this->workflow->appliquer($dossier, 'refuser_directeur', 'Directeur (e-mail)', 'Refusé par le directeur.', false, true, true);

            return $this->confirmation('Dossier refusé', sprintf('Le dossier %s a été refusé.', $dossier->getReference()), 'refuse');
        } catch (DomainException) {
            return $this->confirmation('Déjà traité', sprintf('Le dossier %s a déjà été traité, aucune action effectuée.', $dossier->getReference()), 'neutre');
        }
    }

    /**
     * Valide TOUS les dossiers encore en attente pour ce directeur (identifie par son
     * e-mail, dans la signature). Les dossiers deja refuses ne sont PAS en attente ->
     * naturellement epargnes.
     */
    #[Route('/tout-valider', name: 'app_remboursement_directeur_tout_valider', methods: ['GET'])]
    public function toutValider(Request $request): Response
    {
        $this->verifierSignature($request);
        $email = strtolower(trim((string) $request->query->get('email', '')));
        if ('' === $email) {
            throw $this->createNotFoundException();
        }

        $map = $this->contacts->directeursParEtablissement(); // code etab -> email directeur
        $n = 0;
        foreach ($this->dossiers->parStatut(DossierStatut::A_VALIDER_DIRECTEUR, 500) as $dossier) {
            $code = (string) $dossier->getEtablissementCode();
            if ('' === $code || strtolower((string) ($map[$code] ?? '')) !== $email) {
                continue;
            }
            try {
                $this->workflow->appliquer($dossier, 'valider_directeur', 'Directeur (e-mail, tout valider)', null, false, true, true);
                $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()));
                ++$n;
            } catch (DomainException) {
                // deja traite : on ignore
            }
        }

        return $this->confirmation('Dossiers validés', sprintf('%d dossier(s) validé(s). Les dossiers refusés n\'ont pas été modifiés.', $n), 'valide');
    }

    /** PDF fusionne de toutes les pieces du dossier (consultation avant validation). */
    #[Route('/{id}/pieces', name: 'app_remboursement_directeur_pieces', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pieces(int $id, Request $request): Response
    {
        $this->verifierSignature($request);
        $dossier = $this->trouver($id);

        $pdf = $this->fusion->pdf($dossier);
        if ('' === $pdf) {
            return $this->confirmation('Aucune pièce', 'Ce dossier ne contient aucune pièce consultable.', 'neutre');
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s-pieces.pdf"', $dossier->getReference()),
        ]);
    }

    private function verifierSignature(Request $request): void
    {
        if (!$this->signer->checkRequest($request)) {
            throw $this->createAccessDeniedException('Lien invalide ou expiré.');
        }
    }

    private function trouver(int $id): Dossier
    {
        $dossier = $this->dossiers->find($id);
        if (!$dossier instanceof Dossier) {
            throw $this->createNotFoundException('Dossier introuvable.');
        }

        return $dossier;
    }

    private function confirmation(string $titre, string $message, string $ton): Response
    {
        return $this->render('remboursement/directeur/confirmation.html.twig', [
            'titre' => $titre,
            'message' => $message,
            'ton' => $ton,
        ]);
    }
}
