<?php

declare(strict_types=1);

namespace App\Recouvrement\Controller;

use App\Recouvrement\Entity\FactureSite;
use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Entity\RetourClient;
use App\Recouvrement\Entity\RetourPieceJointe;
use App\Recouvrement\Enum\CompteExclusionEtat;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Journal\JourJournal;
use App\Recouvrement\Message\EnvoyerRelance;
use App\Recouvrement\Referentiel\Etablissements;
use App\Recouvrement\Repository\CompteExclusionRepository;
use App\Recouvrement\Repository\DemandeSiteRepository;
use App\Recouvrement\Repository\FactureSiteRepository;
use App\Recouvrement\Repository\MessageSortantRepository;
use App\Recouvrement\Repository\RecouvrementRepository;
use App\Recouvrement\Repository\RelanceEnvoiRepository;
use App\Recouvrement\Repository\RetourClientRepository;
use App\Recouvrement\Repository\RetourPieceJointeRepository;
use App\Recouvrement\Service\AnnuaireClientService;
use App\Recouvrement\Service\EnvoiRelanceService;
use App\Recouvrement\Service\FicheClientService;
use App\Recouvrement\Service\LettreCourrierPdfService;
use App\Recouvrement\Service\PdfFactureProvider;
use App\Recouvrement\Service\ReponseRetourService;
use App\Recouvrement\Service\SelectionRelanceService;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use ZipArchive;

/**
 * Module Recouvrement : vue comptable des retours clients à traiter.
 *
 * L'écran principal (route racine) est la liste des réponses des clients aux
 * relances (recouvrement.retour_client, non traités). C'est le poste de travail
 * quotidien du comptable. La liste des factures impayées reste accessible en
 * vue secondaire (réservée au manager).
 *
 * Accès : ROLE_COMPTABLE ou ROLE_MANAGER (ROLE_MANAGER n'hérite pas de
 * ROLE_COMPTABLE dans la hiérarchie, on autorise donc les deux explicitement).
 *
 * @phpstan-import-type GroupeARelancer from SelectionRelanceService
 */
#[Route('/recouvrement')]
final class RecouvrementController extends AbstractController
{
    private const PAR_PAGE_RETOURS = 35;
    private const PAR_PAGE_IMPAYES = 50;
    private const PAR_PAGE_COURRIERS = 35;
    private const PAR_PAGE_RELANCER = 30;
    private const PAR_PAGE_ECRITURES = 50;

    /** Taille max d'une pièce jointe de réponse (octets). */
    private const TAILLE_MAX_PIECE = 15_000_000;

    public function __construct(
        private readonly RetourClientRepository $retours,
        private readonly RecouvrementRepository $impayes,
        private readonly RelanceEnvoiRepository $relances,
        private readonly MessageSortantRepository $messagesSortants,
        private readonly CompteExclusionRepository $exclusions,
        private readonly RetourPieceJointeRepository $piecesJointes,
        private readonly FactureSiteRepository $facturesSite,
        private readonly DemandeSiteRepository $demandesSite,
        private readonly FicheClientService $fiches,
    ) {
    }

    /**
     * Fiche client complète : identité (tiers), encours + buckets de retard, écritures
     * et timeline des échanges. Chargée en pleine page ou en Turbo Frame depuis la
     * liste Relance & Curation.
     */
    #[Route('/client/{compte}', name: 'app_recouvrement_fiche_client', requirements: ['compte' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function ficheClient(string $compte, Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        $fiche = $this->fiches->assembler($compte);
        if (null === $fiche) {
            throw $this->createNotFoundException('Compte introuvable.');
        }

        $tri = self::triEcritures($request);
        [$etab, $societe] = self::filtresEcritures($request);
        $total = $this->fiches->compterEcritures($compte, $etab, $societe);

        return $this->render('recouvrement/fiche_client.html.twig', [
            'fiche' => $fiche,
            'ecritures' => $this->fiches->ecritures($compte, $tri, 1, self::PAR_PAGE_ECRITURES, $etab, $societe),
            'tri' => $tri,
            'etab' => $etab,
            'soc' => $societe,
            'etablissements' => $this->fiches->etablissementsDuCompte($compte, $societe),
            'societes' => $this->fiches->societesDuCompte($compte),
            'total_ecritures' => $total,
            'total_pages' => max(1, (int) ceil($total / self::PAR_PAGE_ECRITURES)),
        ]);
    }

    /**
     * Fragment d'APERCU (drawer lateral de la vue Clients) : version condensee de la
     * fiche (identite, encours, derniers echanges) chargee en AJAX sans quitter la
     * recherche.
     */
    #[Route('/client/{compte}/apercu', name: 'app_recouvrement_fiche_apercu', requirements: ['compte' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function apercuClient(string $compte): Response
    {
        $this->denyUnlessRecouvrement();

        $fiche = $this->fiches->assembler($compte);
        if (null === $fiche) {
            throw $this->createNotFoundException('Compte introuvable.');
        }

        return $this->render('recouvrement/_fiche_apercu.html.twig', ['fiche' => $fiche]);
    }

    /**
     * Fragment des ecritures d'une fiche (scroll infini + tri), pour ne pas charger
     * des centaines de lignes d'un coup.
     */
    #[Route('/client/{compte}/ecritures', name: 'app_recouvrement_fiche_ecritures', requirements: ['compte' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function ficheEcritures(string $compte, Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        $page = max(1, $request->query->getInt('page', 1));
        [$etab, $societe] = self::filtresEcritures($request);

        return $this->render('recouvrement/_fiche_ecritures.html.twig', [
            'compte' => $compte,
            'ecritures' => $this->fiches->ecritures($compte, self::triEcritures($request), $page, self::PAR_PAGE_ECRITURES, $etab, $societe),
        ]);
    }

    /**
     * Configure le gel d'UNE facture depuis la fiche client (transfert au site,
     * mise en pause ou réactivation) puis redirige vers la fiche.
     */
    #[Route('/facture/configurer', name: 'app_recouvrement_facture_configurer', methods: ['POST'])]
    public function configurerFactureFiche(Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        if (!$this->isCsrfTokenValid('facture-configurer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $ecriture = trim((string) $request->request->get('ecriture'));
        $action = (string) $request->request->get('action');
        $compte = trim((string) $request->request->get('compte'));
        $par = $this->nomOperateur();
        $etat = null;

        if ('' !== $ecriture) {
            if ('degel' === $action) {
                $etat = $this->facturesSite->parEcriture($ecriture);
                if (null !== $etat && $etat->isActif()) {
                    $etat->reactiver($par);
                    $this->facturesSite->save($etat);
                }
            } elseif (\in_array($action, [FactureSite::TYPE_SITE, FactureSite::TYPE_NE_PAS_RELANCER], true)) {
                $codeSite = self::texteOuNull($request->request->get('code_site'));
                $etat = $this->facturesSite->parEcriture($ecriture) ?? new FactureSite($ecriture, $compte, $action, $par);
                $etat->geler($action, $codeSite, $par);
                $etat->setDemandeSiteId(
                    FactureSite::TYPE_SITE === $action && null !== $codeSite
                        ? $this->demandesSite->ouvrirPour($compte, $codeSite, $par)->getId()
                        : null,
                );
                $this->facturesSite->save($etat);
            }
        }

        if ('' === $compte && null !== $etat) {
            $compte = $etat->getCompteCode();
        }

        return '' !== $compte
            ? $this->redirectToRoute('app_recouvrement_fiche_client', ['compte' => $compte])
            : $this->redirectToRoute('app_recouvrement_relancer');
    }

    /** Tri des ecritures de fiche, en liste blanche. */
    private static function triEcritures(Request $request): string
    {
        $tri = (string) $request->query->get('tri', 'echeance');

        return \in_array($tri, ['echeance', 'retard', 'montant', 'libelle'], true) ? $tri : 'echeance';
    }

    /**
     * Filtres des ecritures d'une fiche : `etab` = code etablissement (codeetab, '093'),
     * `soc` = code societe (code_entite, 'TAM'). Compatibilite : `etab` portait avant
     * la societe, donc une valeur non numerique est basculee sur le filtre societe pour
     * qu'un ancien lien (?etab=TAM) continue de filtrer au lieu de ne rien renvoyer.
     *
     * @return array{0: string, 1: string} [etablissement, societe]
     */
    private static function filtresEcritures(Request $request): array
    {
        $etab = trim((string) $request->query->get('etab', ''));
        $societe = trim((string) $request->query->get('soc', ''));

        if ('' !== $etab && null === Etablissements::normaliserCode($etab)) {
            return ['', '' !== $societe ? $societe : $etab];
        }

        return [$etab, $societe];
    }

    /**
     * Vue comptable : les retours clients non traités, du plus récent au plus
     * ancien (scroll infini). Le fragment `?fragment=1` ne rend que les lignes.
     */
    #[Route('', name: 'app_recouvrement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        $estFragment = $request->query->getBoolean('fragment');
        $page = max(1, $request->query->getInt('page', 1));

        // Filtres : recherche (email ou compte), plage de dates, sens de tri, et
        // rattachement (rattaches par defaut = on masque les orphelins de la liste
        // principale ; orphelins = reponses non reliees a une relance ; tous).
        $q = trim((string) $request->query->get('q', ''));
        $dir = 'asc' === $request->query->get('dir') ? 'asc' : 'desc';
        $duStr = trim((string) $request->query->get('du', ''));
        $auStr = trim((string) $request->query->get('au', ''));
        $du = self::parseDateFiltre($duStr);
        $au = self::parseDateFiltre($auStr, true);
        // Orphelins jamais affichés (décision métier) : on force les rattachés, quel
        // que soit le paramètre. L'auto-réponse spontanée reste envoyée à l'expéditeur.
        $filtre = 'rattaches';

        $rendu = [
            'conversations' => $this->retours->findConversationsNonTraitees(
                $page,
                self::PAR_PAGE_RETOURS,
                '' !== $q ? $q : null,
                $du,
                $au,
                $dir,
                $filtre,
            ),
        ];

        if ($estFragment) {
            return $this->render('recouvrement/_rows.html.twig', $rendu);
        }

        $total = $this->retours->compterConversations('' !== $q ? $q : null, $du, $au, $filtre);
        $totalPages = max(1, (int) ceil($total / self::PAR_PAGE_RETOURS));

        return $this->render('recouvrement/index.html.twig', $rendu + [
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'nb_courriers' => $this->relances->compterCourriersEnAttente(),
            'q' => $q,
            'du' => $duStr,
            'au' => $auStr,
            'dir' => $dir,
            'filtre' => $filtre,
        ]);
    }

    /**
     * Rend la ligne de CONVERSATION d'un retour (fragment) pour l'insertion /
     * mise a jour temps reel dans la liste. Requete authentifiee : le jeton CSRF y
     * est donc valide. Vide si la conversation est desormais entierement traitee.
     */
    #[Route('/retours/{id}/row', name: 'app_recouvrement_retour_row', requirements: ['id' => '\d+|__ID__'], methods: ['GET'])]
    public function retourRow(int $id): Response
    {
        $this->denyUnlessRecouvrement();

        $conversation = $this->retours->findConversationParRetour($id);
        if (null === $conversation) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('recouvrement/_rows.html.twig', ['conversations' => [$conversation]]);
    }

    /**
     * Télécharge une pièce jointe reçue avec un retour client (contenu stocké en
     * base). Vérifie qu'elle appartient bien au retour demandé.
     */
    #[Route('/retours/{id}/piece/{pieceId}', name: 'app_recouvrement_retour_piece', requirements: ['id' => '\d+', 'pieceId' => '\d+'], methods: ['GET'])]
    public function retourPiece(int $id, int $pieceId): Response
    {
        $this->denyUnlessRecouvrement();

        $piece = $this->piecesJointes->find($pieceId);
        if (!$piece instanceof RetourPieceJointe || $piece->getRetour()->getId() !== $id) {
            throw $this->createNotFoundException('Pièce jointe introuvable.');
        }

        $type = $piece->getTypeMime() ?? 'application/octet-stream';
        $contenu = $piece->getContenuBinaire();

        // Inline (s'ouvre dans le navigateur) UNIQUEMENT pour images bitmap + PDF.
        // Le SVG est EXCLU : il peut embarquer du JS -> XSS stocke dans l'origine de
        // l'app si un client envoie un SVG piege et que la comptable l'ouvre. Tout le
        // reste (dont SVG, HTML...) se telecharge. nosniff empeche le MIME sniffing.
        $inline = 'application/pdf' === $type
            || (str_starts_with($type, 'image/') && 'image/svg+xml' !== $type);
        $disposition = HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $piece->getNom(),
            'piece-jointe',
        );

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) \strlen($contenu),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Parse une date de filtre "Y-m-d" (format des input type=date). null si vide
     * ou invalide. $finDeJournee ramene a 23:59:59 (borne haute inclusive).
     */
    private static function parseDateFiltre(string $valeur, bool $finDeJournee = false): ?DateTimeImmutable
    {
        if ('' === $valeur) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        if (false === $date) {
            return null;
        }

        return $finDeJournee ? $date->setTime(23, 59, 59) : $date;
    }

    /**
     * Cle de conversation d'un retour : code compte s'il existe, sinon l'email de
     * l'expediteur (meme regle que le SQL COALESCE(NULLIF(compte_code,''), expediteur)).
     */
    private static function cleConversation(RetourClient $retour): string
    {
        $compte = trim((string) $retour->getCompteCode());

        return '' !== $compte ? $compte : (string) $retour->getExpediteur();
    }

    /**
     * Fragment du panneau détail : corps du retour + relance d'origine +
     * formulaire de traitement. Chargé par le contrôleur Stimulus detail-panel.
     */
    // Le placeholder "__ID__" doit pouvoir generer l'URL cote panneau detail
    // (le controleur Stimulus le remplace par l'id reel) : on tolere donc soit
    // un id numerique, soit ce placeholder.
    #[Route('/retours/{id}', name: 'app_recouvrement_retour_detail', requirements: ['id' => '\d+|__ID__'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $this->denyUnlessRecouvrement();

        $retour = $this->retours->find($id);
        if (!$retour instanceof RetourClient) {
            throw $this->createNotFoundException('Retour introuvable.');
        }

        return $this->rendreDetail($retour);
    }

    /**
     * Traite un retour : marque traité + commentaire. Renvoie le fragment de la
     * ligne mise à jour (qui disparaît de la liste des non traités).
     * (La catégorisation sera gérée par l'IA dans une phase ultérieure.).
     */
    #[Route('/retours/{id}/traiter', name: 'app_recouvrement_retour_traiter', requirements: ['id' => '\d+|__ID__'], methods: ['POST'])]
    public function traiter(int $id, Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        // Jeton fixe (non lie a l'id) : la cloture part de la modale du header, qui
        // ne connait pas l'id au rendu. Le CSRF protege la session ; l'id est dans
        // l'URL et re-verifie ci-dessous.
        if (!$this->isCsrfTokenValid('recouvrement-cloture', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $retour = $this->retours->find($id);
        if (!$retour instanceof RetourClient) {
            throw $this->createNotFoundException('Retour introuvable.');
        }

        $commentaire = trim((string) $request->request->get('commentaire'));
        if (\strlen($commentaire) > 5000) {
            $commentaire = substr($commentaire, 0, 5000);
        }

        $utilisateur = $this->getUser();
        $traitePar = $utilisateur instanceof User ? $utilisateur->getFullName() : null;

        // Traiter = clore la conversation entiere : le message courant (avec la note
        // interne) puis tous les autres messages non traites du meme client.
        $this->retours->marquerTraite($retour, $traitePar, '' !== $commentaire ? $commentaire : null);
        $this->retours->marquerConversationTraitee(self::cleConversation($retour), $traitePar);

        // Cloture via la modale (fetch) : 204 -> la JS ferme le panneau et retire la
        // ligne. Repli non-JS : fragment detail re-rendu.
        if ($request->isXmlHttpRequest()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->rendreDetail($retour, ['traite_succes' => true]);
    }

    /**
     * Répond au client depuis le panneau détail (texte + pièce jointe optionnelle),
     * envoie l'email puis clôture le retour. Renvoie le fragment détail mis à jour.
     */
    #[Route('/retours/{id}/repondre', name: 'app_recouvrement_retour_repondre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function repondre(int $id, Request $request, ReponseRetourService $reponseService): Response
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('retour-repondre-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $retour = $this->retours->find($id);
        if (!$retour instanceof RetourClient) {
            throw $this->createNotFoundException('Retour introuvable.');
        }

        // NB : on n'interdit PAS de répondre à un retour déjà traité — une conversation
        // se règle en un ou plusieurs échanges, il faut toujours pouvoir renvoyer un
        // message. Le double-clic est déjà neutralisé côté client (bouton désactivé au
        // submit, cf. reponse_controller.js).
        $message = trim((string) $request->request->get('message'));
        if ('' === $message) {
            // 422 (et non 200) : Turbo doit voir un echec pour NE PAS fermer le panneau.
            return $this->rendreDetail($retour, ['erreur' => 'Le message ne peut pas être vide.'])
                ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pieces = [];
        foreach ($request->files->all('pieces') as $fichier) {
            if (!$fichier instanceof UploadedFile) {
                continue;
            }
            if (!$fichier->isValid() || $fichier->getSize() > self::TAILLE_MAX_PIECE) {
                return $this->rendreDetail($retour, ['erreur' => 'Pièce jointe invalide ou trop volumineuse (max 15 Mo par fichier).'])
                    ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $pieces[] = $fichier;
        }

        $cc = $this->extraireEmails((string) $request->request->get('cc'));
        $cci = $this->extraireEmails((string) $request->request->get('cci'));

        $utilisateur = $this->getUser();
        $auteur = $utilisateur instanceof User ? $utilisateur->getFullName() : null;

        try {
            $reponseService->repondre($retour, $message, $pieces, $auteur, $cc, $cci);
        } catch (Throwable $e) {
            return $this->rendreDetail($retour, ['erreur' => 'Échec de l\'envoi : '.$e->getMessage()])
                ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Repondre clot toute la conversation (le message courant + les autres).
        $this->retours->marquerConversationTraitee(self::cleConversation($retour), $auteur);

        return $this->rendreDetail($retour, ['reponse_succes' => true]);
    }

    /**
     * Vue secondaire (manager) : les factures clients impayées échues. Source =
     * vue recouvrement.v_impayes (lecture seule). Fragment scroll infini conservé.
     */
    #[Route('/impayes', name: 'app_recouvrement_impayes', methods: ['GET'])]
    public function impayes(Request $request): Response
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            throw $this->createAccessDeniedException();
        }

        $estFragment = $request->query->getBoolean('fragment');
        $page = max(1, $request->query->getInt('page', 1));

        $total = 0;
        $totalPages = 1;
        if (!$estFragment) {
            $total = $this->impayes->compter();
            $totalPages = max(1, (int) ceil($total / self::PAR_PAGE_IMPAYES));
            $page = min($page, $totalPages);
        }

        $rendu = [
            'lignes' => $this->impayes->page($page, self::PAR_PAGE_IMPAYES),
            'numerotation_offset' => ($page - 1) * self::PAR_PAGE_IMPAYES,
        ];

        if ($estFragment) {
            return $this->render('recouvrement/_impayes_rows.html.twig', $rendu);
        }

        return $this->render('recouvrement/impayes.html.twig', $rendu + [
            'synthese' => $this->impayes->synthese(),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'nb_courriers' => $this->relances->compterCourriersEnAttente(),
        ]);
    }

    /**
     * Journal des relances : ce qui est parti (ou a échoué / été annulé) un jour
     * donné, un jour à la fois (flèches précédent / suivant, jamais dans le futur).
     * Le bloc "en attente" (relances à envoyer / courriers à poster) n'est montré
     * que pour aujourd'hui (une programmée n'appartient pas à un jour passé).
     * Accès comptable ou manager.
     */
    #[Route('/journal', name: 'app_recouvrement_journal', methods: ['GET'])]
    public function journal(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        $jour = JourJournal::depuis(
            $request->query->getString('jour'),
            new DateTimeImmutable('today'),
        );

        return $this->render('recouvrement/journal.html.twig', [
            'jour' => $jour,
            'lignes' => $this->relances->findDuJour($jour->debut(), $jour->fin()),
            'synthese' => $this->relances->syntheseDuJour($jour->debut(), $jour->fin()),
            'en_attente' => $jour->estAujourdhui() ? $this->relances->findEnAttente() : [],
            'nb_courriers' => $this->relances->compterCourriersEnAttente(),
        ]);
    }

    /**
     * Vue "Courriers" : relances de vecteur COURRIER (clients sans email).
     * Deux onglets via ?vue= :
     *   - a_envoyer (defaut) : la pile a poster + barre de synthese ;
     *   - envoyes            : l'historique pagine (scroll infini, ?fragment=1).
     * Le comptable telecharge la lettre puis marque "poste" en un geste.
     * Acces comptable ou manager.
     */
    #[Route('/courriers', name: 'app_recouvrement_courriers', methods: ['GET'])]
    public function courriers(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        $vue = 'envoyes' === $request->query->get('vue') ? 'envoyes' : 'a_envoyer';
        $nbEnAttente = $this->relances->compterCourriersEnAttente();
        $nbEnvoyes = $this->relances->compterCourriersEnvoyes();

        if ('envoyes' === $vue) {
            $page = max(1, $request->query->getInt('page', 1));
            $envoyes = $this->relances->findCourriersEnvoyes($page, self::PAR_PAGE_COURRIERS);

            if ($request->query->getBoolean('fragment')) {
                return $this->render('recouvrement/_courriers_envoyes_rows.html.twig', ['courriers' => $envoyes]);
            }

            return $this->render('recouvrement/courriers.html.twig', [
                'vue' => 'envoyes',
                'courriers_envoyes' => $envoyes,
                'page' => $page,
                'total_pages' => max(1, (int) ceil($nbEnvoyes / self::PAR_PAGE_COURRIERS)),
                'nb_en_attente' => $nbEnAttente,
                'nb_envoyes' => $nbEnvoyes,
                'total' => $this->retours->compterNonTraites(),
            ]);
        }

        return $this->render('recouvrement/courriers.html.twig', [
            'vue' => 'a_envoyer',
            'courriers' => $this->relances->findCourriersEnAttente(),
            'synthese' => $this->relances->syntheseEnAttente(),
            'nb_en_attente' => $nbEnAttente,
            'nb_envoyes' => $nbEnvoyes,
            'total' => $this->retours->compterNonTraites(),
        ]);
    }

    /**
     * Télécharge le LOT des courriers en attente sous forme de ZIP : un PDF complet
     * (relevé + factures) par client. On regroupe les PDF déjà pré-générés par la
     * machine interne, sans les fusionner -> aucun Ghostscript requis côté web
     * (Render). Repli à la volée (relevé seul) pour un courrier pas encore généré.
     */
    #[Route('/courriers/lot.zip', name: 'app_recouvrement_courriers_lot', methods: ['GET'])]
    public function courriersLot(LettreCourrierPdfService $lettrePdf): Response
    {
        $this->denyUnlessRecouvrement();

        $chemin = (string) tempnam(sys_get_temp_dir(), 'courriers_');
        $zip = new ZipArchive();
        $zip->open($chemin, ZipArchive::OVERWRITE);
        foreach ($this->relances->findCourriersEnAttente() as $courrier) {
            $pdf = $courrier->getCourrierPdf() ?? $lettrePdf->lettre($courrier);
            $nom = sprintf('relance-%s-niveau%d.pdf', preg_replace('/[^A-Za-z0-9_-]+/', '-', $courrier->getCompteCode()), $courrier->getNiveau());
            $zip->addFromString($nom, $pdf);
        }
        $zip->close();
        $contenu = (string) file_get_contents($chemin);
        @unlink($chemin);

        $nomZip = sprintf('courriers-recouvrement-%s.zip', (new DateTimeImmutable())->format('Ymd'));

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $nomZip),
            'Content-Length' => (string) \strlen($contenu),
        ]);
    }

    /**
     * Télécharge la lettre PDF d'un courrier (un client).
     */
    #[Route('/courriers/{id}/lettre.pdf', name: 'app_recouvrement_courrier_lettre', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function courrierLettre(int $id, LettreCourrierPdfService $lettrePdf): Response
    {
        $this->denyUnlessRecouvrement();

        $courrier = $this->relances->find($id);
        if (!$courrier instanceof RelanceEnvoi) {
            throw $this->createNotFoundException('Courrier introuvable.');
        }

        // Le PDF complet (relevé + factures) est pré-généré par la machine interne
        // (accès Progiciel + Ghostscript) et stocké : le web le sert tel quel. Repli sur
        // une génération à la volée (relevé seul si Progiciel/gs indisponibles ici).
        $pdf = $courrier->getCourrierPdf() ?? $lettrePdf->lettre($courrier);
        $nom = sprintf('relance-%s-niveau%d.pdf', preg_replace('/[^A-Za-z0-9_-]+/', '-', $courrier->getCompteCode()), $courrier->getNiveau());

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $nom),
        ]);
    }

    /**
     * Marque un courrier comme posté (statut ENVOYE + opérateur + date). Délégué
     * au repository (idempotent). Répond en JSON pour le geste « Imprimer et
     * marquer posté » (JS), avec repli redirection sans JS.
     */
    #[Route('/courriers/{id}/envoye', name: 'app_recouvrement_courrier_envoye', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function courrierEnvoye(int $id, Request $request, LettreCourrierPdfService $lettrePdf): Response
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('courrier-envoye-'.$id, (string) $request->request->get('_token'))) {
            return $this->reponseCourrier($request, false, 'Jeton CSRF invalide.', Response::HTTP_FORBIDDEN);
        }

        $courrier = $this->relances->find($id);
        if (!$courrier instanceof RelanceEnvoi) {
            return $this->reponseCourrier($request, false, 'Courrier introuvable.', Response::HTTP_NOT_FOUND);
        }

        $dejaPoste = RelanceStatut::ENVOYE === $courrier->getStatut();
        // Snapshot du document reellement emis (valeur probante), fige au marquage.
        $snapshot = $dejaPoste ? null : $lettrePdf->lettreHtml($courrier);
        $this->relances->marquerEnvoye($courrier, $this->nomOperateur(), $this->idOperateur(), $snapshot);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => true,
                'deja' => $dejaPoste,
                'par' => $courrier->getEnvoyePar(),
                'le' => $courrier->getEnvoyeLe()?->format('d/m/Y à H\\hi'),
            ]);
        }

        $this->addFlash('success', 'Courrier marqué comme posté.');

        return $this->redirectToRoute('app_recouvrement_courriers');
    }

    /**
     * Remet un courrier au statut "à envoyer" (annulation / rattrapage d'un
     * marquage erroné). Délégué au repository (idempotent). JSON pour le JS
     * (toast « Annuler », bouton « Remettre à envoyer »), repli redirection.
     */
    #[Route('/courriers/{id}/remettre', name: 'app_recouvrement_courrier_remettre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function courrierRemettre(int $id, Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('courrier-remettre-'.$id, (string) $request->request->get('_token'))) {
            return $this->reponseCourrier($request, false, 'Jeton CSRF invalide.', Response::HTTP_FORBIDDEN);
        }

        $courrier = $this->relances->find($id);
        if (!$courrier instanceof RelanceEnvoi) {
            return $this->reponseCourrier($request, false, 'Courrier introuvable.', Response::HTTP_NOT_FOUND);
        }

        $this->relances->remettreAEnvoyer($courrier);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true]);
        }

        $this->addFlash('success', 'Courrier remis à envoyer.');

        return $this->redirectToRoute('app_recouvrement_courriers');
    }

    /**
     * Marque TOUS les courriers en attente comme postés (même opérateur, même
     * instant) : le geste de lot « Imprimer le lot ». JSON (avec les ids marqués
     * pour l'annulation), repli redirection sans JS.
     */
    #[Route('/courriers/marquer-lot', name: 'app_recouvrement_courriers_marquer_lot', methods: ['POST'])]
    public function courriersMarquerLot(Request $request, LettreCourrierPdfService $lettrePdf): Response
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('courriers-marquer-lot', (string) $request->request->get('_token'))) {
            return $this->reponseCourrier($request, false, 'Jeton CSRF invalide.', Response::HTTP_FORBIDDEN);
        }

        // Marquage en lot : on fige le snapshot de CHAQUE lettre (valeur probante)
        // puis un SEUL flush (pas de N flush). Rendu HTML uniquement, pas de PDF.
        $par = $this->nomOperateur();
        $parUserId = $this->idOperateur();
        $ids = [];
        foreach ($this->relances->findCourriersEnAttente() as $courrier) {
            $this->relances->marquerEnvoye($courrier, $par, $parUserId, $lettrePdf->lettreHtml($courrier), false);
            $ids[] = $courrier->getId();
        }
        if ([] !== $ids) {
            $this->relances->flush();
        }
        $nombre = \count($ids);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'nb' => $nombre, 'ids' => $ids]);
        }

        $this->addFlash('success', sprintf(
            '%d %s comme posté%s.',
            $nombre,
            $this->pluriel($nombre, 'courrier marqué', 'courriers marqués'),
            $nombre > 1 ? 's' : '',
        ));

        return $this->redirectToRoute('app_recouvrement_courriers');
    }

    /**
     * Annule un marquage de lot : remet à envoyer les courriers dont les ids sont
     * fournis (toast « Annuler » du geste de lot). JSON, repli redirection.
     */
    #[Route('/courriers/remettre-lot', name: 'app_recouvrement_courriers_remettre_lot', methods: ['POST'])]
    public function courriersRemettreLot(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('courriers-remettre-lot', (string) $request->request->get('_token'))) {
            return $this->reponseCourrier($request, false, 'Jeton CSRF invalide.', Response::HTTP_FORBIDDEN);
        }

        /** @var list<mixed> $brut */
        $brut = $request->request->all('ids');
        $ids = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $brut)));
        $nombre = $this->relances->remettreLotAEnvoyer($ids);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'nb' => $nombre]);
        }

        return $this->redirectToRoute('app_recouvrement_courriers');
    }

    /**
     * Réponse uniforme des actions courrier : JSON {ok:false, erreur} pour le JS,
     * sinon une exception HTTP classique (repli sans JS).
     */
    private function reponseCourrier(Request $request, bool $ok, string $message, int $statut): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => $ok, 'erreur' => $message], $statut);
        }

        if (Response::HTTP_NOT_FOUND === $statut) {
            throw $this->createNotFoundException($message);
        }

        throw $this->createAccessDeniedException($message);
    }

    /**
     * Rend le fragment détail en y joignant la timeline des échanges du client
     * (relances envoyées + réponses reçues), triée chronologiquement.
     *
     * @param array<string, mixed> $extra variables additionnelles (succès, erreur)
     */
    private function rendreDetail(RetourClient $retour, array $extra = []): Response
    {
        $timeline = [];
        $piecesParRetour = [];
        $compte = $retour->getCompteCode();
        if (null !== $compte && '' !== $compte) {
            foreach ($this->relances->findEnvoyeesParCompte($compte) as $relance) {
                $date = $relance->getEnvoyeLe() ?? $relance->getCreeLe();
                $timeline[] = ['type' => 'relance', 'ts' => $date->getTimestamp(), 'date' => $date, 'relance' => $relance];
            }
            $retoursCompte = $this->retours->findByCompte($compte);
            // Pieces jointes de TOUS les messages du client en une requete (anti N+1) :
            // une PJ recue dans un message ancien de la conversation doit rester visible.
            $idsRetours = array_values(array_filter(array_map(
                static fn (RetourClient $r): ?int => $r->getId(),
                $retoursCompte,
            )));
            $piecesParRetour = $this->piecesJointes->metaParRetours($idsRetours);
            foreach ($retoursCompte as $autre) {
                $timeline[] = [
                    'type' => 'retour',
                    'ts' => $autre->getRecuLe()->getTimestamp(),
                    'date' => $autre->getRecuLe(),
                    'retour' => $autre,
                    'courant' => $autre->getId() === $retour->getId(),
                    'pieces' => $piecesParRetour[$autre->getId()] ?? [],
                ];
            }
            foreach ($this->messagesSortants->findParCompte($compte) as $sortant) {
                $timeline[] = ['type' => 'reponse', 'ts' => $sortant->getEnvoyeLe()->getTimestamp(), 'date' => $sortant->getEnvoyeLe(), 'message' => $sortant];
            }
            // Du plus récent au plus ancien (les 2 premiers sont affichés, le reste sous « voir + »).
            usort($timeline, static fn (array $a, array $b): int => ((int) $b['ts']) <=> ((int) $a['ts']));
        }

        $nbFactures = (null !== $compte && '' !== $compte) ? $this->impayes->compterFacturesDuCompte($compte) : 0;
        // PJ du message courant : on reutilise le lot deja charge ci-dessus (compte
        // renseigne = cas normal) ; on ne requete que dans le cas rare "sans compte".
        $pieces = '' !== (string) $compte
            ? ($piecesParRetour[$retour->getId()] ?? [])
            : (null !== $retour->getId() ? $this->piecesJointes->metaParRetour($retour->getId()) : []);

        $telephones = (null !== $compte && '' !== $compte)
            ? $this->impayes->telephonesDuCompte($compte)
            : ['telephone' => null, 'portable' => null];

        return $this->render('recouvrement/_detail.html.twig', array_merge([
            'retour' => $retour,
            'timeline' => $timeline,
            'nb_factures' => $nbFactures,
            'pieces_jointes' => $pieces,
            'telephones' => $telephones,
        ], $extra));
    }

    /**
     * Fragment du panneau "factures du client" (chargé en AJAX par le contrôleur
     * Stimulus factures-panel, rendu hors du panneau détail transformé).
     */
    #[Route('/compte/{compte}/factures', name: 'app_recouvrement_compte_factures', requirements: ['compte' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function facturesDuCompte(string $compte): Response
    {
        $this->denyUnlessRecouvrement();

        return $this->rendreFactures($compte);
    }

    /**
     * Configure le gel de relance sur des factures sélectionnées : transfert au site
     * (`relance_site`), mise en pause (`ne_pas_relancer`) ou réactivation (`degel`).
     * Renvoie le fragment du panneau à jour (le JS remplace le contenu du modal).
     */
    #[Route('/compte/{compte}/factures/configurer', name: 'app_recouvrement_compte_factures_configurer', requirements: ['compte' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function configurerFacturesSite(string $compte, Request $request): Response
    {
        $this->denyUnlessRecouvrement();
        if (!$this->isCsrfTokenValid('factures-site', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $action = (string) $request->request->get('action');
        /** @var list<string> $ecritures */
        $ecritures = array_values(array_filter(
            array_map(static fn (mixed $e): string => trim((string) $e), (array) $request->request->all('ecritures')),
            static fn (string $e): bool => '' !== $e,
        ));
        // Message libre au site, indexe par ecriture_id (uniquement pour relance site).
        $notes = (array) $request->request->all('notes');
        // Cadence de relance du site (niveau DOSSIER site) : '1s'/'2s'/'3s'/'4s'/'mois'.
        $cadence = (string) $request->request->get('cadence', '2s');
        $par = $this->nomOperateur();

        if ('degel' === $action) {
            foreach ($ecritures as $ecritureId) {
                $etat = $this->facturesSite->parEcriture($ecritureId);
                if (null !== $etat && $etat->isActif()) {
                    $etat->reactiver($par);
                    $this->facturesSite->save($etat);
                }
            }
        } elseif (\in_array($action, [FactureSite::TYPE_SITE, FactureSite::TYPE_NE_PAS_RELANCER], true)) {
            // Snapshot des factures (montant/réf/échéance, codeetab = site) au moment du gel.
            $snapshot = [];
            foreach ($this->impayes->facturesDuCompte($compte) as $f) {
                $snapshot[(string) $f['ecriture_id']] = $f;
            }
            foreach ($ecritures as $ecritureId) {
                $noteSite = self::texteOuNull((string) ($notes[$ecritureId] ?? ''));
                // Message OBLIGATOIRE pour la relance site : pas de gel "site" sans consigne.
                if (FactureSite::TYPE_SITE === $action && null === $noteSite) {
                    continue;
                }
                $f = $snapshot[$ecritureId] ?? null;
                $codeSite = null !== $f ? self::texteOuNull($f['codeetab'] ?? null) : null;
                $etat = $this->facturesSite->parEcriture($ecritureId) ?? new FactureSite($ecritureId, $compte, $action, $par);
                $etat->geler($action, $codeSite, $par);
                $etat->setNoteSite(FactureSite::TYPE_SITE === $action ? $noteSite : null);
                // Rattache au dossier du site et fixe sa cadence (cadence = niveau dossier,
                // partagee par toutes les factures "relance site" de ce site).
                $demandeId = null;
                if (FactureSite::TYPE_SITE === $action && null !== $codeSite) {
                    $dossier = $this->demandesSite->ouvrirPour($compte, $codeSite, $par);
                    $dossier->setCadenceIntervalle($cadence, $par);
                    $this->demandesSite->save($dossier);
                    $demandeId = $dossier->getId();
                }
                $etat->setDemandeSiteId($demandeId);
                if (null !== $f) {
                    $etat->setSnapshot(
                        isset($f['montant_solde']) ? (string) $f['montant_solde'] : null,
                        self::texteOuNull($f['reference_facture'] ?? null) ?? self::texteOuNull($f['numpiece'] ?? null),
                        self::dateOuNull((string) ($f['date_echeance'] ?? '')),
                    );
                }
                $this->facturesSite->save($etat);
            }
        }

        return $this->rendreFactures($compte);
    }

    /** Fragment du panneau factures, enrichi de l'état de gel par facture (map ecriture_id). */
    private function rendreFactures(string $compte): Response
    {
        return $this->render('recouvrement/_factures.html.twig', [
            'compte' => $compte,
            'factures' => $this->impayes->facturesDuCompte($compte),
            'gels' => $this->facturesSite->actifsParCompteIndexes($compte),
            'cadences' => $this->demandesSite->cadencesParCompte($compte),
        ]);
    }

    private static function texteOuNull(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }

    private static function dateOuNull(string $valeur): ?DateTimeImmutable
    {
        $valeur = trim($valeur);
        if ('' === $valeur) {
            return null;
        }
        try {
            return new DateTimeImmutable($valeur);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Sert le PDF d'une facture (via le fournisseur : stub en local, Progiciel en prod),
     * en affichage inline dans le navigateur.
     */
    #[Route('/facture/{ecritureId}/pdf', name: 'app_recouvrement_facture_pdf', requirements: ['ecritureId' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function facturePdf(string $ecritureId, PdfFactureProvider $pdfFactureProvider): Response
    {
        $this->denyUnlessRecouvrement();

        $facture = $this->impayes->factureParEcriture($ecritureId);
        if (null === $facture) {
            throw $this->createNotFoundException('Facture introuvable.');
        }

        $reference = (string) ($facture['reference_facture'] ?? $facture['numpiece'] ?? '');
        $pdf = $pdfFactureProvider->recuperer($reference, (string) ($facture['compte'] ?? ''));
        if (null === $pdf) {
            throw $this->createNotFoundException('PDF de la facture indisponible.');
        }

        $nom = '' !== trim($reference) ? (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($reference)) : 'facture';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', '' !== $nom ? $nom : 'facture'),
        ]);
    }

    /**
     * Extrait des adresses email valides d'une saisie libre (séparées par , ; ou espace).
     *
     * @return list<string>
     */
    private function extraireEmails(string $brut): array
    {
        $emails = [];
        foreach (preg_split('/[,;\s]+/', $brut) ?: [] as $candidat) {
            $candidat = trim($candidat);
            if ('' !== $candidat && false !== filter_var($candidat, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $candidat;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Curation fusionnée dans la vue « Relance & Curation » : on redirige vers
     * /relancer en conservant les filtres (rétro-compatibilité des liens/favoris).
     */
    #[Route('/curation', name: 'app_recouvrement_curation', methods: ['GET'])]
    public function curation(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        return $this->redirectToRoute('app_recouvrement_relancer', $request->query->all());
    }

    /**
     * Espace admin (manager) : statistiques / suivi du recouvrement.
     */
    #[Route('/admin/stats', name: 'app_recouvrement_admin_stats', methods: ['GET'])]
    public function adminStats(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        return $this->render('recouvrement/admin_stats.html.twig', [
            'retours_a_traiter' => $this->retours->compterNonTraites(),
            'courriers_a_envoyer' => $this->relances->compterCourriersEnAttente(),
            'courriers_envoyes' => $this->relances->compterCourriersEnvoyes(),
            'relances_envoyees' => $this->relances->compterEnvoyees(),
            'comptes_relancables' => $this->exclusions->compterParEtat(CompteExclusionEtat::RELANCABLE, null),
            'comptes_ecartes' => $this->exclusions->compterParEtat(CompteExclusionEtat::ECARTE, null),
        ]);
    }

    /**
     * Pose une decision manuelle de curation (ecarter / reactiver un compte).
     * Reserve au manager. Delegue au repository (trace qui/quand).
     */
    #[Route('/curation/decider', name: 'app_recouvrement_curation_decider', methods: ['POST'])]
    public function curationDecider(Request $request): Response
    {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('curation-decider', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $compte = trim((string) $request->request->get('compte'));
        $cible = (string) $request->request->get('etat');
        if ('' === $compte || !\in_array($cible, ['ecarte', 'relancable'], true)) {
            throw $this->createNotFoundException('Paramètres de curation invalides.');
        }

        $ecarte = 'ecarte' === $cible;
        $etat = $ecarte ? CompteExclusionEtat::ECARTE : CompteExclusionEtat::RELANCABLE;

        // Motif saisi par l'utilisateur (facultatif) ; repli sur un libelle par defaut.
        $motif = trim((string) $request->request->get('motif'));
        if ('' === $motif) {
            $motif = $ecarte ? 'Écarté manuellement' : 'Réactivé manuellement';
        }

        $this->exclusions->decisionManuelle($compte, $etat, $motif, $this->nomOperateur());

        // Bascule AJAX depuis la grille : 204 -> le JS met la ligne a jour sur place.
        if ($request->isXmlHttpRequest()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $this->addFlash('success', sprintf(
            '%s : %s.',
            $compte,
            $ecarte ? 'écarté de la relance' : 'réactivé (relançable)',
        ));

        return $this->redirectToRoute('app_recouvrement_relancer');
    }

    /**
     * Vue de relance MANUELLE : liste des clients relançables (groupée, hors
     * écartés), recherche + scroll infini. La comptable relance un client entier
     * ou une facture précise, au niveau de son choix. Accès comptable ou manager.
     */
    #[Route('/relancer', name: 'app_recouvrement_relancer', methods: ['GET'])]
    public function relancerManuel(Request $request, SelectionRelanceService $selection, AnnuaireClientService $annuaire): Response
    {
        $this->denyUnlessRecouvrement();

        $estFragment = $request->query->getBoolean('fragment');
        $page = max(1, $request->query->getInt('page', 1));
        $q = trim((string) $request->query->get('q', ''));
        $fcollectifs = self::filtreMultiple($request, 'collectif');
        $fetabs = self::filtreMultiple($request, 'etab');
        $fstatut = (string) $request->query->get('statut', '');
        $statut = \in_array($fstatut, ['ecarte', 'relancable'], true) ? $fstatut : null;
        $horsRelance = $request->query->getBoolean('hors_relance');
        // NB : lecture tolerante (pas getInt) car le formulaire GET et le scroll infini
        // envoient "strategie=" VIDE ; getInt() leve une 400 sur une valeur vide.
        $strategieId = (int) $request->query->get('strategie');
        // Filtre "Strategie" : restreint la liste au perimetre de la regle choisie
        // (null si aucune selection ou regle introuvable -> pas de filtrage).
        $contrainteStrategie = $strategieId > 0 ? $selection->contrainteStrategie($strategieId) : null;

        // Vue CLIENTS orientée recherche : servie par la projection mv_annuaire_clients
        // (une ligne par client, recherche trigramme, tri retard/encours).
        $rendu = ['comptes' => $annuaire->rechercher(
            '' !== $q ? $q : null,
            $fcollectifs,
            $fetabs,
            $statut,
            $page,
            self::PAR_PAGE_RELANCER,
            $horsRelance,
            $contrainteStrategie,
        )];

        if ($estFragment) {
            return $this->render('recouvrement/_relancer_rows.html.twig', $rendu);
        }

        $total = $annuaire->compter('' !== $q ? $q : null, $fcollectifs, $fetabs, $statut, $horsRelance, $contrainteStrategie);
        $options = $selection->optionsFiltres();
        $strategieOpts = ['' => 'Stratégie'];
        foreach ($selection->listerStrategies() as $sid => $snom) {
            $strategieOpts[(string) $sid] = $snom;
        }

        return $this->render('recouvrement/relancer.html.twig', $rendu + [
            'q' => $q,
            'collectif' => $fcollectifs,
            'etab' => $fetabs,
            'statut' => $fstatut,
            'hors_relance' => $horsRelance,
            'strategie' => null !== $contrainteStrategie ? (string) $strategieId : '',
            'strategie_opts' => $strategieOpts,
            'collectif_opts' => array_combine($options['collectifs'], $options['collectifs']),
            'etab_opts' => self::optionsEtablissements($options['etablissements']),
            'options' => $options,
            'page' => $page,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / self::PAR_PAGE_RELANCER)),
            'nb_retours' => $this->retours->compterNonTraites(),
            'nb_courriers' => $this->relances->compterCourriersEnAttente(),
            'nb_ecartes' => $this->exclusions->compterParEtat(CompteExclusionEtat::ECARTE, null),
        ]);
    }

    /**
     * Valeurs d'un filtre MULTI-VALEURS de la liste Clients (`?etab[]=093&etab[]=181`).
     * Accepte aussi la forme scalaire `?etab=093` pour que les liens et favoris d'avant
     * le passage en multi-selection continuent de filtrer.
     *
     * @return list<string>
     */
    private static function filtreMultiple(Request $request, string $cle): array
    {
        $brut = $request->query->all()[$cle] ?? null;
        $valeurs = \is_array($brut) ? $brut : [$brut];

        $codes = [];
        foreach ($valeurs as $valeur) {
            if (!\is_scalar($valeur)) {
                continue;
            }
            $code = trim((string) $valeur);
            if ('' !== $code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Options du filtre etablissement : code de v_impayes -> libelle affiche
     * ('093' -> '093 — SYNTHAUTO TEGBRY 51 APV'). Les cles restent les codes exacts
     * attendus par la requete SQL.
     *
     * @param list<string> $codes
     *
     * @return array<int|string, string>
     */
    private static function optionsEtablissements(array $codes): array
    {
        $options = [];
        foreach ($codes as $code) {
            $options[$code] = Etablissements::avecCode($code);
        }

        return $options;
    }

    /**
     * Fragment : factures échues d'un client, pour le dépliage de la vue de relance
     * manuelle (relance facture par facture).
     */
    #[Route('/relancer/compte/{compte}/factures', name: 'app_recouvrement_relancer_factures', requirements: ['compte' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function relancerCompteFactures(string $compte): Response
    {
        $this->denyUnlessRecouvrement();

        return $this->render('recouvrement/_relancer_factures.html.twig', [
            'compte' => $compte,
            'factures' => $this->impayes->facturesDuCompte($compte),
            'avoirs' => $this->impayes->avoirsDuCompte($compte),
        ]);
    }

    /**
     * Envoi manuel : un ou plusieurs clients (comptes[], relevé complet) et/ou une
     * ou plusieurs factures (factures[], ciblées), au niveau choisi. Réutilise le
     * pipeline (preparer + dispatch) : anti-doublon, PDF, Mailjet, FORCE_TO en test.
     * JSON pour le JS, repli redirection sans JS.
     */
    #[Route('/relancer/envoyer', name: 'app_recouvrement_relancer_envoyer', methods: ['POST'])]
    public function relancerManuelEnvoi(
        Request $request,
        SelectionRelanceService $selection,
        EnvoiRelanceService $envoi,
        MessageBusInterface $bus,
    ): Response {
        $this->denyUnlessRecouvrement();

        if (!$this->isCsrfTokenValid('relancer-manuel', (string) $request->request->get('_token'))) {
            return $this->reponseCourrier($request, false, 'Jeton CSRF invalide.', Response::HTTP_FORBIDDEN);
        }

        // Niveau CHOISI par le comptable : 1, 2, ou mise en demeure (med). Indépendant
        // des stratégies : groupeManuel relance avec un rendu par défaut si aucune
        // stratégie ne matche.
        [$niveau, $med] = match ((string) $request->request->get('niveau', '1')) {
            'med' => [3, true],
            '2' => [2, false],
            default => [1, false],
        };

        $comptes = self::nettoyerListe($request->request->all('comptes'));
        $factures = self::nettoyerListe($request->request->all('factures'));

        $bilan = ['envoyes' => 0, 'courriers' => 0, 'ignores' => 0, 'erreurs' => 0];
        foreach ($comptes as $compte) {
            $this->cumuler($bilan, $this->traiterEnvoiManuel($selection->groupeManuel($compte, null, $niveau, $med), $envoi, $bus));
        }
        foreach ($factures as $ecriture) {
            $this->cumuler($bilan, $this->traiterEnvoiManuel($selection->groupeManuel(null, $ecriture, $niveau, $med), $envoi, $bus));
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true] + $bilan);
        }

        $this->addFlash('success', sprintf(
            '%d relance%s envoyée%s, %d courrier%s en attente, %d ignorée%s.',
            $bilan['envoyes'], $bilan['envoyes'] > 1 ? 's' : '', $bilan['envoyes'] > 1 ? 's' : '',
            $bilan['courriers'], $bilan['courriers'] > 1 ? 's' : '',
            $bilan['ignores'], $bilan['ignores'] > 1 ? 's' : '',
        ));

        return $this->redirectToRoute('app_recouvrement_relancer');
    }

    /**
     * Prépare + dispatch une relance manuelle pour un groupe (compte ou facture).
     * Renvoie le bilan unitaire : envoyes / courriers / ignores / erreurs.
     *
     * @param GroupeARelancer|null $groupe
     *
     * @return array{envoyes: int, courriers: int, ignores: int, erreurs: int}
     */
    private function traiterEnvoiManuel(?array $groupe, EnvoiRelanceService $envoi, MessageBusInterface $bus): array
    {
        $vide = ['envoyes' => 0, 'courriers' => 0, 'ignores' => 0, 'erreurs' => 0];

        if (null === $groupe) {
            // Compte/facture non relançable (soldé, écarté ou non codifié).
            return ['ignores' => 1] + $vide;
        }

        try {
            $relance = $envoi->preparer($groupe);
            $id = $relance->getId();
            if (null === $id) {
                return ['ignores' => 1] + $vide;
            }
            if (RelanceVecteur::COURRIER === $groupe['vecteur']) {
                // Client sans email : lettre préparée, part dans la pile "Courriers".
                return ['courriers' => 1] + $vide;
            }
            $bus->dispatch(new EnvoyerRelance($id, $groupe));

            return ['envoyes' => 1] + $vide;
        } catch (Throwable) {
            // Cas nominal : palier déjà envoyé (garde-fou) -> ignoré.
            return ['ignores' => 1] + $vide;
        }
    }

    /**
     * @param array{envoyes: int, courriers: int, ignores: int, erreurs: int} $bilan
     * @param array{envoyes: int, courriers: int, ignores: int, erreurs: int} $ajout
     */
    private function cumuler(array &$bilan, array $ajout): void
    {
        foreach ($ajout as $cle => $valeur) {
            $bilan[$cle] += $valeur;
        }
    }

    /**
     * Normalise une liste POST (comptes[] / factures[]) : chaînes non vides, uniques.
     *
     * @param array<mixed> $brut
     *
     * @return list<string>
     */
    private static function nettoyerListe(array $brut): array
    {
        $valeurs = array_filter(array_map(static fn ($v): string => trim((string) $v), $brut));

        return array_values(array_unique($valeurs));
    }

    private function denyUnlessRecouvrement(): void
    {
        if (!$this->isGranted('ROLE_COMPTABLE') && !$this->isGranted('ROLE_MANAGER')) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Nom complet de l'utilisateur courant (trace du marquage manuel des
     * courriers), avec repli neutre si l'identité n'est pas exploitable.
     */
    private function nomOperateur(): string
    {
        $utilisateur = $this->getUser();
        if ($utilisateur instanceof User) {
            $nom = trim($utilisateur->getFullName());
            if ('' !== $nom) {
                return $nom;
            }
        }

        return 'Opérateur inconnu';
    }

    /**
     * Identifiant immuable de l'utilisateur courant (valeur probante du marquage),
     * ou null si non exploitable.
     */
    private function idOperateur(): ?int
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof User ? $utilisateur->getId() : null;
    }

    private function pluriel(int $nombre, string $singulier, string $pluriel): string
    {
        return abs($nombre) > 1 ? $pluriel : $singulier;
    }
}
