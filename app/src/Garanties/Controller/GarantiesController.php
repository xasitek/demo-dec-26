<?php

declare(strict_types=1);

namespace App\Garanties\Controller;

use App\Garanties\Entity\Note;
use App\Garanties\Enum\StatutDg;
use App\Garanties\Repository\DocumentRepository;
use App\Garanties\Repository\NoteRepository;
use App\Garanties\Repository\ReconciliationRepository;
use App\Garanties\Service\DocumentStockageInterface;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Module Garanties : vue comptable de l'audit. 1 ligne = 1 ecriture Progiciel 4116000,
 * enrichie avec les DG du portail constructeur quand on peut faire le matching
 * par chassis ou par numor. Voir docs/ARCHITECTURE.md pour détail du matching.
 *
 * @phpstan-type Ancre array{
 *     dossier_id: ?int,
 *     cle: ?string,
 *     oidech: ?string,
 *     params: array<string, int|string>,
 *     frame: string,
 *     csrf: string
 * }
 */
#[Route('/garanties')]
final class GarantiesController extends AbstractController
{
    private const PAR_PAGE = 50;

    /**
     * Etats de rapprochement reconnus (libelles UI + ton de couleur).
     *
     * @var array<string, array{libelle: string, ton: string}>
     */
    private const ETATS_RAPPROCHEMENT = [
        'soldee' => ['libelle' => 'Soldée',             'ton' => 'positive'],
        'normale' => ['libelle' => 'Créance en attente', 'ton' => 'info'],
        'orpheline' => ['libelle' => 'Sans DG portail',    'ton' => 'warning'],
        'od_sans_vin' => ['libelle' => 'OD globale (sans VIN)', 'ton' => 'warning'],
        'trop_percu' => ['libelle' => 'Trop-perçu',         'ton' => 'negative'],
        'refusee' => ['libelle' => 'DG refusée',         'ton' => 'negative'],
        'annulee' => ['libelle' => 'DG annulée',          'ton' => 'neutral'],
        'inconnu' => ['libelle' => 'Inconnu',             'ton' => 'neutral'],
    ];

    /**
     * Marques Progiciel (BU) couvertes par un robot de scraping => emetteur DG associe.
     * Une marque ici = robot actif. À ÉTENDRE quand de nouveaux robots arrivent
     * (BMW, Renault, Nissan, Hyundai, Peugeot, Volvo, JLR...). Voir docs/PROD_RENDER.md.
     *
     * @var array<string, string>
     */
    private const MARQUES_SUIVIES = [
        'Fiat' => 'FIAT',
        'Toyota' => 'TOYOTA',
        'Opel' => 'OPEL',
        'BMW' => 'BMW',
    ];

    public function __construct(
        private readonly ReconciliationRepository $reconciliation,
        private readonly NoteRepository $notes,
        private readonly DocumentRepository $documents,
        private readonly DocumentStockageInterface $stockage,
    ) {
    }

    #[Route('', name: 'app_garanties_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtres = $this->extraireFiltres($request);
        // Vue : 'non_lettrees' (defaut, ecritures present_dans_sage=TRUE)
        //       ou 'lettrees' (historique, present_dans_sage=FALSE).
        $vue = 'lettrees' === $request->query->get('vue') ? 'lettrees' : 'non_lettrees';
        $filtres['lettrees'] = ('lettrees' === $vue);
        // Tri par chassis par defaut : regroupe visuellement les ecritures d'un
        // meme vehicule (le template _rows affiche le chassis une fois + separateur).
        $tri = self::texte($request->query->get('tri')) ?? 'chassis';
        $sens = 'asc' === $request->query->get('sens') ? 'asc' : 'desc';
        $estFragment = $request->query->getBoolean('fragment');

        $page = max(1, $request->query->getInt('page', 1));

        // En mode fragment (scroll infini), on n'a pas besoin de compter ni
        // de borner la page : le client demande explicitement une page suivante
        // et s'arrete quand le fragment renvoie 0 ligne. -1 SQL par scroll.
        if (!$estFragment) {
            $total = $this->reconciliation->compter($filtres);
            $totalPages = max(1, (int) ceil($total / self::PAR_PAGE));
            $page = min($page, $totalPages);
        }

        $rows = $this->reconciliation->page($page, self::PAR_PAGE, $filtres, $tri, $sens);

        $rendu = [
            'lignes' => $rows,
            'statut_labels' => $this->statutLabels(),
            'statut_meta' => $this->statutMeta(),
            'etats_meta' => self::ETATS_RAPPROCHEMENT,
            'numerotation_offset' => ($page - 1) * self::PAR_PAGE,
            // Regroupement visuel par chassis actif uniquement quand on trie dessus.
            'tri' => $tri,
        ];

        if ($estFragment) {
            return $this->render('garanties/_rows.html.twig', $rendu);
        }

        $options = $this->reconciliation->optionsFiltres();

        $filtresActifs = array_filter($filtres, static fn ($v): bool => null !== $v && [] !== $v && '' !== $v);

        return $this->render('garanties/index.html.twig', $rendu + [
            'synthese' => $this->reconciliation->synthese($filtres),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filtres' => $filtresActifs,
            'tri' => $tri,
            'sens' => $sens,
            'options' => $options,
            'tranches_age' => ReconciliationRepository::tranchesAge(),
            'statuts' => StatutDg::cases(),
            'pills' => $this->construirePills($filtres, $tri, $sens),
            'vue' => $vue,
        ]);
    }

    /**
     * Vue statistique par marque : volumetrie Progiciel, DG en base, fraicheur des
     * donnees. Distingue les marques suivies par un robot (rapprochement
     * pertinent) de celles pas encore couvertes (volume seulement).
     */
    #[Route('/statistiques', name: 'app_garanties_stats', methods: ['GET'])]
    public function statistiques(Request $request): Response
    {
        $vue = 'lettrees' === $request->query->get('vue') ? 'lettrees' : 'non_lettrees';

        // On compare les marques entre elles : on neutralise le filtre marque,
        // on garde la vue lettré/non lettré.
        $filtres = $this->extraireFiltres($request);
        $filtres['marques'] = [];
        $filtres['lettrees'] = ('lettrees' === $vue);

        $parMarque = $this->reconciliation->statistiquesParMarque($filtres);
        $couverture = $this->reconciliation->couvertureMarques();

        $suivies = [];
        $nonSuivies = [];
        $classement = [];
        $totalLignes = 0;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $totalDgBase = 0;
        $soldeMax = 0.0;

        foreach ($parMarque as $stat) {
            $totalLignes += $stat['lignes'];
            $totalDebit += $stat['total_debit'];
            $totalCredit += $stat['total_credit'];
            $soldeMax = max($soldeMax, abs($stat['solde']));

            $emetteur = self::MARQUES_SUIVIES[$stat['marque']] ?? null;
            $suivie = null !== $emetteur;

            if ($suivie) {
                $infos = $couverture[$emetteur] ?? ['nb_dg' => 0, 'dernier_scrap' => null];
                $suivies[] = $stat + [
                    'nb_dg_base' => $infos['nb_dg'],
                    'dernier_scrap' => $infos['dernier_scrap'],
                ];
                $totalDgBase += $infos['nb_dg'];
            } else {
                $nonSuivies[] = $stat;
            }

            // Donnees du graphique : toutes marques confondues, deja triees par solde.
            $classement[] = [
                'marque' => $stat['marque'],
                'solde' => $stat['solde'],
                'lignes' => $stat['lignes'],
                'suivie' => $suivie,
            ];
        }

        return $this->render('garanties/statistiques.html.twig', [
            'vue' => $vue,
            'suivies' => $suivies,
            'non_suivies' => $nonSuivies,
            'classement' => $classement,
            'solde_max' => $soldeMax,
            'total_lignes' => $totalLignes,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_dg_base' => $totalDgBase,
            'nb_marques_suivies' => \count($suivies),
            'nb_marques_total' => \count($suivies) + \count($nonSuivies),
            'fraicheur_mirror' => $this->reconciliation->fraicheurMirror(),
        ]);
    }

    /**
     * Construit la liste des pills (filtres actifs) avec leur URL de retrait.
     *
     * @param array<string, mixed> $filtres
     *
     * @return list<array{source: string, label: string, name: string, valeur: string, valeur_brute: string, url: string}>
     */
    private function construirePills(array $filtres, string $tri, string $sens): array
    {
        $pills = [];

        // Filtres multi-valeurs.
        $statutLabels = $this->statutLabels();
        foreach ([
            'types_piece' => ['source' => 'sage',    'label' => 'Type pièce'],
            'concessions' => ['source' => 'sage',    'label' => 'Concession'],
            'etablissements' => ['source' => 'sage',    'label' => 'Établissement'],
            'marques' => ['source' => 'sage',    'label' => 'Marque'],
            'payeurs' => ['source' => 'sage',    'label' => 'Payeur'],
            'statuts_dg' => ['source' => 'portail', 'label' => 'Statut DG'],
            'etats' => ['source' => 'audit',   'label' => 'État'],
            'sites' => ['source' => 'portail', 'label' => 'Site'],
        ] as $cle => $meta) {
            $valeurs = $filtres[$cle] ?? [];
            if (!\is_array($valeurs)) {
                continue;
            }
            foreach ($valeurs as $valeur) {
                $libelle = $valeur;
                if ('statuts_dg' === $cle) {
                    $libelle = $statutLabels[$valeur] ?? $valeur;
                } elseif ('etats' === $cle) {
                    $libelle = self::ETATS_RAPPROCHEMENT[$valeur]['libelle'] ?? $valeur;
                }
                $pills[] = [
                    'source' => $meta['source'],
                    'label' => $meta['label'],
                    'name' => $cle,
                    'valeur' => (string) $libelle,
                    'valeur_brute' => $valeur,
                    'url' => $this->urlSansValeur($filtres, $tri, $sens, $cle, $valeur),
                ];
            }
        }

        // Filtres mono-valeur.
        if (\is_string($filtres['signe'] ?? null) && '' !== $filtres['signe']) {
            $libelles = ['creance' => 'Créances seulement', 'paiement' => 'Paiements seulement', 'soldee' => 'Soldées seulement'];
            $valeur = (string) $filtres['signe'];
            $pills[] = [
                'source' => 'audit',
                'label' => 'Sens',
                'name' => 'signe',
                'valeur' => $libelles[$valeur] ?? $valeur,
                'valeur_brute' => $valeur,
                'url' => $this->urlSansFiltre($filtres, $tri, $sens, 'signe'),
            ];
        }
        if (\is_string($filtres['age'] ?? null) && '' !== $filtres['age']) {
            $tranches = ReconciliationRepository::tranchesAge();
            $valeur = (string) $filtres['age'];
            $pills[] = [
                'source' => 'sage',
                'label' => 'Ancienneté',
                'name' => 'age',
                'valeur' => $tranches[$valeur] ?? $valeur,
                'valeur_brute' => $valeur,
                'url' => $this->urlSansFiltre($filtres, $tri, $sens, 'age'),
            ];
        }
        if (\is_string($filtres['recherche'] ?? null) && '' !== $filtres['recherche']) {
            $valeur = (string) $filtres['recherche'];
            $pills[] = [
                'source' => 'sage',
                'label' => 'Recherche',
                'name' => 'recherche',
                'valeur' => $valeur,
                'valeur_brute' => $valeur,
                'url' => $this->urlSansFiltre($filtres, $tri, $sens, 'recherche'),
            ];
        }

        return $pills;
    }

    /**
     * @param array<string, mixed> $filtres
     */
    private function urlSansFiltre(array $filtres, string $tri, string $sens, string $cle): string
    {
        $copie = $filtres;
        if (\in_array($cle, ['signe', 'age', 'recherche'], true)) {
            $copie[$cle] = null;
        } else {
            $copie[$cle] = [];
        }

        return $this->generateUrl('app_garanties_index', $this->paramsUrl($copie, $tri, $sens));
    }

    /**
     * @param array<string, mixed> $filtres
     */
    private function urlSansValeur(array $filtres, string $tri, string $sens, string $cle, string $valeur): string
    {
        $copie = $filtres;
        if (\is_array($copie[$cle] ?? null)) {
            /** @var list<string> $liste */
            $liste = $copie[$cle];
            $copie[$cle] = array_values(array_diff($liste, [$valeur]));
        }

        return $this->generateUrl('app_garanties_index', $this->paramsUrl($copie, $tri, $sens));
    }

    /**
     * @param array<string, mixed> $filtres
     *
     * @return array<string, string|list<string>>
     */
    private function paramsUrl(array $filtres, string $tri, string $sens): array
    {
        $params = ['tri' => $tri, 'sens' => $sens];
        foreach (['types_piece', 'concessions', 'etablissements', 'marques', 'payeurs', 'statuts_dg', 'etats'] as $k) {
            if (!empty($filtres[$k]) && \is_array($filtres[$k])) {
                /** @var list<string> $liste */
                $liste = $filtres[$k];
                $params[$k] = $liste;
            }
        }
        foreach (['signe', 'age', 'recherche'] as $k) {
            $v = $filtres[$k] ?? null;
            if (\is_string($v) && '' !== $v) {
                $params[$k] = $v;
            }
        }

        return $params;
    }

    #[Route('/export.csv', name: 'app_garanties_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $filtres = $this->extraireFiltres($request);
        $tri = self::texte($request->query->get('tri')) ?? 'chassis';
        $sens = 'asc' === $request->query->get('sens') ? 'asc' : 'desc';

        $statutLabels = $this->statutLabels();

        $response = new StreamedResponse(function () use ($filtres, $tri, $sens, $statutLabels): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            // 20 colonnes : 13 ecriture Progiciel + 5 resume DG matchees + 2 commentaires.
            // 1 ligne CSV = 1 ecriture Progiciel (pas de duplication par DG).
            fputcsv($out, [
                // === Ecriture Progiciel ===
                'Date pièce', 'N° pièce', 'Type', 'Concession', 'Étab.', 'Marque (BU)',
                'Payeur', 'VIN', 'N° OR Progiciel', 'Libellé',
                'Débit €', 'Crédit €', 'Solde €',
                // === DG matchees ===
                'Nb DG',
                'Statut principal',
                'Site portail',
                'Liste DG (n° statut)',
                'Total DG Payés TTC €',
                // === Commentaires (date, auteur, texte) ===
                'Nb commentaires',
                'Commentaires',
            ], ';');

            foreach ($this->reconciliation->iterer($filtres, $tri, $sens) as $r) {
                $statutDg = (string) ($r['statut_dg_principal'] ?? '');
                $statutDgLabel = '' !== $statutDg ? ($statutLabels[$statutDg] ?? $statutDg) : '';

                // Format combiné "79 (Payé) ; 348 (Annulé) ; ..." : on zippe les num_dg et codes statuts.
                $listeDg = '';
                $listeNum = (string) ($r['liste_num_dg'] ?? '');
                $listeCodes = (string) ($r['liste_codes_statuts'] ?? '');
                if ('' !== $listeNum && '' !== $listeCodes) {
                    $nums = array_map('trim', explode(';', $listeNum));
                    $codes = array_map('trim', explode(';', $listeCodes));
                    $paires = [];
                    foreach ($nums as $i => $num) {
                        $code = $codes[$i] ?? '';
                        $label = $statutLabels[$code] ?? $code;
                        $paires[] = sprintf('%s (%s)', $num, $label);
                    }
                    $listeDg = implode(' ; ', $paires);
                }

                $nbDg = (int) ($r['nb_dg'] ?? 0);
                $totalPayeHt = $r['total_montant_dg_paye'] ?? null;
                $totalPayeTtc = null !== $totalPayeHt && (float) $totalPayeHt > 0
                    ? round((float) $totalPayeHt * 1.20, 2)
                    : null;

                fputcsv($out, [
                    // Ecriture Progiciel
                    self::dateFr($r['date_piece'] ?? null),
                    self::texteExcel((string) ($r['no_piece'] ?? '')),
                    (string) ($r['type_piece'] ?? ''),
                    (string) ($r['concession_sage'] ?? ''),
                    (string) ($r['etablissement_sage'] ?? ''),
                    (string) ($r['marque_sage'] ?? ''),
                    (string) ($r['payeur_sage'] ?? ''),
                    self::texteExcel((string) ($r['vin_complet'] ?? '')),
                    self::texteExcel((string) ($r['numor'] ?? '')),
                    (string) ($r['libelle'] ?? ''),
                    self::nombreExcel($r['debit'] ?? null),
                    self::nombreExcel($r['credit'] ?? null),
                    self::nombreExcel($r['solde'] ?? null),
                    // DG matchees
                    $nbDg > 0 ? (string) $nbDg : '',
                    $statutDgLabel,
                    // Site portail : si plusieurs sites differents, on liste tous, sinon juste le principal.
                    (string) ($r['liste_sites'] ?? $r['site_principal'] ?? ''),
                    $listeDg,
                    null !== $totalPayeTtc ? self::nombreExcel($totalPayeTtc) : '',
                    // Commentaires : agreges en SQL (cf. ReconciliationRepository), donc
                    // aucune requete par ligne — l'export reste streame a memoire constante.
                    ((int) ($r['nb_commentaires'] ?? 0)) > 0 ? (string) $r['nb_commentaires'] : '',
                    (string) ($r['commentaires'] ?? ''),
                ], ';');
            }
            fclose($out);
        });

        $nom = sprintf('garanties-audit-%s.csv', (new DateTimeImmutable())->format('Y-m-d-His'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $nom));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    /**
     * Detail d'une ligne Progiciel (par cle ecriture). Affiche l'ecriture + toutes les
     * ecritures du meme chassis + du meme numor + les DG matchees.
     */
    #[Route('/detail/{cleEcriture}', name: 'app_garanties_detail', requirements: ['cleEcriture' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function detail(string $cleEcriture): Response
    {
        $detail = $this->reconciliation->detail($cleEcriture);
        if (null === $detail) {
            throw $this->createNotFoundException('Écriture introuvable.');
        }

        // Ancrage des commentaires : la DG principale si l'ecriture est rapprochee,
        // sinon l'ecriture elle-meme — les lignes orphelines sont commentables aussi.
        $idDgPrincipal = $detail['ecriture']['id_dg_principal'] ?? null;
        $ancre = $this->ancre(
            is_numeric($idDgPrincipal) ? (int) $idDgPrincipal : 0,
            self::texte($detail['ecriture']['cle_ecriture'] ?? null),
            self::texte($detail['ecriture']['oidech'] ?? null),
        );
        $notes = $this->notesDe($ancre);

        // Documents constructeur (PDF) lies aux DG matchees, via dossier_document.
        $dossierIds = array_values(array_filter(
            array_map(static fn (array $d): int => (int) ($d['id'] ?? 0), $detail['dossiers']),
            static fn (int $id): bool => $id > 0,
        ));
        $documents = $this->documents->parDossiers($dossierIds);

        return $this->render('garanties/_detail.html.twig', [
            'detail' => $detail,
            'ancre' => $ancre,
            'notes' => $notes,
            'documents' => $documents,
            'statut_labels' => $this->statutLabels(),
            'statut_meta' => $this->statutMeta(),
            'etats_meta' => self::ETATS_RAPPROCHEMENT,
        ]);
    }

    /**
     * Sert le binaire d'un document (PDF) en affichage inline dans le navigateur.
     * Acces : MODULE_GARANTIES (voir security.yaml), au meme niveau que la
     * consultation des DG, via la
     * contrainte de classe).
     */
    #[Route('/document/{id}', name: 'app_garanties_document', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(int $id): Response
    {
        $meta = $this->documents->metadata($id);
        if (null === $meta) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        $contenu = $this->stockage->lire($meta['reference']);
        if (null === $contenu) {
            // Lien cree (colonne « document » du Sheet) mais PDF pas encore uploade.
            throw $this->createNotFoundException('Document pas encore disponible.');
        }

        $response = new Response($contenu);
        $response->headers->set('Content-Type', ('' !== (string) $meta['mime']) ? (string) $meta['mime'] : 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                ('' !== (string) $meta['nom_fichier']) ? (string) $meta['nom_fichier'] : $meta['reference'].'.pdf',
            ),
        );
        $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');

        return $response;
    }

    /**
     * Panneau lateral des commentaires d'une ligne, ouvert depuis l'icone de la liste.
     * L'ancrage est porte par l'URL (`dg`, ou `cle` + `oidech`) : la ligne le connait
     * deja, le serveur n'a donc AUCUNE requete de resolution a faire.
     */
    #[Route('/commentaires', name: 'app_garanties_commentaires', methods: ['GET'])]
    public function commentaires(Request $request): Response
    {
        $ancre = $this->ancre(
            $request->query->getInt('dg'),
            self::texte($request->query->get('cle')),
            self::texte($request->query->get('oidech')),
        );

        return $this->render('garanties/_commentaires_panneau.html.twig', [
            'ancre' => $ancre,
            'notes' => $this->notesDe($ancre),
            'reference' => self::texte($request->query->get('ref')),
        ]);
    }

    /**
     * Ajout d'un commentaire, quel que soit son ancrage : une seule voie d'ecriture
     * pour le panneau lateral comme pour le panneau de detail.
     */
    #[Route('/commentaires', name: 'app_garanties_commentaire_ajouter', methods: ['POST'])]
    public function ajouterCommentaire(Request $request): Response
    {
        $ancre = $this->ancre(
            $request->request->getInt('dg'),
            self::texte($request->request->get('cle')),
            self::texte($request->request->get('oidech')),
        );

        if (!$this->isCsrfTokenValid($ancre['csrf'], (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $texte = trim((string) $request->request->get('texte'));
        if ('' === $texte) {
            return $this->render('garanties/_notes.html.twig', [
                'ancre' => $ancre,
                'notes' => $this->notesDe($ancre),
                'erreur' => 'Le commentaire ne peut pas être vide.',
            ]);
        }

        $auteur = $this->getUser();
        if (!$auteur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (\strlen($texte) > 5000) {
            $texte = substr($texte, 0, 5000);
        }

        $this->notes->save(null !== $ancre['dossier_id']
            ? Note::surDossier($ancre['dossier_id'], $auteur, $texte)
            : Note::surEcriture((string) $ancre['cle'], $ancre['oidech'], $auteur, $texte));

        return $this->render('garanties/_notes.html.twig', [
            'ancre' => $ancre,
            'notes' => $this->notesDe($ancre),
        ]);
    }

    /**
     * Ancrage d'un commentaire : la DG principale si l'ecriture est rapprochee, sinon
     * l'ecriture elle-meme (couple cle + oidech, cf. Note). Rend aussi les identifiants
     * derives : parametres d'URL, id de frame Turbo, id de jeton CSRF.
     *
     * @return Ancre
     */
    private function ancre(int $dg, ?string $cle, ?string $oidech): array
    {
        if ($dg > 0) {
            return [
                'dossier_id' => $dg,
                'cle' => null,
                'oidech' => null,
                'params' => ['dg' => $dg],
                'frame' => 'commentaires-dg-'.$dg,
                'csrf' => 'commentaire-dg-'.$dg,
            ];
        }

        if (null === $cle) {
            throw $this->createNotFoundException('Ancrage de commentaire manquant.');
        }

        $params = ['cle' => $cle];
        if (null !== $oidech) {
            $params['oidech'] = $oidech;
        }

        // L'id de frame doit etre un identifiant HTML : on ne garde que l'alphanumerique,
        // avec repli sur une empreinte si la cle n'en contient pas. Le jeton CSRF, lui,
        // est bati sur les valeurs BRUTES : deux cles distinctes ne doivent jamais
        // partager un jeton, meme si leur version nettoyee coincide.
        $brut = $cle.'|'.($oidech ?? '');
        $suffixe = preg_replace('/[^A-Za-z0-9]+/', '', $cle.($oidech ?? ''));
        if (null === $suffixe || '' === $suffixe) {
            $suffixe = substr(sha1($brut), 0, 12);
        }

        return [
            'dossier_id' => null,
            'cle' => $cle,
            'oidech' => $oidech,
            'params' => $params,
            'frame' => 'commentaires-ec-'.$suffixe,
            'csrf' => 'commentaire-ec-'.$brut,
        ];
    }

    /**
     * @param Ancre $ancre
     *
     * @return list<Note>
     */
    private function notesDe(array $ancre): array
    {
        return null !== $ancre['dossier_id']
            ? $this->notes->findByDossier($ancre['dossier_id'])
            : $this->notes->findByEcriture((string) $ancre['cle'], $ancre['oidech']);
    }

    /**
     * @return array<int|string, string> code statut DG => libelle FR
     */
    private function statutLabels(): array
    {
        $map = [];
        foreach (StatutDg::cases() as $statut) {
            $map[$statut->value] = $statut->libelle();
        }
        $map['23,24'] = 'En traitement';

        return $map;
    }

    /**
     * @return array<int|string, array{libelle: string, ton: string}>
     */
    private function statutMeta(): array
    {
        $map = [];
        foreach (StatutDg::cases() as $statut) {
            $famille = $statut->famille();
            $map[$statut->value] = ['libelle' => $statut->libelle(), 'ton' => $famille->ton()];
        }

        return $map;
    }

    private static function texte(mixed $valeur): ?string
    {
        if (!\is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return '' !== $valeur ? $valeur : null;
    }

    /**
     * @return array{types_piece: list<string>, concessions: list<string>, etablissements: list<string>, marques: list<string>, payeurs: list<string>, statuts_dg: list<string>, etats: list<string>, signe: ?string, age: ?string, recherche: ?string}
     */
    private function extraireFiltres(Request $request): array
    {
        $signeAuth = ['creance', 'paiement', 'soldee'];
        $signe = $request->query->get('signe');
        $age = $request->query->get('age');

        return [
            'types_piece' => self::listeChainesNonVides(self::lireBrut($request, 'types_piece')),
            'concessions' => self::listeChainesNonVides(self::lireBrut($request, 'concessions')),
            'etablissements' => self::listeChainesNonVides(self::lireBrut($request, 'etablissements')),
            'marques' => self::listeChainesNonVides(self::lireBrut($request, 'marques')),
            'payeurs' => self::listeChainesNonVides(self::lireBrut($request, 'payeurs')),
            'statuts_dg' => self::listeChainesNonVides(self::lireBrut($request, 'statuts_dg')),
            'etats' => self::listeChainesNonVides(self::lireBrut($request, 'etats')),
            'sites' => self::listeChainesNonVides(self::lireBrut($request, 'sites')),
            'signe' => \is_string($signe) && \in_array($signe, $signeAuth, true) ? $signe : null,
            'age' => \is_string($age) && isset(ReconciliationRepository::tranchesAge()[$age]) ? $age : null,
            'recherche' => self::texte($request->query->get('recherche')),
        ];
    }

    /**
     * Lit un parametre tolerant aux deux formats : ?cle=val ou ?cle[]=val.
     *
     * @return array<int|string, mixed>
     */
    private static function lireBrut(Request $request, string $cle): array
    {
        $valeur = $request->query->all()[$cle] ?? null;
        if (\is_array($valeur)) {
            return $valeur;
        }
        if (\is_string($valeur) && '' !== $valeur) {
            return [$valeur];
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $valeurs
     *
     * @return list<string>
     */
    private static function listeChainesNonVides(array $valeurs): array
    {
        $vus = [];
        $out = [];
        foreach ($valeurs as $v) {
            if (!\is_string($v) && !\is_int($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ('' === $v || isset($vus[$v])) {
                continue;
            }
            $vus[$v] = true;
            $out[] = $v;
            if (\count($out) >= 100) {
                break;
            }
        }

        return $out;
    }

    /**
     * Force Excel a interpreter la chaine comme du texte (sinon "0E047554"
     * devient "0,00E+47554" et "01001658" perd son zero initial).
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
     * Convertit "YYYY-MM-DD" en "DD/MM/YYYY".
     */
    private static function dateFr(mixed $valeur): string
    {
        if (!\is_string($valeur) || '' === $valeur) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $valeur);

        return false !== $date ? $date->format('d/m/Y') : $valeur;
    }
}
