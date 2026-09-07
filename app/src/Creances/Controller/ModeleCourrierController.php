<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\ModeleCourrier;
use App\Creances\Enum\ModeleFormat;
use App\Creances\Repository\ModeleCourrierRepository;
use App\Shared\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use Twig\Environment;

/**
 * CRUD des modeles de courrier (email ou postal) + apercu HTML.
 *
 * Acces ROLE_COMPTABLE. CSRF sur tous les POST.
 *
 * Securite : le rendu Twig se fait avec un contexte demo statique. Pour des
 * raisons evidentes, ne PAS rendre des modeles utilisateurs dans le Twig
 * de l'application avec des donnees reelles sans sandbox. Le sandbox sera
 * ajoute dans la phase d'envoi (PHASE 8) qui rendra avec un vrai
 * destinataire.
 */
#[Route('/creances')]
#[IsGranted('ROLE_COMPTABLE')]
final class ModeleCourrierController extends AbstractController
{
    public function __construct(
        private readonly ModeleCourrierRepository $modeles,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/modeles', name: 'app_creances_modeles', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('creances/modeles.html.twig', [
            'modeles' => $this->modeles->findToutes(),
        ]);
    }

    #[Route('/modele/{id}', name: 'app_creances_modele_voir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function voir(int $id, Request $request): Response
    {
        $modele = $this->modeles->find($id);
        if (null === $modele) {
            throw new NotFoundHttpException('Modele introuvable.');
        }

        $apercu = $request->query->getBoolean('apercu');

        return $this->render('creances/modele_detail.html.twig', [
            'modele' => $modele,
            'apercu' => $apercu ? $this->rendreApercu($modele) : null,
        ]);
    }

    #[Route('/modele', name: 'app_creances_modele_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_modele_creer');
        $code = self::texte($request->request->get('code'));
        $libelle = self::texte($request->request->get('libelle'));
        $corps = self::texte($request->request->get('corps_html'));
        if (null === $code || null === $libelle || null === $corps) {
            $this->addFlash('warning', 'Code, libelle et corps sont obligatoires.');

            return $this->redirectToRoute('app_creances_modeles');
        }
        $format = ModeleFormat::tryFrom((string) $request->request->get('format', 'email')) ?? ModeleFormat::Email;
        $sujet = self::texte($request->request->get('sujet'));
        $langue = self::texte($request->request->get('langue')) ?? 'fr';
        $auteur = $this->utilisateurCourant();

        $m = new ModeleCourrier($code, $libelle, $corps, $auteur, $format, $sujet, $langue);
        $this->modeles->save($m);
        $this->addFlash('success', 'Modele cree.');

        return $this->redirectToRoute('app_creances_modele_voir', ['id' => $m->getId()]);
    }

    #[Route('/modele/{id}/modifier', name: 'app_creances_modele_modifier', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function modifier(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_modele_modifier_'.$id);
        $m = $this->modeles->find($id);
        if (null === $m) {
            throw new NotFoundHttpException('Modele introuvable.');
        }
        $libelle = self::texte($request->request->get('libelle'));
        $corps = self::texte($request->request->get('corps_html'));
        if (null === $libelle || null === $corps) {
            $this->addFlash('warning', 'Libelle et corps obligatoires.');

            return $this->redirectToRoute('app_creances_modele_voir', ['id' => $id]);
        }
        $m->setLibelle($libelle);
        $m->setCorpsHtml($corps);
        $m->setDescription(self::texte($request->request->get('description')));
        $m->setSujet(self::texte($request->request->get('sujet')));
        $m->setFormat(ModeleFormat::tryFrom((string) $request->request->get('format', $m->getFormat()->value)) ?? $m->getFormat());
        $m->setLangue(self::texte($request->request->get('langue')) ?? $m->getLangue());
        $m->setActif($request->request->getBoolean('actif'));
        $this->modeles->save($m);
        $this->addFlash('success', 'Modele mis a jour.');

        return $this->redirectToRoute('app_creances_modele_voir', ['id' => $id]);
    }

    /**
     * Vue impression d'un courrier issu d'un envoi reel : rendu A4 print-only
     * (CSS @media print). L'utilisateur fait Ctrl+P / Cmd+P pour generer un
     * PDF via la boite de dialogue du navigateur. Pas de fichier serveur,
     * pas de dependance externe.
     */
    #[Route('/envoi/{id}/print', name: 'app_creances_envoi_print', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function envoiPrint(int $id, \App\Creances\Repository\RelanceEnvoiRepository $envois): Response
    {
        $envoi = $envois->find($id);
        if (null === $envoi) {
            throw new NotFoundHttpException('Envoi introuvable.');
        }

        return $this->render('creances/courrier_print.html.twig', [
            'envoi' => $envoi,
        ]);
    }

    #[Route('/modele/{id}/supprimer', name: 'app_creances_modele_supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_modele_supprimer_'.$id);
        $m = $this->modeles->find($id);
        if (null === $m) {
            throw new NotFoundHttpException('Modele introuvable.');
        }
        $this->modeles->remove($m);
        $this->addFlash('success', 'Modele supprime.');

        return $this->redirectToRoute('app_creances_modeles');
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function rendreApercu(ModeleCourrier $modele): string
    {
        // Donnees demo pour visualiser le rendu. Le rendu reel utilisera les
        // vraies donnees du tiers + ecritures (PHASE 8).
        $contexte = [
            'tiers' => [
                'civilite' => 'Monsieur',
                'prenom' => 'Kirdan',
                'nom' => 'VELKUR',
                'adresse' => '12 rue des Synthes',
                'code_postal' => '67000',
                'ville' => 'OSKNEM',
                'email' => 'kirdan.velkur@demonstration.invalid',
            ],
            'compte_code' => '394074',
            'montant_total' => 1248.50,
            'date_aujourdhui' => date('d/m/Y'),
            'ecritures' => [
                ['numpiece' => 'V1/341 25/04-00061', 'date' => '04/04/2025', 'montant' => 310.00, 'retard' => '>240'],
                ['numpiece' => 'V1/352 25/05-00012', 'date' => '15/05/2025', 'montant' => 938.50, 'retard' => '>120'],
            ],
            'signature' => 'Service Comptabilite Groupe Synthauto',
        ];

        try {
            $template = $this->twig->createTemplate($modele->getCorpsHtml(), 'modele_courrier_'.$modele->getId());

            return $template->render($contexte);
        } catch (Throwable $e) {
            return '<div class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-700">Erreur de rendu : '.htmlspecialchars($e->getMessage(), \ENT_QUOTES, 'UTF-8').'</div>';
        }
    }

    private function verifierCsrf(Request $request, string $intention): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intention, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function utilisateurCourant(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }
}
