<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\StatutSecretaire;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Repository\DossierTransitionRepository;
use App\Remboursement\Service\ImputationComptable;
use App\Remboursement\Service\RemboursementMailer;
use App\Remboursement\Service\WorkflowRemboursement;
use App\Shared\Repository\EtablissementContactRepository;
use App\Shared\Repository\EtablissementRepository;
use DateTimeImmutable;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche d'un dossier de remboursement + declenchement des transitions de la machine
 * a etats. Chaque action est GARDEE par role (WorkflowRemboursement via la securite) :
 * la fiche n'affiche que les transitions permises a l'utilisateur courant. C'est le
 * point ou le comptable envoie au directeur, le directeur valide/refuse, etc.
 *
 * Poste comptable / encadrement finance uniquement (la secretaire a son espace dedie,
 * avec controle de propriete). L'apercu et le service des pieces n'ont donc pas besoin
 * d'un controle de proprietaire : l'acces au module + role suffit.
 */
#[Route('/remboursement/dossier')]
#[IsGranted(new Expression("is_granted('ROLE_COMPTABLE') or is_granted('ROLE_DIRECTEUR') or is_granted('ROLE_MANAGER')"))]
final class DossierController extends AbstractController
{
    /** Libelles humains des transitions (pour les boutons d'action). */
    private const LIBELLES = [
        'deposer' => 'Déposer',
        'demander_complement' => 'Demander un complément',
        'basculer_icar' => 'Basculer en cas ICAR',
        'resoudre_icar' => 'Résoudre le cas ICAR',
        'envoyer_directeur' => 'Envoyer au directeur',
        'refuser_comptable' => 'Refuser',
        'valider_directeur' => 'Valider',
        'refuser_directeur' => 'Refuser',
        'confirmer' => 'Confirmer (définitif)',
        'refuser_apres_validation' => 'Refuser',
        'relancer_generation' => 'Relancer la génération',
        'lettrer' => 'Marquer lettré',
        'marquer_doublon' => 'Marquer doublon',
        'rouvrir_doublon' => 'Rouvrir (pas un doublon)',
        'marquer_fraude' => 'Signaler une fraude',
        'rouvrir' => 'Rouvrir',
    ];

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierPieceRepository $pieces,
        private readonly DossierTransitionRepository $transitions,
        private readonly WorkflowRemboursement $workflow,
        private readonly EtablissementRepository $etablissements,
        private readonly EtablissementContactRepository $contacts,
        private readonly ImputationComptable $imputation,
        private readonly RemboursementMailer $mailer,
    ) {
    }

    /**
     * VALIDER (comptable) : enregistre les valeurs validees puis envoie au directeur
     * (transition envoyer_directeur) + e-mail directeur.
     */
    #[Route('/{id}/valider', name: 'app_remboursement_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function valider(int $id, Request $request): Response
    {
        $dossier = $this->trouver($id);
        $this->verifierCsrf($request);
        $par = $this->getUser()?->getUserIdentifier();

        // Les valeurs validees generent les fichiers comptables : elles sont OBLIGATOIRES
        // pour VALIDER (ex. code ICAR non detecte par l'IA, a saisir par la comptable).
        $donnees = $this->donneesValidation($request);
        $manque = $this->valeursValideesManquantes($donnees, $dossier);
        if ([] !== $manque) {
            $this->addFlash('error', sprintf('Valeurs validées incomplètes : %s. Ces valeurs génèrent les fichiers comptables et sont obligatoires.', implode(', ', $manque)));

            return $this->redirectToRoute('app_remboursement_dossier', ['id' => $id]);
        }
        $dossier->enregistrerValidation($donnees, $par);

        try {
            $this->workflow->appliquer($dossier, 'envoyer_directeur', $par);
            $this->mailer->directeur($dossier);
            $this->addFlash('success', sprintf('Dossier %s validé et envoyé au directeur.', $dossier->getReference()));
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_remboursement_accueil');
    }

    /**
     * DEMANDER UNE CORRECTION : enregistre les valeurs deja validees (pas de reprise a
     * zero), memorise les pieces a corriger, repasse le dossier a la secretaire + e-mail.
     */
    #[Route('/{id}/corriger', name: 'app_remboursement_corriger', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function corriger(int $id, Request $request): Response
    {
        $dossier = $this->trouver($id);
        $this->verifierCsrf($request);
        $par = $this->getUser()?->getUserIdentifier();
        $message = trim((string) $request->request->get('message', ''));

        $dossier->enregistrerValidation($this->donneesValidation($request), $par);
        $types = $this->piecesDemandees($request, $dossier);
        $dossier->setCorrectionPieces($types);
        $dossier->setCorrectionChamps(null); // nouveau tour : on repart d'une correction propre

        try {
            $this->workflow->appliquer($dossier, 'demander_complement', $par, '' !== $message ? $message : null);
            $this->mailer->correction($dossier, $message, $this->libellesPieces($dossier, $types));
            $this->addFlash('success', sprintf('Correction demandée à la secrétaire pour %s.', $dossier->getReference()));
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_remboursement_accueil');
    }

    /**
     * REFUSER (comptable) : trace le motif, refuse le dossier + e-mail a la secretaire.
     */
    #[Route('/{id}/refuser', name: 'app_remboursement_refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuser(int $id, Request $request): Response
    {
        $dossier = $this->trouver($id);
        $this->verifierCsrf($request);
        $par = $this->getUser()?->getUserIdentifier();
        $message = trim((string) $request->request->get('message', ''));

        $dossier->enregistrerValidation($this->donneesValidation($request), $par);
        $dossier->setRefusMotif('' !== $message ? $message : null);

        try {
            $this->workflow->appliquer($dossier, 'refuser_comptable', $par, '' !== $message ? $message : null);
            $this->mailer->refus($dossier, $message);
            $this->addFlash('success', sprintf('Dossier %s refusé.', $dossier->getReference()));
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_remboursement_accueil');
    }

    /**
     * Valeurs validees (colonne "Valeur validée" + imputation) issues du formulaire.
     *
     * @return array{nom: ?string, iban: ?string, bic: ?string, montant: ?string, immatriculation: ?string, code_icar: ?string, libelle: ?string, code_comptable: ?string, role_tiers: ?string}
     */
    private function donneesValidation(Request $request): array
    {
        $montant = trim((string) $request->request->get('montant', ''));

        return [
            'nom' => self::champ($request->request->get('nom')),
            'iban' => self::champ($request->request->get('iban')),
            'bic' => self::champ($request->request->get('bic')),
            'montant' => '' !== $montant ? number_format((float) str_replace(',', '.', $montant), 2, '.', '') : null,
            'immatriculation' => self::champ($request->request->get('immatriculation')),
            'code_icar' => self::champ($request->request->get('code_icar')),
            'libelle' => self::champ($request->request->get('libelle')),
            'code_comptable' => self::champ($request->request->get('code_comptable')),
            'role_tiers' => self::champ($request->request->get('role_tiers')),
        ];
    }

    /**
     * Valeurs validees OBLIGATOIRES manquantes (pour VALIDER) : la colonne "Valeur
     * validée" doit etre complete car elle alimente les fichiers comptables generes.
     *
     * @param array{nom: ?string, iban: ?string, bic: ?string, montant: ?string, immatriculation: ?string, code_icar: ?string, libelle: ?string, code_comptable: ?string, role_tiers: ?string} $donnees
     *
     * @return list<string> libelles des valeurs manquantes
     */
    private function valeursValideesManquantes(array $donnees, Dossier $dossier): array
    {
        $requis = [
            'Nom' => $donnees['nom'],
            'IBAN' => $donnees['iban'],
            'BIC' => $donnees['bic'],
            'Montant' => $donnees['montant'],
        ];
        if (DossierMotif::RACHAT_SEC === $dossier->getMotif()) {
            $requis['Immatriculation'] = $donnees['immatriculation'];
        } else {
            $requis['Code ICAR'] = $donnees['code_icar'];
        }

        $manque = [];
        foreach ($requis as $label => $valeur) {
            if (null === $valeur || '' === trim($valeur)) {
                $manque[] = $label;
            }
        }

        return $manque;
    }

    /**
     * Types de pieces cochees "a corriger" (filtres sur les pieces du motif).
     *
     * @return list<string>
     */
    private function piecesDemandees(Request $request, Dossier $dossier): array
    {
        $valides = array_keys($dossier->getMotif()->piecesRequises());
        $coches = (array) $request->request->all('pieces_a_corriger');

        return array_values(array_filter(array_map('strval', $coches), static fn (string $t): bool => \in_array($t, $valides, true)));
    }

    /**
     * @param list<string> $types
     *
     * @return list<string>
     */
    private function libellesPieces(Dossier $dossier, array $types): array
    {
        $libelles = $dossier->getMotif()->piecesRequises();

        return array_map(static fn (string $t): string => $libelles[$t] ?? $t, $types);
    }

    private function verifierCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('remb_action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private static function champ(mixed $v): ?string
    {
        $v = trim((string) $v);

        return '' === $v ? null : $v;
    }

    /**
     * Panneau glissant (quick-view) d'un dossier depuis "Tous les dossiers" : infos
     * synthetiques en lecture seule (dont etablissement / directeur / secretaire).
     * Fragment charge a la volee par le controleur "detail-panel".
     */
    #[Route('/{id}/panneau', name: 'app_remboursement_dossier_panneau', requirements: ['id' => '\d+|__ID__'], methods: ['GET'])]
    public function panneau(int $id): Response
    {
        $dossier = $this->trouver($id);
        $code = $dossier->getEtablissementCode();
        $etab = null !== $code && '' !== $code ? $this->etablissements->findOneBy(['codeEtab' => $code]) : null;
        $directeur = null !== $code && '' !== $code ? ($this->contacts->emailsActifs($code, 'directeur')[0] ?? null) : null;

        return $this->render('remboursement/_panneau.html.twig', [
            'dossier' => $dossier,
            'pieces' => $this->pieces->pourDossier($dossier),
            'etabNom' => $etab?->getLibelle(),
            'directeurEmail' => $directeur,
            'messageComptable' => $this->messageComptable($dossier),
            'historique' => $this->transitions->pourDossier($dossier),
        ]);
    }

    /**
     * Affichage en ligne d'une piece (PDF ou image) pour la visionneuse de la page
     * detail. Reserve au poste comptable/encadrement (garde de classe) ; les fichiers
     * vivent hors racine web et ne sont servis que par cette route. On pose
     * X-Frame-Options SAMEORIGIN pour autoriser l'affichage dans l'iframe de l'app
     * (le subscriber global met DENY par defaut sur tout le reste).
     */
    #[Route('/piece/{id}', name: 'app_remboursement_dossier_piece', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function piece(DossierPiece $piece): Response
    {
        $octets = $piece->getContenu();
        if ('' === $octets) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }

        $reponse = new Response($octets, Response::HTTP_OK, [
            'Content-Type' => $piece->getMimeType(),
            'Content-Disposition' => 'inline',
        ]);
        $reponse->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $reponse;
    }

    #[Route('/{id}', name: 'app_remboursement_dossier', requirements: ['id' => '\d+|__ID__'], methods: ['GET'])]
    public function fiche(int $id): Response
    {
        $dossier = $this->trouver($id);

        $actions = [];
        foreach ($this->workflow->transitionsPossibles($dossier) as $nom) {
            $actions[$nom] = self::LIBELLES[$nom] ?? $nom;
        }

        $estRachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();
        $code = $dossier->getEtablissementCode();
        $directeurEmail = null !== $code && '' !== $code ? ($this->contacts->emailsActifs($code, 'directeur')[0] ?? null) : null;

        // Precedente demande de correction (repere si le dossier a deja fait un aller-retour).
        $ancienMessage = null;
        foreach ($this->transitions->pourDossier($dossier) as $t) {
            if ('demander_complement' === $t->getTransition() && '' !== trim((string) $t->getCommentaire())) {
                $ancienMessage = ['texte' => (string) $t->getCommentaire(), 'le' => $t->getLe(), 'par' => $t->getPar()];
                break;
            }
        }

        // Jumeaux ACTIFS par immat/ICAR d'un cote, par compte bancaire de l'autre. Les
        // deux listes peuvent se recouper et c'est voulu : elles alimentent desormais DEUX
        // panneaux ouverts l'un apres l'autre, jamais deux bandeaux cote a cote. Dedupliquer
        // ne ferait que creuser un ecart entre le compteur d'une ligne et ce qu'elle montre.
        $doublons = $this->dossiers->doublonsActifs($dossier->getMotif(), $dossier->getCleDoublon(), $dossier->getId());
        $doublonsIban = $this->dossiers->memeIbanActif($dossier->getIbanHash(), $dossier->getId());

        return $this->render('remboursement/dossier.html.twig', [
            'dossier' => $dossier,
            'pieces' => $this->pieces->pourDossier($dossier),
            'actions' => $actions,
            'imputation' => $this->imputation->pour($dossier),
            'directeurEmail' => $directeurEmail,
            'ancienMessage' => $ancienMessage,
            // Jumeaux ACTIFS (meme immatriculation / meme code ICAR) : bandeau anti double-paiement.
            'doublons' => $doublons,
            // "Meme compte bancaire" (meme IBAN) HORS jumeaux immat/ICAR deja listes ci-dessus.
            'doublonsIban' => $doublonsIban,
            // Compteurs de doublons affiches DEUX fois par ligne : un sur la valeur SAISIE
            // (secretaire), un sur la valeur IA. Un compte > 1 = doublon potentiel.
            'comptes' => [
                'nom' => [
                    'saisie' => $this->dossiers->compterNom($dossier->getNomClient()),
                    'ia' => $this->dossiers->compterNom($dossier->getControleNom()),
                ],
                // IBAN chiffre en base : un LOWER(colonne)=valeur ne matcherait jamais. Le
                // comptage passe donc par l'index aveugle (iban_hash), qui porte l'IBAN QUI
                // FERA FOI (valide, sinon IA, sinon saisie) -- exactement celui qui sera
                // paye, donc le bon pour un controle anti-double-paiement. Un seul compteur
                // par ligne, pose sur la colonne Saisie : le repeter en IA n'apprendrait rien.
                'iban' => [
                    'saisie' => $this->dossiers->compterIbanHash($dossier->getIbanHash()),
                    'ia' => null,
                ],
                'cle' => $estRachat
                    ? [
                        'saisie' => $this->dossiers->compterImmat($dossier->getImmatriculation()),
                        'ia' => $this->dossiers->compterImmat($dossier->getControleImmatriculation()),
                    ]
                    : [
                        'saisie' => $this->dossiers->compterIcar($dossier->getCodeIcar()),
                        'ia' => $this->dossiers->compterIcar($dossier->getControleIcar()),
                    ],
            ],
        ]);
    }

    #[Route('/{id}/transition', name: 'app_remboursement_dossier_transition', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transition(int $id, Request $request): Response
    {
        $dossier = $this->trouver($id);
        if (!$this->isCsrfTokenValid('remboursement_transition', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $transition = (string) $request->request->get('transition', '');
        $commentaire = trim((string) $request->request->get('commentaire', '')) ?: null;

        try {
            $this->workflow->appliquer($dossier, $transition, $this->getUser()?->getUserIdentifier(), $commentaire);
            $this->addFlash('success', sprintf('Action « %s » effectuée.', self::LIBELLES[$transition] ?? $transition));
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_remboursement_dossier', ['id' => $id]);
    }

    /**
     * Message laisse par la comptable pendant la verification, a n'afficher que si le
     * dossier est REFUSE ou en CORRECTION requise : commentaire de la derniere
     * transition ayant conduit a l'etat courant. Null sinon.
     *
     * @return array{texte: string, par: ?string, le: DateTimeImmutable}|null
     */
    private function messageComptable(Dossier $dossier): ?array
    {
        $sec = $dossier->getStatut()->statutSecretaire();
        if (StatutSecretaire::CORRECTION !== $sec && StatutSecretaire::REFUSE !== $sec) {
            return null;
        }

        foreach ($this->transitions->pourDossier($dossier) as $t) {
            $texte = trim((string) $t->getCommentaire());
            if ('' !== $texte && $t->getVersStatut() === $dossier->getStatut()) {
                return ['texte' => $texte, 'par' => $t->getPar(), 'le' => $t->getLe()];
            }
        }

        return null;
    }

    private function trouver(int $id): Dossier
    {
        $dossier = $this->dossiers->find($id);
        if (!$dossier instanceof Dossier) {
            throw $this->createNotFoundException('Dossier introuvable.');
        }

        return $dossier;
    }
}
