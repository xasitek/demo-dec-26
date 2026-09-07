<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\WorkflowRemboursement;
use App\Shared\Repository\EtablissementRepository;
use DateTimeInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal des paiements : reserve au DIRECTEUR DU POLE + la dev. Il y voit,
 * groupes PAR JOUR (scroll infini), les lots de paiements prepares (fichiers SEPA/OD
 * telecharges par le manager) et confirme leur reglement — dossiers coches passent en
 * « Payé » (temps reel, sans rechargement), ce qui informe la secretaire et la comptabilite.
 */
#[Route('/remboursement/paiements')]
#[IsGranted(new Expression("is_granted('ROLE_DIRECTEUR') or user.getUserIdentifier() == 'credit-manager@demonstration.invalid'"))]
final class PaiementJournalController extends AbstractController
{
    /** Jours par page (scroll infini). */
    private const PAR_JOUR = 5;

    /**
     * Ordre d'affichage FIXE des pieces : les communes aux deux motifs d'abord (RIB, puis
     * les fichiers generes SEPA/OD), ensuite les specifiques rachat sec, puis trop-percu.
     */
    private const ORDRE_PIECES = [
        'rib', 'fichier_sepa', 'fichier_od',
        'facture_achat_vo', 'estimation_salesforce', 'carte_grise', 'certificat_situation',
        'releve_icar', 'petits_comptes',
    ];

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierPieceRepository $pieces,
        private readonly EtablissementRepository $etablissements,
        private readonly WorkflowRemboursement $workflow,
    ) {
    }

    #[Route('/journal', name: 'app_remboursement_paiements_journal', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $etab = trim((string) $request->query->get('etab', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $joursAll = $this->dossiers->journalJours('' !== $etab ? $etab : null, '' !== $q ? $q : null);
        $totalPages = max(1, (int) ceil(\count($joursAll) / self::PAR_JOUR));
        $datesPage = \array_slice($joursAll, ($page - 1) * self::PAR_JOUR, self::PAR_JOUR);

        $dossiers = $this->dossiers->dossiersDesJours($datesPage, '' !== $etab ? $etab : null, '' !== $q ? $q : null);
        [$etabsNom, $etabsSociete] = $this->mapEtablissements();

        // Pieces triees dans un ordre FIXE (communes d'abord) pour un affichage stable.
        $piecesParDossier = $this->pieces->pourDossiers($dossiers);
        foreach ($piecesParDossier as $id => $liste) {
            usort($liste, fn (DossierPiece $a, DossierPiece $b): int => $this->ordrePiece($a->getType()) <=> $this->ordrePiece($b->getType()));
            $piecesParDossier[$id] = $liste;
        }

        $vars = [
            'jours' => $this->grouperParJour($dossiers),
            'piecesParDossier' => $piecesParDossier,
            'etabsNom' => $etabsNom,
            'etabsSociete' => $etabsSociete,
        ];

        // Tranche de scroll infini : uniquement les sections « jour ».
        if ($request->query->getBoolean('fragment')) {
            return $this->render('remboursement/_journal_jours.html.twig', $vars);
        }

        return $this->render('remboursement/paiements_journal.html.twig', $vars + [
            'q' => $q,
            'etab' => $etab,
            'etabs' => $etabsNom,
            'page' => $page,
            'pages' => $totalPages,
        ]);
    }

    /** Confirmation : les dossiers COCHES (ids) encore en attente passent en « Payé ». */
    #[Route('/journal/confirmer', name: 'app_remboursement_paiements_confirmer', methods: ['POST'])]
    public function confirmer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('paiements_confirmer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        $par = $this->getUser()?->getUserIdentifier();

        $payes = [];
        if ([] !== $ids) {
            foreach ($this->dossiers->findBy(['id' => $ids]) as $dossier) {
                if (DossierStatut::GENERATION_EN_COURS !== $dossier->getStatut()) {
                    continue;
                }
                try {
                    $this->workflow->appliquer($dossier, 'terminer_generation', $par, 'Paiement confirmé par la direction', parSysteme: true);
                    $payes[] = (int) $dossier->getId();
                } catch (DomainException) {
                    // Dossier non transitionnable (deja paye entre-temps) : on ignore.
                }
            }
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'payes' => $payes]);
        }

        $this->addFlash('success', [] === $payes
            ? 'Aucun paiement à confirmer.'
            : sprintf('%d paiement%s marqué%s payé%s.', \count($payes), \count($payes) > 1 ? 's' : '', \count($payes) > 1 ? 's' : '', \count($payes) > 1 ? 's' : ''));

        return $this->redirectToRoute('app_remboursement_paiements_journal');
    }

    /**
     * @param list<Dossier> $dossiers
     *
     * @return array<string, array{date: DateTimeInterface|null, par: string|null, dossiers: list<Dossier>, total: float, enAttente: int, payes: int}>
     */
    private function grouperParJour(array $dossiers): array
    {
        $jours = [];
        foreach ($dossiers as $d) {
            $cle = $d->getSepaTelechargeLe()?->format('Y-m-d') ?? 'inconnu';
            if (!isset($jours[$cle])) {
                $jours[$cle] = [
                    'date' => $d->getSepaTelechargeLe(),
                    'par' => $d->getSepaTelechargePar(),
                    'dossiers' => [],
                    'total' => 0.0,
                    'enAttente' => 0,
                    'payes' => 0,
                ];
            }
            $jours[$cle]['dossiers'][] = $d;
        }

        foreach ($jours as $cle => $grp) {
            $liste = $grp['dossiers'];
            usort($liste, fn (Dossier $a, Dossier $b): int => $this->montant($b) <=> $this->montant($a));

            $total = 0.0;
            $enAttente = 0;
            $payes = 0;
            foreach ($liste as $d) {
                $total += $this->montant($d);
                if (DossierStatut::GENERATION_EN_COURS === $d->getStatut()) {
                    ++$enAttente;
                } else {
                    ++$payes;
                }
            }
            $jours[$cle]['dossiers'] = $liste;
            $jours[$cle]['total'] = $total;
            $jours[$cle]['enAttente'] = $enAttente;
            $jours[$cle]['payes'] = $payes;
        }

        return $jours;
    }

    private function montant(Dossier $d): float
    {
        return (float) ($d->getValideMontant() ?: $d->getControleMontant() ?: $d->getMontant());
    }

    /** Rang d'une piece dans l'ordre fixe (les inconnues a la fin). */
    private function ordrePiece(string $type): int
    {
        $rang = array_search($type, self::ORDRE_PIECES, true);

        return false === $rang ? 999 : $rang;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>} [code->libelle, code->societe]
     */
    private function mapEtablissements(): array
    {
        $nom = [];
        $societe = [];
        foreach ($this->etablissements->rechercher() as $etab) {
            $nom[$etab->getCodeEtab()] = $etab->getLibelle();
            $societe[$etab->getCodeEtab()] = (string) ($etab->getSociete() ?? '');
        }

        return [$nom, $societe];
    }
}
