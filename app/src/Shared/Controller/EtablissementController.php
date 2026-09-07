<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Entity\Etablissement;
use App\Shared\Entity\EtablissementContact;
use App\Shared\Entity\User;
use App\Shared\Repository\EtablissementContactRepository;
use App\Shared\Repository\EtablissementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue d'administration du referentiel PARTAGE des etablissements (schema shared) :
 * consultation/edition des etablissements et gestion de leurs contacts e-mail
 * (directeur + secretaires), destinataires des « relances site ».
 *
 * Acces comptable OU manager (ROLE_MANAGER n'herite pas de ROLE_COMPTABLE). Chaque
 * modification de contact trace l'auteur et l'horodatage (champs d'audit).
 * Voir docs/RECOUVREMENT_RELANCE_SITE.md.
 */
#[Route('/parametres/etablissements')]
final class EtablissementController extends AbstractController
{
    public function __construct(
        private readonly EtablissementRepository $etablissements,
        private readonly EtablissementContactRepository $contacts,
    ) {
    }

    #[Route('', name: 'app_etablissements', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAcces();

        $q = trim((string) $request->query->get('q', ''));
        $liste = $this->etablissements->rechercher('' !== $q ? $q : null);

        return $this->render('parametres/etablissements/index.html.twig', [
            'etablissements' => $liste,
            // Colonne "contacts" : une seule requete agregee (pas une par ligne).
            'nb_contacts' => $this->contacts->nbParEtablissement(),
            'compteurs' => $this->etablissements->compteurs(),
            'q' => $q,
        ]);
    }

    /**
     * Creation d'un etablissement : code Progiciel + libelle + societe. Les coordonnees
     * bancaires (SEPA, compte de contrepartie) se saisissent ensuite sur la fiche,
     * ou l'on redirige directement.
     */
    #[Route('/nouveau', name: 'app_etablissements_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->denyUnlessAcces();
        $this->verifierCsrf($request, 'etab_creer');

        $code = self::normaliserCode($request->request->get('code_etab'));
        $libelle = trim((string) $request->request->get('libelle'));

        if (null === $code) {
            $this->addFlash('error', 'Le code établissement doit contenir de 1 à 8 chiffres (ex. 093).');

            return $this->redirectToRoute('app_etablissements');
        }
        if ('' === $libelle) {
            $this->addFlash('error', 'Le libellé de l\'établissement est requis.');

            return $this->redirectToRoute('app_etablissements');
        }
        if (null !== $this->etablissements->find($code)) {
            $this->addFlash('error', sprintf('L\'établissement %s existe déjà.', $code));

            return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
        }

        $etab = new Etablissement($code, $libelle);
        $societe = self::nullSiVide($request->request->get('societe'));
        $etab->setSociete($societe);
        $etab->setCodeSociete(self::nullSiVide($request->request->get('code_societe')) ?? $societe);
        $etab->setActif(true);
        $etab->marquerModifiePar($this->auteur());
        $this->etablissements->save($etab);

        $this->addFlash('success', sprintf('Établissement %s créé. Complétez ses coordonnées bancaires.', $code));

        return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
    }

    #[Route('/{code}', name: 'app_etablissements_detail', requirements: ['code' => '\d+'], methods: ['GET'])]
    public function detail(string $code): Response
    {
        $this->denyUnlessAcces();
        $etab = $this->etablissement($code);

        return $this->render('parametres/etablissements/detail.html.twig', [
            'etab' => $etab,
            'contacts' => $this->contacts->pourEtablissement($etab->getCodeEtab()),
            'roles' => EtablissementContact::ROLES,
        ]);
    }

    #[Route('/{code}', name: 'app_etablissements_maj', requirements: ['code' => '\d+'], methods: ['POST'])]
    public function majEtablissement(string $code, Request $request): Response
    {
        $this->denyUnlessAcces();
        $this->verifierCsrf($request, 'etab'.$code);
        $etab = $this->etablissement($code);

        $libelle = trim((string) $request->request->get('libelle'));
        if ('' !== $libelle) {
            $etab->setLibelle($libelle);
        }
        $etab->setSociete(self::nullSiVide($request->request->get('societe')));
        // Coordonnees bancaires du debiteur (SEPA remboursement).
        $etab->setNomLegalSepa(self::nullSiVide($request->request->get('nom_legal_sepa')));
        $etab->setIban(self::nullSiVide($request->request->get('iban')));
        $etab->setBic(self::nullSiVide($request->request->get('bic')));
        $etab->setBanque(self::nullSiVide($request->request->get('banque')));
        $etab->setCompteContrepartie(self::nullSiVide($request->request->get('compte_contrepartie')));
        $etab->setActif($request->request->getBoolean('actif'));
        $etab->marquerModifiePar($this->auteur());
        $this->etablissements->save($etab);

        $this->addFlash('success', sprintf('Établissement %s mis à jour.', $etab->getCodeEtab()));

        return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
    }

    #[Route('/{code}/contacts', name: 'app_etablissements_contact_ajout', requirements: ['code' => '\d+'], methods: ['POST'])]
    public function ajouterContact(string $code, Request $request): Response
    {
        $this->denyUnlessAcces();
        $this->verifierCsrf($request, 'contact'.$code);
        $etab = $this->etablissement($code);

        $email = EtablissementContact::normaliserEmail((string) $request->request->get('email'));
        $role = (string) $request->request->get('role', 'secretaire');
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse e-mail invalide.');

            return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
        }

        foreach ($this->contacts->pourEtablissement($etab->getCodeEtab()) as $existant) {
            if ($existant->getEmail() === $email) {
                $this->addFlash('error', 'Ce contact existe déjà pour cet établissement.');

                return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
            }
        }

        $this->contacts->save(new EtablissementContact($etab, $email, $role, $this->auteur()));
        $this->addFlash('success', sprintf('Contact %s ajouté.', $email));

        return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
    }

    #[Route('/contacts/{id}', name: 'app_etablissements_contact_maj', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function majContact(int $id, Request $request): Response
    {
        $this->denyUnlessAcces();
        $contact = $this->contact($id);
        $code = $contact->getEtablissement()->getCodeEtab();
        $this->verifierCsrf($request, 'contact-maj'.$id);

        $email = EtablissementContact::normaliserEmail((string) $request->request->get('email'));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse e-mail invalide.');

            return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
        }

        $contact->setEmail($email, $this->auteur());
        $contact->setRole((string) $request->request->get('role', 'secretaire'), $this->auteur());
        $contact->setActif($request->request->getBoolean('actif'), $this->auteur());
        $this->contacts->save($contact);

        $this->addFlash('success', 'Contact mis à jour.');

        return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
    }

    #[Route('/contacts/{id}/supprimer', name: 'app_etablissements_contact_suppr', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerContact(int $id, Request $request): Response
    {
        $this->denyUnlessAcces();
        $contact = $this->contact($id);
        $code = $contact->getEtablissement()->getCodeEtab();
        $this->verifierCsrf($request, 'contact-suppr'.$id);

        $this->contacts->remove($contact);
        $this->addFlash('success', 'Contact supprimé.');

        return $this->redirectToRoute('app_etablissements_detail', ['code' => $code]);
    }

    private function etablissement(string $code): Etablissement
    {
        $etab = $this->etablissements->find($code);
        if (null === $etab) {
            throw $this->createNotFoundException('Établissement introuvable.');
        }

        return $etab;
    }

    private function contact(int $id): EtablissementContact
    {
        $contact = $this->contacts->find($id);
        if (null === $contact) {
            throw $this->createNotFoundException('Contact introuvable.');
        }

        return $contact;
    }

    private function auteur(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getFullName() : 'inconnu';
    }

    private function verifierCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /** Accessible a tout utilisateur connecte (parametrage general). L'audit trace qui edite. */
    private function denyUnlessAcces(): void
    {
        if (!$this->isGranted('ROLE_USER')) {
            throw $this->createAccessDeniedException('Connexion requise.');
        }
    }

    /**
     * Code etablissement au format Progiciel : chiffres seuls, zero-padde sur 3 (« 93 » -> « 093 »)
     * pour rester joignable avec la colonne `codeetab` du miroir. null si invalide.
     */
    private static function normaliserCode(mixed $valeur): ?string
    {
        $code = preg_replace('/\D+/', '', trim((string) $valeur)) ?? '';
        if ('' === $code || \strlen($code) > 8) {
            return null;
        }

        return \strlen($code) < 3 ? str_pad($code, 3, '0', \STR_PAD_LEFT) : $code;
    }

    private static function nullSiVide(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }
}
