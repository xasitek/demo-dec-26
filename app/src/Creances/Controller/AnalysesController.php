<?php

declare(strict_types=1);

namespace App\Creances\Controller;

use App\Creances\Repository\CreancesRepository;
use App\Creances\Service\AnalysesService;
use App\Creances\Service\IndicateursService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Analyses : previsions d'encaissement a 3 mois + radar de risque +
 * performance par responsable (site, vendeur, secretaire).
 */
#[Route('/creances/analyses')]
#[IsGranted('ROLE_COMPTABLE')]
final class AnalysesController extends AbstractController
{
    public function __construct(
        private readonly AnalysesService $analyses,
        private readonly IndicateursService $indicateurs,
        private readonly CreancesRepository $creances,
    ) {
    }

    #[Route('', name: 'app_creances_analyses', methods: ['GET'])]
    public function index(): Response
    {
        $radar = $this->analyses->radarRisque();

        // Note : depuis le passage de balance_agee a bal_eloficash (refacto
        // 2026-06-02), `bal_eloficash` ne contient pas nomvendeur ni
        // nomsecretaire. L'analyse par responsable est limitee a
        // l'etablissement.
        return $this->render('creances/analyses.html.twig', [
            'previsions' => $this->analyses->previsionsEncaissement(),
            'radar' => $radar,
            'radar_geometrie' => $this->geometrieRadar($radar),
            'indicateurs' => $this->indicateurs->syntheseGlobale(),
            'perf_etablissements' => $this->creances->repartitionParEtablissement(),
        ]);
    }

    /**
     * Detail d'un responsable : ouvre dans le panneau lateral droit la liste
     * de ses dossiers en cours (top creances + top comptes attribues).
     * L'id passe par le placeholder __ID__ du detail-panel est encode
     * `axe|cle` (separateur pipe pour eviter collision avec noms contenant
     * tiret/espace).
     */
    #[Route('/responsable/{cleEncodee}/detail', name: 'app_creances_responsable_detail', methods: ['GET'])]
    public function responsableDetail(string $cleEncodee): Response
    {
        // Decode l'id "axe|cle" — on accepte aussi base64 pour les noms qui
        // contiennent / ou autres caracteres URL-unsafe.
        $decode = base64_decode(strtr($cleEncodee, '-_', '+/'), true);
        $brut = false !== $decode ? $decode : rawurldecode($cleEncodee);
        $parts = explode('|', $brut, 2);
        if (2 !== count($parts) || '' === trim($parts[1])) {
            throw new NotFoundHttpException('Identifiant responsable invalide.');
        }
        [$axe, $cle] = $parts;
        if (!in_array($axe, ['etablissement'], true)) {
            throw new NotFoundHttpException('Axe inconnu (seul etablissement supporte depuis 2026-06-02).');
        }

        $detail = $this->creances->detailResponsable($axe, $cle);

        return $this->render('creances/_analyses_responsable_detail.html.twig', [
            'detail' => $detail,
        ]);
    }

    /**
     * Pre-calcule les coordonnees SVG des points / labels / grilles du
     * radar (Twig n'a pas cos/sin natifs).
     *
     * @param array{risque: float, echu: float, promesses: float, litiges: float, dso: float} $radar
     *
     * @return array{
     *     centre: array{x: int, y: int},
     *     rayon_max: int,
     *     axes: list<array{label: string, valeur: float, point: array{x: float, y: float}, label_pos: array{x: float, y: float}}>,
     *     grilles: list<string>,
     *     toile: string,
     *     niveau_global: float,
     *     couleur: string
     * }
     */
    private function geometrieRadar(array $radar): array
    {
        $cx = 200;
        $cy = 180;
        $rMax = 130;

        $axesDef = [
            ['cle' => 'risque', 'label' => 'Risque', 'valeur' => $radar['risque']],
            ['cle' => 'echu', 'label' => 'Echu', 'valeur' => $radar['echu']],
            ['cle' => 'promesses', 'label' => 'Promesses', 'valeur' => $radar['promesses']],
            ['cle' => 'litiges', 'label' => 'Litiges', 'valeur' => $radar['litiges']],
            ['cle' => 'dso', 'label' => 'DSO', 'valeur' => $radar['dso']],
        ];

        $axes = [];
        $toilePts = [];
        foreach ($axesDef as $k => $axe) {
            $angle = ($k * 72 - 90) * \M_PI / 180;
            $r = ($axe['valeur'] / 10) * $rMax;
            $px = $cx + $r * cos($angle);
            $py = $cy + $r * sin($angle);
            $lx = $cx + ($rMax + 25) * cos($angle);
            $ly = $cy + ($rMax + 25) * sin($angle);
            $axes[] = [
                'label' => $axe['label'],
                'valeur' => $axe['valeur'],
                'point' => ['x' => round($px, 1), 'y' => round($py, 1)],
                'label_pos' => ['x' => round($lx, 1), 'y' => round($ly, 1)],
            ];
            $toilePts[] = round($px, 1).','.round($py, 1);
        }

        $grilles = [];
        foreach ([2, 4, 6, 8, 10] as $niveau) {
            $r = ($niveau / 10) * $rMax;
            $pts = [];
            for ($k = 0; $k < 5; ++$k) {
                $angle = ($k * 72 - 90) * \M_PI / 180;
                $pts[] = round($cx + $r * cos($angle), 1).','.round($cy + $r * sin($angle), 1);
            }
            $grilles[] = implode(' ', $pts);
        }

        $niveauGlobal = ($radar['risque'] + $radar['echu'] + $radar['promesses'] + $radar['litiges'] + $radar['dso']) / 5;
        $couleur = $niveauGlobal > 6 ? '#e11d48' : ($niveauGlobal > 2 ? '#0284c7' : '#10b981');

        return [
            'centre' => ['x' => $cx, 'y' => $cy],
            'rayon_max' => $rMax,
            'axes' => $axes,
            'grilles' => $grilles,
            'toile' => implode(' ', $toilePts),
            'niveau_global' => round($niveauGlobal, 1),
            'couleur' => $couleur,
        ];
    }
}
