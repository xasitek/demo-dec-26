<?php

declare(strict_types=1);

namespace App\Recouvrement\Controller;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Repository\RelanceEnvoiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Telechargement PUBLIC du document complet (releve + factures) d'une relance
 * dont les pieces etaient trop volumineuses pour etre jointes a l'e-mail.
 *
 * Acces par token non devinable (bin2hex(random_bytes(16)) = 32 hex), sans
 * authentification : le client clique le lien recu dans sa relance. Le PDF a ete
 * pre-genere et stocke par la machine interne (seule a avoir Progiciel + Ghostscript)
 * dans relance_envoi.courrier_pdf ; le web le sert tel quel.
 */
final class TelechargementController extends AbstractController
{
    #[Route(
        '/recouvrement/telecharger/{token}',
        name: 'app_recouvrement_telecharger',
        requirements: ['token' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function telecharger(string $token, RelanceEnvoiRepository $relances): Response
    {
        $relance = $relances->findOneBy(['token' => $token]);
        if (!$relance instanceof RelanceEnvoi) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        $pdf = $relance->getCourrierPdf();
        if (null === $pdf) {
            throw $this->createNotFoundException('Document indisponible.');
        }

        $nom = sprintf('Factures-%s.pdf', (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $relance->getCompteCode()));

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // Le lien de l'e-mail dit "Telecharger mes factures" -> attachment (download).
            'Content-Disposition' => sprintf('attachment; filename="%s"', $nom),
            // Taille connue -> le navigateur affiche une vraie barre de progression
            // pendant le transfert (le PDF est deja genere, aucun calcul a l'ouverture).
            'Content-Length' => (string) \strlen($pdf),
            // Document nominatif : pas de mise en cache par des intermediaires.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
