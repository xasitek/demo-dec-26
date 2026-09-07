<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\AnalyseVirement;
use App\Remboursement\Service\EcartControle;
use App\Remboursement\Service\FusionOd;
use App\Remboursement\Service\GenerationFichiersComptables;
use App\Remboursement\Service\RemboursementMailer;
use App\Shared\Entity\User;
use App\Shared\Repository\EtablissementRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use ZipArchive;

/**
 * Poste MANAGER : recuperation des fichiers de virement SEPA a envoyer a la banque.
 * Deux sections — « A telecharger » (file active) et « Deja telecharges » (historique
 * recherchable / scroll infini). « Tout telecharger » produit un ZIP et marque le lot.
 * Doublons (meme cle anti-doublon) et anomalies (etablissement/IBAN/BIC) sont signales.
 */
#[Route('/remboursement/virements')]
#[IsGranted('ROLE_MANAGER')]
final class VirementController extends AbstractController
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierPieceRepository $pieces,
        private readonly EtablissementRepository $etablissements,
        private readonly AnalyseVirement $analyse,
        private readonly RemboursementMailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_remboursement_virements', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $etab = trim((string) $request->query->get('etab', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $etabs = $this->mapEtablissements();
        $tous = $this->dossiers->aControler();
        $groupes = $this->analyse->groupes($tous, $etabs);
        $traites = $this->dossiers->sepaTelechargesFiltre('' !== $q ? $q : null, '' !== $etab ? $etab : null, $page);

        // Tranche de scroll infini (deja telecharges).
        if ($request->query->getBoolean('fragment')) {
            $ecarts = [];
            foreach ($traites as $d) {
                $ecarts[(int) $d->getId()] = EcartControle::pour($d);
            }

            return $this->render('remboursement/_virements_rows.html.twig', [
                'dossiers' => $traites,
                'meta' => $this->analyse->metas($traites, $etabs, $groupes),
                'etabs' => $etabs,
                'traite' => true,
                'select' => false,
                'ecarts' => $ecarts,
            ]);
        }

        $aTraiter = $this->dossiers->sepaAtraiter(false);
        $meta = $this->analyse->metas($tous, $etabs, $groupes);

        // Doublons / anomalies sur TOUT ce qui est en paiement (les 2 sections).
        $nbDoublons = 0;
        $nbAnomalies = 0;
        foreach ($tous as $d) {
            $m = $meta[(int) $d->getId()];
            if ($m['doublon']) {
                ++$nbDoublons;
            }
            if ([] !== $m['anomalies']) {
                ++$nbAnomalies;
            }
        }

        // Montant total = ce qui reste a payer (file a telecharger).
        $total = 0.0;
        foreach ($aTraiter as $d) {
            $total += (float) ($d->getValideMontant() ?: $d->getControleMontant() ?: $d->getMontant());
        }

        // Ecart saisie/IA/valide (IBAN ou montant) sur chaque ligne affichee. Compte sur
        // la file A TELECHARGER (les paiements imminents) pour la tuile de tete.
        $ecarts = [];
        foreach ([...$tous, ...$aTraiter, ...$traites] as $d) {
            $ecarts[(int) $d->getId()] ??= EcartControle::pour($d);
        }
        $nbEcarts = 0;
        foreach ($aTraiter as $d) {
            if ($ecarts[(int) $d->getId()]['diverge'] ?? false) {
                ++$nbEcarts;
            }
        }

        // File "a telecharger" REGROUPEE par motif (rachat sec / trop-percu) : en-tete + total
        // par motif dans l'ecran.
        /** @var array<string, array{motif: DossierMotif, dossiers: list<Dossier>, total: float}> $parMotif */
        $parMotif = [];
        foreach ($aTraiter as $d) {
            $mv = $d->getMotif()->value;
            if (!isset($parMotif[$mv])) {
                $parMotif[$mv] = ['motif' => $d->getMotif(), 'dossiers' => [], 'total' => 0.0];
            }
            $parMotif[$mv]['dossiers'][] = $d;
            $parMotif[$mv]['total'] += (float) ($d->getValideMontant() ?: $d->getControleMontant() ?: $d->getMontant());
        }
        $aTraiterGroupes = array_values($parMotif);

        $totalTraites = $this->dossiers->compterSepaTelecharges('' !== $q ? $q : null, '' !== $etab ? $etab : null);

        return $this->render('remboursement/virements.html.twig', [
            'aTraiter' => $aTraiter,
            'aTraiterGroupes' => $aTraiterGroupes,
            'traites' => $traites,
            'tous' => $tous,
            'meta' => $meta,
            'etabs' => $etabs,
            'total' => $total,
            'nbDoublons' => $nbDoublons,
            'nbAnomalies' => $nbAnomalies,
            'ecarts' => $ecarts,
            'nbEcarts' => $nbEcarts,
            'q' => $q,
            'etab' => $etab,
            'totalTraites' => $totalTraites,
            'pages' => max(1, (int) ceil($totalTraites / DossierRepository::PAR_PAGE)),
        ]);
    }

    /**
     * ZIP du lot selectionne, en UNE archive : SEPA/ (fichiers banque), OD/ (une ecriture par
     * dossier) et la FUSION « OD Lettrage.csv ». La compta depose cette fusion dans
     * X:\...\OD LETTRAGE apres telechargement. Le telechargement marque le lot + notifie le directeur.
     */
    #[Route('/zip', name: 'app_remboursement_virements_zip', methods: ['POST'])]
    public function zip(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('virements_zip', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        if ([] === $ids) {
            $this->addFlash('info', 'Aucun dossier sélectionné.');

            return $this->redirectToRoute('app_remboursement_virements');
        }

        $dossiers = array_values(array_filter(
            $this->dossiers->findBy(['id' => $ids]),
            static fn (Dossier $d): bool => DossierStatut::GENERATION_EN_COURS === $d->getStatut(),
        ));
        if ([] === $dossiers) {
            $this->addFlash('info', 'Aucun dossier sélectionné.');

            return $this->redirectToRoute('app_remboursement_virements');
        }

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'remb_').'.zip';
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException('Impossible de créer l\'archive ZIP.');
        }

        $etabs = $this->mapEtablissements();
        $piecesParDossier = $this->pieces->pourDossiers($dossiers); // 1 requete au lieu de N (anti N+1)
        $ajoutes = 0;
        $marques = [];
        $odContenus = [];
        foreach ($dossiers as $dossier) {
            $pieces = $piecesParDossier[(int) $dossier->getId()] ?? [];
            $sepaPiece = $this->pieceType($pieces, GenerationFichiersComptables::TYPE_SEPA);
            $odPiece = $this->pieceType($pieces, GenerationFichiersComptables::TYPE_OD);
            $ok = false;
            // Deux sous-dossiers dans UNE archive : SEPA/ (banque) et OD/ (compta). Nom
            // lisible : intitule etablissement + date + reference (pas le code etab).
            $sepaOctets = null !== $sepaPiece ? $sepaPiece->getContenu() : '';
            if (null !== $sepaPiece && '' !== $sepaOctets
                && $zip->addFromString('SEPA/'.$this->nomLisible($dossier, $etabs, $sepaPiece->getNomFichier()), $sepaOctets)) {
                $ok = true;
            }
            $odOctets = null !== $odPiece ? $odPiece->getContenu() : '';
            if (null !== $odPiece && '' !== $odOctets
                && $zip->addFromString('OD/'.$this->nomLisible($dossier, $etabs, $odPiece->getNomFichier()), $odOctets)) {
                $odContenus[] = $odOctets;
                $ok = true;
            }
            if ($ok) {
                // Le telechargement n'est plus limite a une fois par jour : la direction
                // veut pouvoir reprendre l'archive autant de fois qu'elle en a besoin.
                // L'anti-spam se deplace donc du BOUTON vers l'E-MAIL — seuls les
                // dossiers jamais telecharges alimentent la notification, si bien qu'un
                // second telechargement du meme lot ne previent personne une deuxieme
                // fois. `marquer()` etant idempotent, la date du premier lot est gardee.
                $nouveau = !$dossier->sepaTelecharge();
                $this->marquer($dossier);
                if ($nouveau) {
                    $marques[] = $dossier;
                }
                ++$ajoutes;
            }
        }
        // OD lettrage fusionne (en-tete unique + toutes les ecritures du lot), dans OD/ :
        // la compta le depose dans X:\...\OD LETTRAGE apres telechargement.
        if ([] !== $odContenus) {
            $fusion = FusionOd::fusionner($odContenus);
            if ('' !== $fusion) {
                $zip->addFromString('OD/OD Lettrage.csv', $fusion);
            }
        }
        $zip->close();

        if (0 === $ajoutes) {
            @unlink($zipPath);
            $this->addFlash('error', 'Aucun fichier disponible pour ce lot.');

            return $this->redirectToRoute('app_remboursement_virements');
        }

        $this->em->flush();
        // Un e-mail au directeur du pole : le lot est prepare, en attente de sa
        // confirmation. Rien a annoncer si le lot ne contenait que des dossiers deja
        // telecharges (cf. plus haut) : on ne le derange pas pour un simple re-telechargement.
        if ([] !== $marques) {
            $u = $this->getUser();
            $this->mailer->paiementsPrepares($u instanceof User ? $u->getFullName() : '', $marques);
        }

        $reponse = new BinaryFileResponse($zipPath, Response::HTTP_OK, ['Content-Type' => 'application/zip']);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'paiements.zip');
        $reponse->deleteFileAfterSend(true);

        return $reponse;
    }

    private function marquer(Dossier $dossier): void
    {
        if (!$dossier->sepaTelecharge()) {
            $dossier->marquerSepaTelecharge($this->getUser()?->getUserIdentifier());
        }
    }

    /**
     * @param list<DossierPiece> $pieces pieces DEJA chargees du dossier (evite le N+1)
     */
    private function pieceType(array $pieces, string $type): ?DossierPiece
    {
        foreach ($pieces as $piece) {
            if ($type === $piece->getType()) {
                return $piece;
            }
        }

        return null;
    }

    /**
     * Nom de fichier lisible pour la banque / la compta : « Intitule etablissement -
     * JJ-MM-AAAA - REFERENCE.ext » (l'intitule, pas le code etab ; avec la date du dossier).
     *
     * @param array<string, string> $etabs
     */
    private function nomLisible(Dossier $dossier, array $etabs, string $nomOriginal): string
    {
        $code = (string) $dossier->getEtablissementCode();
        $etab = $this->nettoyerNom($etabs[$code] ?? ('' !== $code ? $code : 'Etablissement'));
        $date = ($dossier->getValideDirecteurLe() ?? $dossier->getDeposeLe() ?? $dossier->getCreeLe())->format('d-m-Y');
        $ext = strtolower(pathinfo($nomOriginal, \PATHINFO_EXTENSION)) ?: 'csv';

        return sprintf('%s - %s - %s.%s', $etab, $date, $dossier->getReference(), $ext);
    }

    /** Nettoie un intitule pour un nom de fichier (sans accents ni caracteres interdits). */
    private function nettoyerNom(string $valeur): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur);
        $propre = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 _-]+/', '', $ascii)));

        return '' !== $propre ? $propre : 'Etablissement';
    }

    /**
     * @return array<string, string> code etablissement -> libelle
     */
    private function mapEtablissements(): array
    {
        $map = [];
        foreach ($this->etablissements->rechercher() as $etab) {
            $map[$etab->getCodeEtab()] = $etab->getLibelle();
        }

        return $map;
    }
}
