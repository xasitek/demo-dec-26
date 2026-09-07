<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Enum\StatutSecretaire;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Repository\DossierTransitionRepository;
use App\Remboursement\Repository\EmailFavoriRepository;
use App\Remboursement\Service\CleDoublon;
use App\Remboursement\Service\ClientEloficash;
use App\Remboursement\Service\ControleBuyBack;
use App\Remboursement\Service\DepotDossier;
use App\Remboursement\Service\GenerationFichiersComptables;
use App\Remboursement\Service\NormalisationSaisie;
use App\Remboursement\Service\RemboursementNotifier;
use App\Remboursement\Service\StockagePieces;
use App\Remboursement\Service\WorkflowRemboursement;
use App\Shared\Entity\User;
use App\Shared\Repository\EtablissementContactRepository;
use App\Shared\Repository\EtablissementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Depot d'un dossier de remboursement par une secretaire (formulaire natif dynamique,
 * fin du Google Form) + suivi de ses dossiers. Les champs et pieces requises varient
 * selon le motif (rachat sec / trop-percu).
 *
 * Acces : le FORMULAIRE (GET /deposer) est PUBLIC (les secretaires ne sont pas encore
 * connectees ; cf. security.yaml) ; le depot reel (POST) et "mes dossiers" exigent
 * ROLE_SECRETAIRE (renseigne en base a la 1re connexion Google @demonstration.invalid).
 */
#[Route('/remboursement')]
final class DepotController extends AbstractController
{
    public function __construct(
        private readonly DepotDossier $depot,
        private readonly EtablissementRepository $etablissements,
        private readonly DossierRepository $dossiers,
        private readonly DossierPieceRepository $pieces,
        private readonly StockagePieces $stockage,
        private readonly WorkflowRemboursement $workflow,
        private readonly EntityManagerInterface $em,
        private readonly DossierTransitionRepository $transitions,
        private readonly NormalisationSaisie $normalisation,
        private readonly EtablissementContactRepository $contacts,
        private readonly ControleBuyBack $controleBuyBack,
        private readonly RemboursementNotifier $notifier,
        private readonly ClientEloficash $eloficash,
        private readonly EmailFavoriRepository $favoris,
    ) {
    }

    #[Route('/deposer', name: 'app_remboursement_deposer_formulaire', methods: ['GET'])]
    public function formulaire(): Response
    {
        return $this->render('remboursement/deposer.html.twig', [
            'motifs' => DossierMotif::cases(),
            // Liste de SAISIE : les etablissements desactives ne doivent plus etre
            // proposes. Les ecrans comptables, eux, gardent le referentiel complet
            // pour continuer d'afficher l'historique.
            'etablissements' => $this->etablissements->actifs(),
            // Carnet d'adresses PERSONNEL : rien pour une visiteuse pas encore connectee
            // (le formulaire est public, l'etape 1 grisee jusqu'a la connexion Google).
            'favorisEmail' => $this->favoris->pourSecretaire((string) $this->getUser()?->getUserIdentifier()),
        ]);
    }

    /**
     * Controle Buy Back en DIRECT (message temps reel sous le formulaire de depot,
     * rachat sec). Renvoie un fragment HTML (vert si OK, alerte si surpaiement) ou une
     * reponse VIDE si la plaque est inconnue (aucun message a afficher).
     */
    #[Route('/deposer/controle-buyback', name: 'app_remboursement_controle_buyback', methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function controleBuyBack(Request $request): Response
    {
        $montant = $this->normalisation->montant((string) $request->query->get('montant', ''));
        $c = $this->controleBuyBack->pour(
            self::texte($request->query->get('immat')),
            null !== $montant ? (float) $montant : null,
        );
        if (null === $c) {
            return new Response('');
        }

        return $this->render('remboursement/_controle_buyback.html.twig', ['c' => $c]);
    }

    /**
     * Recherche floue de clients dans le miroir Gestion commerciale (aide au depot trop-percu). JSON.
     */
    #[Route('/deposer/chercher-client', name: 'app_remboursement_chercher_client', methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function chercherClient(Request $request): JsonResponse
    {
        return $this->json(['clients' => $this->eloficash->chercherClients((string) $request->query->get('q', ''))]);
    }

    /**
     * Donnees d'un client pour preremplir un depot trop-percu : ses trop-percus (lignes
     * credit = montant + code ICAR, source de verite) et ses coordonnees. L'IBAN vient
     * rarement de la base (a saisir via le RIB) ; le montant et l'ICAR, oui. JSON.
     */
    #[Route('/deposer/client-donnees', name: 'app_remboursement_client_donnees', methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function clientDonnees(Request $request): JsonResponse
    {
        $compte = (string) $request->query->get('compte', '');
        $co = $this->eloficash->coordonnees($compte);

        return $this->json([
            'client' => $co['raisonSociale'] ?? '',
            'iban' => $co['iban'] ?? '',
            'bic' => $co['bic'] ?? '',
            'tropPercu' => $this->eloficash->tropPercu($compte),
        ]);
    }

    #[Route('/deposer', name: 'app_remboursement_deposer', methods: ['POST'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function deposer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('remboursement_depot', (string) $request->request->get('_token'))) {
            return $this->echecDepot($request, ['Session expirée : rechargez la page et réessayez.'], Response::HTTP_FORBIDDEN);
        }

        $motif = DossierMotif::tryFrom((string) $request->request->get('motif', ''));
        if (null === $motif) {
            return $this->echecDepot($request, ['Veuillez choisir un motif de remboursement.']);
        }

        $erreurs = $this->valider($request, $motif);
        if ([] !== $erreurs) {
            return $this->echecDepot($request, $erreurs);
        }

        $donnees = [
            'etablissementCode' => self::texte($request->request->get('etablissement')),
            'nomClient' => (string) $request->request->get('nom_client', ''),
            'ibanClient' => self::texte($request->request->get('iban_client')),
            'bicClient' => self::texte($request->request->get('bic_client')),
            'montant' => $this->normalisation->montant((string) $request->request->get('montant', '0')) ?? '0',
            'immatriculation' => $this->normalisation->immatriculation(self::texte($request->request->get('immatriculation'))),
            'codeIcar' => self::texte($request->request->get('code_icar')),
            'emailCopie' => self::texte($request->request->get('email_copie')),
        ];

        /** @var array<string, UploadedFile> $fichiers */
        $fichiers = [];
        foreach (array_keys($motif->piecesRequises()) as $type) {
            $fichier = $request->files->get($type);
            if ($fichier instanceof UploadedFile) {
                $fichiers[$type] = $fichier;
            }
        }

        try {
            $this->depot->deposer($motif, $donnees, $fichiers, $this->getUser()?->getUserIdentifier(), $this->compteSourceVerifie($request, $motif));
        } catch (Throwable $e) {
            return $this->echecDepot($request, ['Le dépôt a échoué : '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'Votre dossier a bien été déposé.');

        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'redirect' => $this->generateUrl('app_remboursement_mes_dossiers')]);
        }

        return $this->redirectToRoute('app_remboursement_mes_dossiers');
    }

    /**
     * Reponse d'echec de depot : en AJAX -> JSON {ok:false, erreurs} (affichage
     * temps reel cote formulaire) ; sinon flashs + redirection (repli sans JS).
     *
     * @param list<string> $erreurs
     */
    private function echecDepot(Request $request, array $erreurs, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => false, 'erreurs' => $erreurs], $status);
        }

        foreach ($erreurs as $message) {
            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('app_remboursement_deposer_formulaire');
    }

    #[Route('/mes-dossiers', name: 'app_remboursement_mes_dossiers', methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function mesDossiers(Request $request): Response
    {
        $par = $this->getUser()?->getUserIdentifier();
        $q = trim((string) $request->query->get('q', ''));
        $motif = DossierMotif::tryFrom((string) $request->query->get('motif', ''));
        $statut = StatutSecretaire::tryFrom((string) $request->query->get('statut', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $dossiers = $this->dossiers->parCreateurFiltre($par, '' !== $q ? $q : null, $motif, $statut, $page);

        // Tranche de scroll infini : uniquement les lignes.
        if ($request->query->getBoolean('fragment')) {
            return $this->render('remboursement/_dossiers_rows.html.twig', ['dossiers' => $dossiers]);
        }

        $total = $this->dossiers->compterParCreateurFiltre($par, '' !== $q ? $q : null, $motif, $statut);

        return $this->render('remboursement/mes_dossiers.html.twig', [
            'dossiers' => $dossiers,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / DossierRepository::PAR_PAGE)),
            'q' => $q,
            'motif' => $motif,
            'statut' => $statut,
            'motifs' => DossierMotif::cases(),
            'statuts' => StatutSecretaire::pourFiltre(),
        ]);
    }

    /**
     * Fiche d'un de MES dossiers : infos + statut + pieces. Editable uniquement si le
     * comptable a demande une correction (statut Correction requise), sinon lecture seule.
     */
    #[Route('/mes-dossiers/{id}', name: 'app_remboursement_mon_dossier', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function monDossier(Dossier $dossier): Response
    {
        $this->assertProprietaire($dossier);

        return $this->render('remboursement/mon_dossier.html.twig', [
            'dossier' => $dossier,
            'pieces' => $this->pieces->pourDossier($dossier),
            'editable' => DossierStatut::COMPLEMENT_REQUIS === $dossier->getStatut(),
            // Meme forme que dans le panneau (texte + auteur + date) : la secretaire doit
            // savoir QUI lui demande la correction, pas seulement ce qui est demande.
            'messageComptable' => $this->messageComptable($dossier),
            'piecesACorriger' => $dossier->getCorrectionPieces(),
        ]);
    }

    /**
     * Panneau glissant "quick-view" d'un de MES dossiers (meme rendu que la comptable,
     * lecture seule). Charge a la volee par le controleur "detail-panel" pour les dossiers
     * qui ne sont PAS en correction requise (ceux-la ouvrent la page editable).
     */
    #[Route('/mes-dossiers/{id}/panneau', name: 'app_remboursement_mon_dossier_panneau', requirements: ['id' => '\d+|__ID__'], methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function monDossierPanneau(int $id): Response
    {
        $dossier = $this->dossiers->find($id);
        if (!$dossier instanceof Dossier) {
            throw $this->createNotFoundException('Dossier introuvable.');
        }
        $this->assertProprietaire($dossier);

        $code = $dossier->getEtablissementCode();
        $etab = null !== $code && '' !== $code ? $this->etablissements->findOneBy(['codeEtab' => $code]) : null;
        $directeur = null !== $code && '' !== $code ? ($this->contacts->emailsActifs($code, 'directeur')[0] ?? null) : null;

        // La secretaire ne voit PAS les fichiers comptables generes (OD / SEPA) : usage interne.
        $pieces = array_values(array_filter(
            $this->pieces->pourDossier($dossier),
            static fn (DossierPiece $p): bool => !\in_array($p->getType(), [GenerationFichiersComptables::TYPE_OD, GenerationFichiersComptables::TYPE_SEPA], true),
        ));

        return $this->render('remboursement/_panneau.html.twig', [
            'dossier' => $dossier,
            'pieces' => $pieces,
            'etabNom' => $etab?->getLibelle(),
            'directeurEmail' => $directeur,
            'messageComptable' => $this->messageComptable($dossier),
            'historique' => $this->transitions->pourDossier($dossier),
            'pieceRoute' => 'app_remboursement_ma_piece',
            // Lien vers la fiche EDITABLE : bouton "Corriger" dans le panneau, affiche
            // seulement si le dossier est en correction requise (cote secretaire).
            'lienCorrection' => $this->generateUrl('app_remboursement_mon_dossier', ['id' => $dossier->getId()]),
            // Vue secretaire : on masque le verdict IA (notes de controle internes au comptable).
            'vueSecretaire' => true,
        ]);
    }

    /**
     * Message du comptable (refus / correction) pour le panneau, au format attendu par
     * `_panneau.html.twig`. Null hors de ces deux etats.
     *
     * @return array{texte: string, par: string|null, le: DateTimeImmutable}|null
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

    /**
     * Affichage / telechargement d'une piece de MON dossier (PDF ou image, en ligne).
     */
    #[Route('/mes-dossiers/piece/{id}', name: 'app_remboursement_ma_piece', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function maPiece(DossierPiece $piece): Response
    {
        $this->assertProprietaire($piece->getDossier());

        $octets = $piece->getContenu();
        if ('' === $octets) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }

        return new Response($octets, Response::HTTP_OK, [
            'Content-Type' => $piece->getMimeType(),
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Correction d'un dossier renvoye par le comptable (statut Correction requise) :
     * met a jour les champs, remplace les pieces re-jointes, puis re-soumet le dossier.
     */
    #[Route('/mes-dossiers/{id}/corriger', name: 'app_remboursement_mon_dossier_corriger', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function corriger(Dossier $dossier, Request $request): Response
    {
        $this->assertProprietaire($dossier);
        if (!$this->isCsrfTokenValid('remboursement_corriger'.$dossier->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        if (DossierStatut::COMPLEMENT_REQUIS !== $dossier->getStatut()) {
            $this->addFlash('error', 'Ce dossier n\'est pas en correction.');

            return $this->redirectToRoute('app_remboursement_mon_dossier', ['id' => $dossier->getId()]);
        }

        $par = $this->getUser()?->getUserIdentifier();
        $piecesACorriger = $dossier->getCorrectionPieces() ?? [];

        // Valeurs AVANT (pour reperer ce que la secretaire change).
        $avant = self::valeursClient($dossier);

        $dossier->setNomClient(trim((string) $request->request->get('nom_client', $dossier->getNomClient())));
        if (null !== ($iban = self::texte($request->request->get('iban_client')))) {
            $dossier->setIbanClient($iban);
        }
        $dossier->setBicClient(self::texte($request->request->get('bic_client')));
        $montant = (string) $request->request->get('montant', '');
        if ('' !== $montant && null !== ($m = $this->normalisation->montant($montant))) {
            $dossier->setMontant($m);
        }
        if (DossierMotif::RACHAT_SEC === $dossier->getMotif()
            && null !== ($immat = $this->normalisation->immatriculation(self::texte($request->request->get('immatriculation'))))) {
            $dossier->setImmatriculation($immat);
        }
        // Code client ICAR : corrigeable aux DEUX motifs depuis que le rachat sec l'exige.
        // Au rachat il etait auparavant ignore, la branche « sinon » ne s'executant pas.
        if (null !== ($icar = self::texte($request->request->get('code_icar')))) {
            $dossier->setCodeIcar($icar);
        }
        // Adresse en copie : le champ est soumis meme vide, ce qui doit permettre de la
        // RETIRER. Une valeur mal formee est ignoree plutot que rejetee — la correction
        // porte sur les pieces, on ne bloque pas la secretaire pour un champ facultatif.
        if ($request->request->has('email_copie')) {
            $copie = trim((string) $request->request->get('email_copie', ''));
            if ('' === $copie || filter_var($copie, \FILTER_VALIDATE_EMAIL)) {
                $dossier->setEmailCopie('' !== $copie ? $copie : null);
            }
        }
        // La cle anti-doublon depend de l'immat/ICAR : on la rafraichit apres correction.
        $dossier->setCleDoublon(CleDoublon::pour($dossier));

        // Champs client modifies -> traces pour le comptable.
        $apres = self::valeursClient($dossier);
        $modifies = [];
        foreach ($avant as $cle => $valeur) {
            if (trim($valeur) !== trim($apres[$cle])) {
                $modifies[] = $cle;
            }
        }
        $dossier->setCorrectionChamps($modifies);

        // Pieces re-jointes : seulement celles demandees en correction (si liste fournie).
        foreach (array_keys($dossier->getMotif()->piecesRequises()) as $type) {
            if ([] !== $piecesACorriger && !\in_array($type, $piecesACorriger, true)) {
                continue;
            }
            $fichier = $request->files->get($type);
            if (!$fichier instanceof UploadedFile) {
                continue;
            }
            foreach ($this->pieces->pourDossier($dossier) as $ancienne) {
                if ($ancienne->getType() === $type) {
                    $this->em->remove($ancienne);
                }
            }
            $meta = $this->stockage->stocker($dossier, $type, $fichier);
            $this->em->persist(new DossierPiece($dossier, $type, $meta['nomOriginal'], $meta['contenu'], $meta['mime'], $meta['taille'], $meta['hash'], $par));
        }
        $this->em->flush();

        // Renvoi DIRECT en verification comptable (pas de re-analyse IA).
        $this->workflow->appliquer($dossier, 'renvoyer_correction', $par, 'Correction apportée par la secrétaire.');

        // Comme un nouveau dossier a verifier : cloche + insertion en direct dans le
        // dashboard comptable (sinon le dossier corrige n'y apparait qu'au rechargement).
        $this->notifier->signalerAVerifier($dossier);

        $this->addFlash('success', 'Votre correction a bien été envoyée.');

        return $this->redirectToRoute('app_remboursement_mes_dossiers');
    }

    /** La secretaire n'accede qu'a SES propres dossiers. */
    private function assertProprietaire(Dossier $dossier): void
    {
        if ($dossier->getCreePar() !== $this->getUser()?->getUserIdentifier()) {
            throw $this->createAccessDeniedException('Ce dossier ne vous appartient pas.');
        }
    }

    /**
     * Identite du secretaire connecte (JSON). Utilise par l'accueil "Bonjour Prenom"
     * affiche apres la connexion en popup, sans recharger la page de depot.
     */
    #[Route('/moi', name: 'app_remboursement_moi', methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    public function moi(): JsonResponse
    {
        $user = $this->getUser();

        return $this->json([
            'prenom' => $user instanceof User ? $user->getFirstName() : '',
            'nom' => $user instanceof User ? $user->getLastName() : '',
            'email' => $user?->getUserIdentifier(),
            'avatar' => $user instanceof User ? $user->getAvatarUrl() : null,
        ]);
    }

    /**
     * Validation serveur des champs requis selon le motif.
     *
     * @return list<string>
     */
    private function valider(Request $request, DossierMotif $motif): array
    {
        $erreurs = [];
        if ('' === trim((string) $request->request->get('nom_client', ''))) {
            $erreurs[] = 'Le nom du client est requis.';
        }
        if ('' === trim((string) $request->request->get('etablissement', ''))) {
            $erreurs[] = 'L\'établissement est requis.';
        }
        $montantNorm = $this->normalisation->montant((string) $request->request->get('montant', '0'));
        if (null === $montantNorm || (float) $montantNorm <= 0.0) {
            $erreurs[] = 'Le montant doit être strictement positif.';
        }
        if ('' === trim((string) $request->request->get('iban_client', ''))) {
            $erreurs[] = 'L\'IBAN du client est requis.';
        }
        if ('' === trim((string) $request->request->get('bic_client', ''))) {
            $erreurs[] = 'Le BIC du client est requis.';
        }
        // Cle d'identite : l'immatriculation n'existe qu'au rachat, le code client ICAR est
        // exige aux DEUX motifs (il descend jusqu'a la colonne codeClientICAR du CSV compta).
        if (DossierMotif::RACHAT_SEC === $motif && '' === trim((string) $request->request->get('immatriculation', ''))) {
            $erreurs[] = 'L\'immatriculation est requise.';
        }
        if ('' === trim((string) $request->request->get('code_icar', ''))) {
            $erreurs[] = 'Le code client ICAR est requis.';
        }
        // Adresse en copie : FACULTATIVE, donc vide reste valide. Remplie, elle doit etre
        // une vraie adresse — l'attestation part dessus, et une faute de frappe
        // l'enverrait a un inconnu avec le nom du client et le montant.
        $copie = trim((string) $request->request->get('email_copie', ''));
        if ('' !== $copie && !filter_var($copie, \FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'L\'adresse en copie n\'est pas une adresse e-mail valide.';
        }

        // Controle anti-surpaiement Buy Back (rachat sec) : plaque connue + montant qui
        // depasse l'engagement de reprise TTC de plus de 3 EUR => blocage dur.
        if (DossierMotif::RACHAT_SEC === $motif && null !== $montantNorm) {
            $c = $this->controleBuyBack->pour(self::texte($request->request->get('immatriculation')), (float) $montantNorm);
            if (null !== $c && $c['surpaiement']) {
                $vehicule = $c['vehicule'];
                $erreurs[] = sprintf(
                    'Montant %s € supérieur de %s € à l\'engagement de reprise (ER TTC %s €) pour %s %s (%s). Vérifiez le montant avant de déposer.',
                    number_format((float) $montantNorm, 2, ',', ' '),
                    number_format((float) $c['ecart'], 2, ',', ' '),
                    number_format($c['erTtc'], 2, ',', ' '),
                    trim((string) $vehicule->getMarque()),
                    trim((string) $vehicule->getModele()),
                    $vehicule->getImmat(),
                );
            }
        }

        // Trop-percu PREREMPLI depuis le miroir (compte + montant + ICAR inchanges) : seul le
        // releve de compte ICAR n'est PAS requis (la donnee vient de la base). Les PETITS COMPTES
        // restent REQUIS dans tous les cas (justificatif). Saisie manuelle / modif -> releve requis.
        $piecesFacultatives = null !== $this->compteSourceVerifie($request, $motif) ? ['releve_icar'] : [];

        foreach ($motif->piecesRequises() as $type => $libelle) {
            $fichier = $request->files->get($type);
            if (!$fichier instanceof UploadedFile) {
                if (!\in_array($type, $piecesFacultatives, true)) {
                    $erreurs[] = sprintf('Pièce manquante : %s.', $libelle);
                }
                continue;
            }
            // Hors "petits comptes" (converti en PDF au depot) : seuls PDF/image acceptes.
            if ('petits_comptes' !== $type && !self::estPdfOuImage($fichier)) {
                $erreurs[] = sprintf('%s : format refusé (PDF ou image uniquement).', $libelle);
            }
        }

        return $erreurs;
    }

    /**
     * Compte Gestion commerciale retenu SI le dépôt trop-percu a été prérempli et NON modifié (montant +
     * code ICAR identiques a la base). null sinon (rachat, saisie manuelle, ou valeur modifiee).
     * Re-verifie contre la base => non falsifiable. Sert a rendre les 2 pieces facultatives ET a
     * orienter le controle IA (la compta = source de verite).
     */
    private function compteSourceVerifie(Request $request, DossierMotif $motif): ?string
    {
        if (DossierMotif::TROP_PERCU !== $motif) {
            return null;
        }
        $compte = trim((string) $request->request->get('eloficash_compte', ''));
        if ('' === $compte) {
            return null;
        }

        $tp = $this->eloficash->tropPercu($compte);
        $montantNorm = $this->normalisation->montant((string) $request->request->get('montant', ''));
        $icarSaisi = (string) preg_replace('/\D+/', '', (string) $request->request->get('code_icar', ''));

        return $tp['credit'] && $tp['montant'] === $montantNorm && $tp['icar'] === $icarSaisi ? $compte : null;
    }

    private static function estPdfOuImage(UploadedFile $fichier): bool
    {
        $mime = (string) $fichier->getMimeType();

        return 'application/pdf' === $mime || str_starts_with($mime, 'image/');
    }

    private static function texte(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }

    /**
     * Valeurs client actuelles (comparaison avant/apres correction).
     *
     * @return array{nom: string, iban: string, bic: string, montant: string, immatriculation: string, code_icar: string}
     */
    private static function valeursClient(Dossier $d): array
    {
        return [
            'nom' => (string) $d->getNomClient(),
            'iban' => (string) $d->getIbanClient(),
            'bic' => (string) $d->getBicClient(),
            'montant' => (string) $d->getMontant(),
            'immatriculation' => (string) $d->getImmatriculation(),
            'code_icar' => (string) $d->getCodeIcar(),
        ];
    }
}
