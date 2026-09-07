<?php

declare(strict_types=1);

namespace App\Shared\Controller\Admin;

use App\Shared\Entity\DemandeAcces;
use App\Shared\Entity\PoleComptable;
use App\Shared\Entity\User;
use App\Shared\Repository\DemandeAccesRepository;
use App\Shared\Repository\UserRepository;
use App\Shared\Security\Roles;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Gestion des demandes d'accès (habilitation des utilisateurs).
 * Accessible aux administrateurs. Voir docs/SECURITY.md.
 */
#[Route('/admin/demandes')]
#[IsGranted('ROLE_ADMIN')]
final class DemandeAccesController extends AbstractController
{
    /** Expediteur du tunnel SMTP interne (le Mailjet « relances » est reserve au Recouvrement). */
    private const FROM_EMAIL = 'copilote@demonstration.invalid';

    private const FROM_NOM = 'Finance Créances';

    #[Route('', name: 'app_admin_demandes', methods: ['GET'])]
    public function index(DemandeAccesRepository $demandes): Response
    {
        return $this->render('admin/demandes/index.html.twig', [
            'demandes' => $demandes->findEnAttente(),
            'roles_metier' => Roles::METIER,
            'roles_eleves' => Roles::ELEVES,
            'poles' => PoleComptable::choix(),
        ]);
    }

    #[Route('/{id}/approuver', name: 'app_admin_demandes_approuver', methods: ['POST'])]
    public function approuver(
        Request $request,
        DemandeAcces $demande,
        UserRepository $users,
        DemandeAccesRepository $demandes,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('approuver'.$demande->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$demande->isEnAttente()) {
            $this->addFlash('error', 'Cette demande a déjà été traitée.');

            return $this->redirectToRoute('app_admin_demandes');
        }

        /** @var list<string> $rolesDemandes */
        $rolesDemandes = (array) $request->request->all('roles');
        // Roles metier uniquement : admin/super se gerent en CLI (pas via l'UI).
        $roles = Roles::filtrer($rolesDemandes, false);

        if ([] === $roles) {
            $this->addFlash('error', 'Sélectionnez au moins un rôle à attribuer.');

            return $this->redirectToRoute('app_admin_demandes');
        }

        $beneficiaire = $demande->getDemandeur();
        $beneficiaire->setRoles($roles);
        // Pole d'affectation, uniquement si le role Comptable est accorde.
        $beneficiaire->setPoleComptable(
            \in_array('ROLE_COMPTABLE', $roles, true)
                ? PoleComptable::tryFrom(trim((string) $request->request->get('pole', '')))
                : null,
        );
        $users->save($beneficiaire, false);

        /** @var User $decideur */
        $decideur = $this->getUser();
        $demande->approuver($decideur, $roles);
        $demandes->save($demande);

        $this->addFlash('success', sprintf('Accès accordé à %s.', $beneficiaire->getFullName()));

        // L'habilitation est DEJA enregistree : l'e-mail de courtoisie ne doit jamais
        // faire echouer la requete. Les e-mails partent en synchrone (SendEmailMessage
        // n'est pas route en async, cf. config/packages/messenger.yaml), donc une panne
        // de transport remontait ici en 500 — alors que l'acces etait bien accorde.
        if (!$this->envoyerEmailValidation($mailer, $beneficiaire, $logger)) {
            $this->addFlash('error', sprintf(
                'Accès accordé, mais l\'e-mail de confirmation n\'a pas pu être envoyé à %s. Prévenez la personne.',
                $beneficiaire->getEmail(),
            ));
        }

        return $this->redirectToRoute('app_admin_demandes');
    }

    #[Route('/{id}/refuser', name: 'app_admin_demandes_refuser', methods: ['POST'])]
    public function refuser(
        Request $request,
        DemandeAcces $demande,
        DemandeAccesRepository $demandes,
    ): Response {
        if (!$this->isCsrfTokenValid('refuser'.$demande->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($demande->isEnAttente()) {
            /** @var User $decideur */
            $decideur = $this->getUser();
            $demande->refuser($decideur);
            $demandes->save($demande);
            $this->addFlash('success', 'Demande refusée.');
        }

        return $this->redirectToRoute('app_admin_demandes');
    }

    /**
     * E-mail « votre acces est actif ». Best-effort : renvoie false si l'envoi echoue,
     * sans jamais propager l'exception — l'habilitation, elle, est deja acquise.
     */
    private function envoyerEmailValidation(MailerInterface $mailer, User $beneficiaire, LoggerInterface $logger): bool
    {
        $email = (new TemplatedEmail())
            ->from(new Address(self::FROM_EMAIL, self::FROM_NOM))
            ->to($beneficiaire->getEmail())
            ->subject('Votre accès à Finance Créances est actif')
            ->htmlTemplate('emails/acces_valide.html.twig')
            ->context(['user' => $beneficiaire]);

        // Tunnel SMTP (transport « copilote »), pas le Mailjet de Recouvrement : sans cet
        // en-tete l'e-mail partait sur le transport par defaut. Cf. config/packages/mailer.yaml.
        $email->getHeaders()->addTextHeader('X-Transport', 'copilote');

        try {
            $mailer->send($email);

            return true;
        } catch (Throwable $e) {
            $logger->error('Remise de l\'e-mail de validation d\'acces impossible.', [
                'beneficiaire' => $beneficiaire->getEmail(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}
