<?php

declare(strict_types=1);

namespace App\BonusEco\Controller;

use App\BonusEco\Repository\BonusEcoRepository;
use App\BonusEco\Service\RelanceMailer;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Module Bonus Ecologique.
 *
 * Vue d'audit : 1 ligne = 1 ecriture Progiciel du compte bonus eco
 * (table public.t_ari_balance_agee_bonuseco), enrichie avec le dossier ASP
 * correspondant (matching par numvin = num_chassis) si on l'a synchronise
 * depuis le Google Sheet ASP.
 *
 * Accessible aux 3 roles metier qui ont besoin de ce suivi.
 */
#[Route('/bonus-eco', name: 'app_bonus_eco_')]
#[IsGranted(new \Symfony\Component\ExpressionLanguage\Expression(
    'is_granted("ROLE_MANAGER") or is_granted("ROLE_AUDITEUR") or is_granted("ROLE_COMPTABLE")',
))]
final class BonusEcoController extends AbstractController
{
    private const PAR_PAGE = 50;

    /**
     * Mapping categorie metier -> liste de libelles bruts du sheet ASP.
     * Plusieurs statuts bruts peuvent correspondre a une meme categorie metier.
     *
     * @var array<string, array{libelle: string, ton: string, bruts: list<string>}>
     */
    private const CATEGORIES_ASP = [
        'paye' => [
            'libelle' => 'Payé',
            'ton' => 'positive',
            'bruts' => ['Soldé'],
        ],
        'en_cours' => [
            'libelle' => 'En cours',
            'ton' => 'info',
            'bruts' => ['Instruction en cours', 'Réalisé', 'Décidé'],
        ],
        'en_correction' => [
            'libelle' => 'En correction',
            'ton' => 'warning',
            'bruts' => ['Demande initialisée', 'Demande initialisé'],
        ],
        'annule' => [
            'libelle' => 'Annulé',
            'ton' => 'neutral',
            'bruts' => ['Clos'],
        ],
    ];

    public function __construct(
        private readonly BonusEcoRepository $repo,
        private readonly RelanceMailer $relanceMailer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtres = $this->extraireFiltres($request);
        $tri = (string) ($request->query->get('tri') ?? 'date');
        $sens = 'asc' === $request->query->get('sens') ? 'asc' : 'desc';
        $estFragment = $request->query->getBoolean('fragment');

        $page = max(1, $request->query->getInt('page', 1));
        $total = 0;
        $totalPages = 1;

        if (!$estFragment) {
            $total = $this->repo->compter($filtres);
            $totalPages = max(1, (int) ceil($total / self::PAR_PAGE));
            $page = min($page, $totalPages);
        }

        $lignes = $this->repo->page($page, self::PAR_PAGE, $filtres, $tri, $sens);

        $rendu = [
            'lignes' => $lignes,
            'numerotation_offset' => ($page - 1) * self::PAR_PAGE,
            'categories_asp' => self::CATEGORIES_ASP,
        ];

        if ($estFragment) {
            return $this->render('bonus_eco/_rows.html.twig', $rendu);
        }

        return $this->render('bonus_eco/index.html.twig', $rendu + [
            'synthese' => $this->repo->synthese($filtres),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filtres' => array_filter($filtres, static fn ($v): bool => null !== $v && [] !== $v && '' !== $v),
            'tri' => $tri,
            'sens' => $sens,
            'options' => $this->repo->optionsFiltres(),
        ]);
    }

    // Regex permissive pour autoriser le placeholder "__ID__" en cote JS Twig path().
    // La validation int se fait via le typehint + check ci-dessous.
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $filtres = $this->extraireFiltres($request);
        $tri = (string) ($request->query->get('tri') ?? 'date');
        $sens = 'asc' === $request->query->get('sens') ? 'asc' : 'desc';

        // Index inverse : libelle brut -> categorie metier (pour libelle simplifie).
        $brutVersCategorie = [];
        foreach (self::CATEGORIES_ASP as $meta) {
            foreach ($meta['bruts'] as $brut) {
                $brutVersCategorie[$brut] = $meta['libelle'];
            }
        }

        $lignes = $this->repo->toutes($filtres, $tri, $sens);

        $response = new StreamedResponse(function () use ($lignes, $brutVersCategorie): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'N° écriture', 'Date pièce', 'N° pièce',
                'Société', 'Concession', 'Marque', 'Modèle',
                'VIN', 'Immatriculation', 'Client',
                'Débit €', 'Crédit €', 'Montant signé €',
                'Retard',
                'Statut ASP', 'Statut ASP (libellé brut)',
                'ASP N° dossier', 'ASP Montant payé €', 'ASP Date paiement bonus',
            ], ';');

            foreach ($lignes as $l) {
                $asp = $l['asp'] ?? null;
                $libBrut = $asp ? (string) ($asp['lib_etat'] ?? '') : '';
                $libCat = '' !== $libBrut ? ($brutVersCategorie[$libBrut] ?? $libBrut) : '';

                fputcsv($out, [
                    self::texteExcel((string) ($l['numero'] ?? '')),
                    self::dateFr($l['dateecriture'] ?? null),
                    self::texteExcel((string) ($l['numpiece'] ?? '')),
                    (string) ($l['codesoc'] ?? ''),
                    (string) ($l['codeetab'] ?? ''),
                    trim((string) ($l['marque'] ?? '')),
                    (string) ($l['modele'] ?? ''),
                    self::texteExcel((string) ($l['numvin'] ?? '')),
                    self::texteExcel((string) ($l['numimmat'] ?? '')),
                    (string) ($l['nom'] ?? ''),
                    self::nombreExcel($l['debit'] ?? null),
                    self::nombreExcel($l['credit'] ?? null),
                    self::nombreExcel($l['montant_signe'] ?? null),
                    (string) ($l['retard'] ?? ''),
                    $libCat,
                    $libBrut,
                    $asp ? self::texteExcel((string) ($asp['num_dossier_mensuel'] ?? '')) : '',
                    $asp ? self::nombreExcel($asp['mt_paye'] ?? null) : '',
                    $asp ? self::dateFr($asp['date_paie_bonus'] ?? null) : '',
                ], ';');
            }
            fclose($out);
        });

        $nom = sprintf('bonus-eco-audit-%s.csv', (new DateTimeImmutable())->format('Y-m-d-His'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $nom));

        return $response;
    }

    #[Route('/detail/{numero}', name: 'detail', requirements: ['numero' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function detail(string $numero): Response
    {
        if (!ctype_digit($numero)) {
            throw $this->createNotFoundException('Écriture introuvable.');
        }
        $num = (int) $numero;
        $detail = $this->repo->detail($num);
        if (null === $detail) {
            throw $this->createNotFoundException('Écriture introuvable.');
        }

        $detail['historique_relances'] = $this->relanceMailer->historique($num);
        $detail['categories_asp'] = self::CATEGORIES_ASP;
        $detail['relance_possible'] = $this->relancePossible($detail);

        return $this->render('bonus_eco/_detail.html.twig', ['detail' => $detail]);
    }

    /**
     * Determine si la relance est autorisee pour cette ecriture :
     *  - statut ASP != 'paye' (categorie metier)
     *  - ET au moins un identifiant utilisable (email Progiciel OU nom)
     *
     * Le fallback construit prenomnom@/nomprenom@demonstration.invalid a partir
     * du nom : on n'a donc PAS besoin d'un email Progiciel pour proposer la relance,
     * du moment qu'on a au moins un nom.
     *
     * @param array<string, mixed> $detail
     *
     * @return array{vendeur: bool, secretaire: bool, raison_blocage: ?string}
     */
    private function relancePossible(array $detail): array
    {
        $ec = $detail['ecriture'] ?? [];
        $asp = $detail['asp'] ?? null;

        // Bloque si statut ASP = Soldé (categorie 'paye' couvre uniquement ce libelle brut).
        if ($asp && 'Soldé' === ($asp['lib_etat'] ?? '')) {
            return ['vendeur' => false, 'secretaire' => false, 'raison_blocage' => 'Dossier déjà payé par l\'ASP.'];
        }

        $vendeurUtilisable = '' !== trim((string) ($ec['emailvendeur'] ?? ''))
            || '' !== trim((string) ($ec['prenomvendeur'] ?? ''))
            || '' !== trim((string) ($ec['nomvendeur'] ?? ''));

        $secretaireUtilisable = '' !== trim((string) ($ec['emailsecr'] ?? ''))
            || '' !== trim((string) ($ec['nomsecretaire'] ?? ''));

        return [
            'vendeur' => $vendeurUtilisable,
            'secretaire' => $secretaireUtilisable,
            'raison_blocage' => (!$vendeurUtilisable && !$secretaireUtilisable)
                ? 'Aucun contact vendeur ni secrétaire identifiable.'
                : null,
        ];
    }

    #[Route('/relance/{numero}', name: 'relance', requirements: ['numero' => '\d+'], methods: ['POST'])]
    public function relance(int $numero, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bonus-eco-relance-'.$numero, (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Jeton CSRF invalide.'], 403);
        }

        $type = (string) $request->request->get('type');
        if (!\in_array($type, ['vendeur', 'secretaire'], true)) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Type de destinataire invalide.'], 400);
        }

        $detail = $this->repo->detail($numero);
        if (null === $detail) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Écriture introuvable.'], 404);
        }

        // Garde-fou : meme verif cote serveur que cote UI.
        $autorisation = $this->relancePossible($detail);
        if (false === $autorisation[$type]) {
            return new JsonResponse([
                'ok' => false,
                'erreur' => $autorisation['raison_blocage'] ?? "Relance $type non autorisée pour cette écriture.",
            ], 403);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Non authentifié.'], 401);
        }

        try {
            $this->relanceMailer->envoyer($detail['ecriture'], $detail['asp'], $type, $user);
        } catch (Throwable $e) {
            return new JsonResponse(['ok' => false, 'erreur' => $e->getMessage()], 500);
        }

        return new JsonResponse(['ok' => true, 'message' => 'Relance envoyée.']);
    }

    /**
     * Relance groupée : 1 mail par destinataire (vendeur ou secrétaire)
     * avec la liste de SES dossiers en correction côté ASP.
     */
    #[Route('/relance-groupee', name: 'relance_groupee', methods: ['POST'])]
    public function relanceGroupee(Request $request): JsonResponse
    {
        $type = (string) $request->request->get('type');
        if (!\in_array($type, ['vendeur', 'secretaire'], true)) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Type invalide.'], 400);
        }

        if (!$this->isCsrfTokenValid('bonus-eco-relance-groupee', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Jeton CSRF invalide.'], 403);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['ok' => false, 'erreur' => 'Non authentifié.'], 401);
        }

        $groupes = $this->repo->dossiersGroupesPourRelance($type);
        if ([] === $groupes) {
            return new JsonResponse(['ok' => true, 'message' => 'Aucun dossier en correction à relancer.', 'stats' => ['envoyes' => 0, 'echecs' => 0, 'ignores' => 0]]);
        }

        try {
            $stats = $this->relanceMailer->envoyerLot($groupes, $type, $user);
        } catch (Throwable $e) {
            return new JsonResponse(['ok' => false, 'erreur' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => sprintf(
                '%d email(s) envoyé(s), %d échec(s), %d ignoré(s).',
                $stats['envoyes'],
                $stats['echecs'],
                $stats['ignores'],
            ),
            'stats' => $stats,
        ]);
    }

    /**
     * @return array{codesoc: list<string>, marque: list<string>, retard: list<string>, lib_etat: list<string>, asp_categories: list<string>, recherche: ?string}
     */
    private function extraireFiltres(Request $request): array
    {
        // Filtre statut ASP : on accepte les CLES de categorie metier (paye,
        // en_cours, en_correction, annule) que l'on traduit en liste de
        // libelles bruts pour le WHERE SQL. On conserve aussi la liste des
        // categories cochees pour pre-cocher les checkboxes du formulaire.
        $categoriesChoisies = self::listeChainesNonVides((array) $request->query->all('asp_categories'));
        $libellesBruts = [];
        foreach ($categoriesChoisies as $cat) {
            foreach (self::CATEGORIES_ASP[$cat]['bruts'] ?? [] as $brut) {
                $libellesBruts[] = $brut;
            }
        }

        return [
            'codesoc' => self::listeChainesNonVides((array) $request->query->all('codesoc')),
            'marque' => self::listeChainesNonVides((array) $request->query->all('marque')),
            'retard' => self::listeChainesNonVides((array) $request->query->all('retard')),
            'lib_etat' => $libellesBruts,
            'asp_categories' => $categoriesChoisies,
            'recherche' => self::texte($request->query->get('recherche')),
        ];
    }

    /**
     * @param array<mixed> $valeurs
     *
     * @return list<string>
     */
    private static function listeChainesNonVides(array $valeurs): array
    {
        return array_values(array_filter(
            array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $valeurs),
            static fn (string $v): bool => '' !== $v,
        ));
    }

    private static function texte(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);

        return '' === $s ? null : $s;
    }

    /**
     * Force Excel a interpreter la chaine comme du texte (cf. Garanties).
     */
    private static function texteExcel(string $valeur): string
    {
        if ('' === $valeur) {
            return '';
        }
        $echappe = str_replace('"', '""', $valeur);

        return '="'.$echappe.'"';
    }

    /**
     * Format Excel-FR : virgule decimale, sans separateur de milliers.
     */
    private static function nombreExcel(mixed $valeur): string
    {
        if (null === $valeur || '' === $valeur) {
            return '';
        }

        return str_replace('.', ',', (string) $valeur);
    }

    /**
     * Convertit "YYYY-MM-DD..." en "DD/MM/YYYY".
     */
    private static function dateFr(mixed $valeur): string
    {
        if (!is_scalar($valeur)) {
            return '';
        }
        $s = trim((string) $valeur);
        if ('' === $s) {
            return '';
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s)
            ?: DateTimeImmutable::createFromFormat('Y-m-d', $s);

        return false === $d ? $s : $d->format('d/m/Y');
    }
}
