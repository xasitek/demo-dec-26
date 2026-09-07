<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Enum\StatutSecretaire;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Repository\LettrageCommentaireRepository;
use App\Remboursement\Repository\LettrageRepository;
use App\Remboursement\Service\AppariementLettrage;
use App\Shared\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Onglet « Lettrage » du poste comptable : les paiements de remboursement partis en
 * banque (RBC // rachat sec, TP // trop-percu) qui ne sont PAS encore lettres en
 * compta. Reprend l'onglet « A lettrer » de l'ancien dashboard, mais lit le miroir
 * comptable directement au lieu d'un onglet Google Sheet.
 *
 * Lecture seule sur la compta ; la seule ecriture est le COMMENTAIRE de la comptable,
 * stocke a part pour survivre au resync nocturne du miroir.
 *
 * Meme garde que le reste du poste comptable (cf. RemboursementController).
 */
#[Route('/remboursement/lettrage')]
#[IsGranted(new Expression("is_granted('ROLE_COMPTABLE') or is_granted('ROLE_DIRECTEUR') or is_granted('ROLE_MANAGER')"))]
final class LettrageController extends AbstractController
{
    /** Longueur maximale d'un commentaire (meme borne que l'ancien dashboard). */
    private const LONGUEUR_MAX = 500;

    public function __construct(
        private readonly LettrageRepository $lettrage,
        private readonly LettrageCommentaireRepository $commentaires,
        private readonly DossierRepository $dossiers,
        private readonly AppariementLettrage $appariement,
    ) {
    }

    #[Route('', name: 'app_remboursement_lettrage', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $type = self::texte($request->query->get('type'));
        $retard = self::texte($request->query->get('retard'));
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $lignes = $this->lettrage->lignes($type, $retard, '' !== $q ? $q : null, $page);

        // Tranche de scroll infini : uniquement les lignes.
        if ($request->query->getBoolean('fragment')) {
            return $this->render('remboursement/_lettrage_rows.html.twig', ['lignes' => $lignes]);
        }

        $synthese = $this->lettrage->synthese($type, $retard, '' !== $q ? $q : null);

        return $this->render('remboursement/lettrage.html.twig', [
            'lignes' => $lignes,
            'synthese' => $synthese,
            'pages' => max(1, (int) ceil($synthese['total'] / LettrageRepository::PAR_PAGE)),
            'type' => $type,
            'retard' => $retard,
            'retards' => LettrageRepository::RETARDS,
            'q' => $q,
            'nbAVerifier' => $this->dossiers->compterParStatuts(StatutSecretaire::VERIFICATION->statuts()),
        ]);
    }

    /**
     * Pose / modifie / retire le commentaire de la comptable sur une ligne. Un texte
     * vide efface l'annotation. Repond le fragment de cellule a jour (remplacement en
     * place, sans rechargement de la liste).
     */
    // `__CLE__` est le gabarit d'URL passe au controleur Stimulus (meme convention que
    // les routes a panneau du module), qui le remplace par la vraie cle a l'appel.
    #[Route('/{cle}/commentaire', name: 'app_remboursement_lettrage_commentaire', requirements: ['cle' => '\d+|__CLE__'], methods: ['POST'])]
    public function commenter(int $cle, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lettrage_commentaire', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'erreur' => 'Session expirée : rechargez la page.'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->lettrage->existe($cle)) {
            return $this->json(['ok' => false, 'erreur' => 'Cette écriture n\'est plus à lettrer.'], Response::HTTP_NOT_FOUND);
        }

        $texte = trim((string) $request->request->get('commentaire', ''));
        if (mb_strlen($texte) > self::LONGUEUR_MAX) {
            return $this->json(['ok' => false, 'erreur' => sprintf('Commentaire trop long (%d caractères maximum).', self::LONGUEUR_MAX)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->commentaires->definir($cle, $texte, $this->auteur());

        $ligne = $this->lettrage->ligne($cle);
        if (null === $ligne) {
            return $this->json(['ok' => false, 'erreur' => 'Cette écriture n\'est plus à lettrer.'], Response::HTTP_NOT_FOUND);
        }

        return $this->render('remboursement/_lettrage_commentaire.html.twig', ['l' => $ligne]);
    }

    /**
     * Panneau « rapprochement » d'une ligne : le dossier paye le plus probable, avec
     * son score et les raisons. Lecture seule — rien n'est lettre automatiquement, la
     * comptable garde la decision.
     */
    #[Route('/{cle}/rapprochement', name: 'app_remboursement_lettrage_rapprochement', requirements: ['cle' => '\d+|__CLE__'], methods: ['GET'])]
    public function rapprochement(int $cle): Response
    {
        $ligne = $this->lettrage->ligne($cle);
        if (null === $ligne) {
            throw $this->createNotFoundException('Cette écriture n\'est plus à lettrer.');
        }

        $payes = $this->dossiers->payes();

        return $this->render('remboursement/_lettrage_rapprochement.html.twig', [
            'l' => $ligne,
            'candidat' => $this->appariement->meilleur($ligne, $payes),
            'nbPayes' => \count($payes),
        ]);
    }

    private function auteur(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getFullName() : $user?->getUserIdentifier();
    }

    private static function texte(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }
}
