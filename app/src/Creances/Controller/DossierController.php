<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Entity\Dossier;
use App\Creances\Entity\DossierEcriture;
use App\Creances\Entity\Echeance;
use App\Creances\Enum\DossierType;
use App\Creances\Repository\CreancesRepository;
use App\Creances\Repository\DossierEcritureRepository;
use App\Creances\Repository\DossierRepository;
use App\Creances\Repository\EcheanceRepository;
use App\Creances\Service\RecouvrementNotifier;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des dossiers metier (echeancier / litige / contentieux).
 *
 * Reserve a `ROLE_COMPTABLE`. CSRF sur tous les POST. Toutes les
 * publications Mercure passent par RecouvrementNotifier (best-effort).
 */
#[Route('/creances')]
#[IsGranted('ROLE_COMPTABLE')]
final class DossierController extends AbstractController
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierEcritureRepository $dossierEcritures,
        private readonly EcheanceRepository $echeances,
        private readonly CreancesRepository $creances,
        private readonly EntityManagerInterface $em,
        private readonly RecouvrementNotifier $notifier,
    ) {
    }

    /**
     * Page de detail d'un dossier (litige / echeancier / contentieux) avec
     * ecritures liees + echeances (si echeancier) + formulaires de gestion.
     */
    #[Route('/dossier/{id}', name: 'app_creances_dossier_voir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function voir(int $id): Response
    {
        $dossier = $this->dossiers->find($id);
        if (null === $dossier) {
            throw new NotFoundHttpException('Dossier introuvable.');
        }

        // Ecritures encore ouvertes sur le compte mais pas deja dans le dossier
        // (pour la liste de selection a ajouter).
        $deja = $this->dossierEcritures->listerEcrituresLieesAuCompte($dossier->getCompteCode());
        $ecrituresDuTiers = $this->creances->creancesOuvertesDuTiers($dossier->getCompteCode());
        $candidats = [];
        foreach ($ecrituresDuTiers as $row) {
            $d = is_array($row['donnees']) ? $row['donnees'] : (is_string($row['donnees']) ? (json_decode($row['donnees'], true) ?: []) : []);
            $numero = isset($d['numero']) && is_string($d['numero']) ? $d['numero'] : '';
            if ('' === $numero || in_array($numero, $deja, true)) {
                continue;
            }
            $candidats[] = [
                'numero' => $numero,
                'piece' => (string) ($d['numpiece'] ?? ''),
                'date' => (string) ($d['dateecriture'] ?? ''),
                'montant' => (float) ($d['Montant (valeur absolue)'] ?? 0),
                'retard' => (string) ($d['retard'] ?? ''),
            ];
        }

        $tiers = $this->creances->tiers($dossier->getCompteCode());

        return $this->render('creances/dossier_detail.html.twig', [
            'dossier' => $dossier,
            'tiers' => $tiers,
            'candidats' => $candidats,
        ]);
    }

    /**
     * Cree un dossier rattache a un tiers. Le formulaire est dans la fiche
     * tiers (onglet Dossiers).
     */
    #[Route('/tiers/{code}/dossier', name: 'app_creances_dossier_creer', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_-]+'])]
    public function creer(string $code, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_dossier_creer_'.$code);
        $type = DossierType::tryFrom((string) $request->request->get('type', ''));
        $libelle = self::texte($request->request->get('libelle'));
        if (null === $type || null === $libelle) {
            $this->addFlash('warning', 'Type et libelle sont obligatoires.');

            return $this->redirectFiche($code);
        }
        $description = self::texte($request->request->get('description'));
        $dateDebut = self::date($request->request->get('date_debut')) ?? new DateTimeImmutable('today');
        $dateResolutionCible = self::date($request->request->get('date_resolution_cible'));
        $auteur = $this->utilisateurCourant();

        $dossier = new Dossier(
            $code,
            $type,
            $libelle,
            $dateDebut,
            $auteur,
            $auteur,
            $description,
            $dateResolutionCible,
        );
        $this->dossiers->save($dossier);

        $this->notifier->notifierTiers($code, 'dossier_cree', ['type' => $type->value, 'dossier_id' => $dossier->getId()]);
        $this->addFlash('success', 'Dossier cree. Ajoute maintenant les ecritures concernees.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $dossier->getId()]);
    }

    /**
     * Cloture un dossier avec un resultat libre + commentaire.
     */
    #[Route('/dossier/{id}/cloturer', name: 'app_creances_dossier_cloturer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloturer(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_dossier_cloturer_'.$id);
        $dossier = $this->dossiers->find($id);
        if (null === $dossier) {
            throw new NotFoundHttpException('Dossier introuvable.');
        }
        $resultat = self::texte($request->request->get('resultat')) ?? 'cloture';
        $commentaire = self::texte($request->request->get('commentaire'));

        $dossier->cloturer($resultat, $commentaire);
        $this->dossiers->save($dossier);

        $this->notifier->notifierTiers($dossier->getCompteCode(), 'dossier_cloture', ['dossier_id' => $id]);
        $this->addFlash('success', 'Dossier cloture.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
    }

    /**
     * Rouvre un dossier precedemment clos.
     */
    #[Route('/dossier/{id}/rouvrir', name: 'app_creances_dossier_rouvrir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rouvrir(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_dossier_rouvrir_'.$id);
        $dossier = $this->dossiers->find($id);
        if (null === $dossier) {
            throw new NotFoundHttpException('Dossier introuvable.');
        }
        $dossier->rouvrir();
        $this->dossiers->save($dossier);

        $this->notifier->notifierTiers($dossier->getCompteCode(), 'dossier_rouvert', ['dossier_id' => $id]);
        $this->addFlash('success', 'Dossier reouvert.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
    }

    /**
     * Ajoute une ecriture au dossier (avec montant partiel optionnel pour
     * litige). Recalcule le montant total.
     */
    #[Route('/dossier/{id}/ecriture/ajouter', name: 'app_creances_dossier_ajouter_ecriture', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouterEcriture(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_dossier_ecriture_'.$id);
        $dossier = $this->dossiers->find($id);
        if (null === $dossier) {
            throw new NotFoundHttpException('Dossier introuvable.');
        }
        $ecritureNumero = self::texte($request->request->get('ecriture_numero'));
        if (null === $ecritureNumero) {
            $this->addFlash('warning', 'Selectionne une ecriture.');

            return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
        }
        $montantPartiel = self::texte($request->request->get('montant_partiel'));
        $commentaire = self::texte($request->request->get('commentaire'));

        $lien = new DossierEcriture($dossier, $ecritureNumero, $montantPartiel, $commentaire);
        $this->em->persist($lien);
        $this->em->flush();

        $this->recalculerMontantTotal($dossier);

        $this->notifier->notifierTiers($dossier->getCompteCode(), 'dossier_ecriture_ajoutee', ['dossier_id' => $id, 'ecriture' => $ecritureNumero]);
        $this->addFlash('success', 'Ecriture ajoutee au dossier.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
    }

    /**
     * Retire une ecriture du dossier (cascade efface le lien, l'ecriture
     * Progiciel reste intacte dans mirror.*).
     */
    #[Route('/dossier/{id}/ecriture/{lienId}/retirer', name: 'app_creances_dossier_retirer_ecriture', methods: ['POST'], requirements: ['id' => '\d+', 'lienId' => '\d+'])]
    public function retirerEcriture(int $id, int $lienId, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_dossier_ecriture_retirer_'.$lienId);
        $dossier = $this->dossiers->find($id);
        $lien = $this->dossierEcritures->find($lienId);
        if (null === $dossier || null === $lien || $lien->getDossier()->getId() !== $dossier->getId()) {
            throw new NotFoundHttpException('Lien introuvable.');
        }
        $this->dossierEcritures->remove($lien);
        $this->recalculerMontantTotal($dossier);

        $this->notifier->notifierTiers($dossier->getCompteCode(), 'dossier_ecriture_retiree', ['dossier_id' => $id]);
        $this->addFlash('success', 'Ecriture retiree du dossier.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
    }

    /**
     * Resoud (ou annule la resolution) d'une ligne ecriture dans un dossier
     * litige. Pas de cloture automatique : on laisse le comptable cloturer
     * explicitement quand le dossier global est resolu.
     */
    #[Route('/dossier/{id}/ecriture/{lienId}/resoudre', name: 'app_creances_dossier_resoudre_ecriture', methods: ['POST'], requirements: ['id' => '\d+', 'lienId' => '\d+'])]
    public function resoudreEcriture(int $id, int $lienId, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_dossier_ecriture_resoudre_'.$lienId);
        $lien = $this->dossierEcritures->find($lienId);
        if (null === $lien || $lien->getDossier()->getId() !== $id) {
            throw new NotFoundHttpException('Lien introuvable.');
        }
        if ('1' === (string) $request->request->get('annuler')) {
            $lien->annulerResolution();
        } else {
            $lien->resoudre(self::date($request->request->get('date_resolution')));
        }
        $this->dossierEcritures->save($lien);

        $this->notifier->notifierTiers($lien->getDossier()->getCompteCode(), 'dossier_ecriture_resolue', ['lien_id' => $lienId]);
        $this->addFlash('success', 'Statut de la ligne mis a jour.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
    }

    /**
     * Ajoute une echeance a un dossier echeancier.
     */
    #[Route('/dossier/{id}/echeance/ajouter', name: 'app_creances_echeance_ajouter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouterEcheance(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_echeance_'.$id);
        $dossier = $this->dossiers->find($id);
        if (null === $dossier || DossierType::Echeancier !== $dossier->getType()) {
            throw new NotFoundHttpException('Dossier echeancier introuvable.');
        }
        $datePrevue = self::date($request->request->get('date_prevue'));
        $montant = self::texte($request->request->get('montant'));
        if (null === $datePrevue || null === $montant || !is_numeric($montant) || (float) $montant <= 0) {
            $this->addFlash('warning', 'Date et montant valides obligatoires.');

            return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
        }
        $numero = $dossier->getEcheances()->count() + 1;
        $commentaire = self::texte($request->request->get('commentaire'));

        $echeance = new Echeance($dossier, $numero, $datePrevue, $montant, $commentaire);
        $echeance->recalculerStatut();
        $this->em->persist($echeance);
        $this->em->flush();

        $this->notifier->notifierTiers($dossier->getCompteCode(), 'echeance_ajoutee', ['dossier_id' => $id, 'echeance_id' => $echeance->getId()]);
        $this->addFlash('success', 'Echeance ajoutee.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $id]);
    }

    /**
     * Enregistre un reglement (partiel ou complet) sur une echeance.
     */
    #[Route('/echeance/{id}/regler', name: 'app_creances_echeance_regler', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reglerEcheance(int $id, Request $request): Response
    {
        $this->verifierCsrf($request, 'creances_echeance_regler_'.$id);
        $echeance = $this->echeances->find($id);
        if (null === $echeance) {
            throw new NotFoundHttpException('Echeance introuvable.');
        }
        $montantRegle = self::texte($request->request->get('montant_regle'));
        if (null === $montantRegle || !is_numeric($montantRegle)) {
            $this->addFlash('warning', 'Montant invalide.');

            return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $echeance->getDossier()->getId()]);
        }
        $date = self::date($request->request->get('date_reglement'));
        $echeance->regler($montantRegle, $date);
        $this->echeances->save($echeance);

        $this->notifier->notifierTiers($echeance->getDossier()->getCompteCode(), 'echeance_reglee', ['echeance_id' => $id]);
        $this->addFlash('success', 'Reglement enregistre.');

        return $this->redirectToRoute('app_creances_dossier_voir', ['id' => $echeance->getDossier()->getId()]);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function recalculerMontantTotal(Dossier $dossier): void
    {
        $total = 0.0;
        foreach ($dossier->getEcritures() as $de) {
            if (null !== $de->getMontantPartiel()) {
                $total += (float) $de->getMontantPartiel();
                continue;
            }
            // Pas de montant partiel : on lit le solde Progiciel.
            $ecriture = $this->creances->ecriture($de->getEcritureNumero());
            if (null === $ecriture) {
                continue;
            }
            $d = is_array($ecriture['donnees']) ? $ecriture['donnees'] : (is_string($ecriture['donnees']) ? (json_decode($ecriture['donnees'], true) ?: []) : []);
            $total += (float) ($d['Montant (valeur absolue)'] ?? 0);
        }
        $dossier->setMontantTotal($total > 0 ? number_format($total, 2, '.', '') : null);
        $this->dossiers->save($dossier);
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

    private function redirectFiche(string $code): Response
    {
        return $this->redirect($this->generateUrl('app_creances_fiche', ['code' => $code]).'#tab=dossiers');
    }

    private static function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    private static function date(mixed $valeur): ?DateTimeImmutable
    {
        if (!is_string($valeur) || '' === trim($valeur)) {
            return null;
        }
        try {
            return new DateTimeImmutable($valeur);
        } catch (Exception) {
            return null;
        }
    }
}
