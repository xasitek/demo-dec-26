<?php

declare(strict_types=1);

namespace App\GrandsComptes\Controller;

use App\GrandsComptes\Moteur\Circuit;
use App\GrandsComptes\Moteur\Controle;
use App\GrandsComptes\Moteur\Fabricant;
use App\GrandsComptes\Moteur\Pont;
use App\GrandsComptes\Moteur\Referentiel;
use App\Pilotage\Moteur\Perimetre;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Outil 7 — les dossiers grands comptes.
 *
 *   /dossiers                     secretaire : mes dossiers, et ce qui manque
 *   /dossiers/deposer/{id}        secretaire : la page unique de depot
 *   /dossiers/controle            comptable  : la file a verifier
 *   /dossiers/controle/{id}       comptable  : le controle, en deux volets
 *   /dossiers/piece/{id}          le document lui-meme, synthetique
 *   /dossiers/methode             la grille, le referentiel, les limites
 *
 * Un poste de travail, pas une consultation : chaque ecran porte une file, un
 * dossier, un geste, une decision et une trace.
 */
#[Route('/dossiers')]
#[IsGranted('MODULE_LIVRAISON')]
final class DossiersController extends AbstractController
{
    /** Combien de dossiers par page de file. */
    private const PAR_PAGE = 40;

    public function __construct(
        private readonly Connection $cnx,
        private readonly Controle $controle,
        private readonly Circuit $circuit,
        private readonly Fabricant $fabricant,
        private readonly Pont $pont,
        private readonly Perimetre $perimetre,
    ) {
    }

    // ==================================================== la file de la secretaire

    #[Route('', name: 'app_gc_secretaire', methods: ['GET'])]
    public function mesDossiers(Request $requete): Response
    {
        $t0 = microtime(true);
        $etat = (string) $requete->query->get('etat', 'a_completer');
        $q = trim((string) $requete->query->get('q', ''));
        $page = max(1, (int) $requete->query->get('page', 1));

        [$lignes, $total] = $this->fileDeTravail($etat, $q, $page, true);

        return $this->render('grands_comptes/secretaire.html.twig', [
            'sommaire' => $this->sommaire(true),
            'etat' => $etat,
            'lignes' => $lignes,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAR_PAGE)),
            'par_page' => self::PAR_PAGE,
            'q' => $q,
            'etats' => Circuit::ETATS,
            'arrete' => Referentiel::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /** La page unique de depot. Tout ce que la secretaire doit voir y tient. */
    #[Route('/deposer/{id}', name: 'app_gc_deposer', methods: ['GET'],
        requirements: ['id' => 'DLV-\d+'])]
    public function deposer(string $id): Response
    {
        $t0 = microtime(true);
        $vue = $this->vueDossier($id);

        $mien = $this->perimetre->etablissement();
        if (null !== $mien && $mien !== ($vue['dossier']['etablissement_id'] ?? null)) {
            throw $this->createAccessDeniedException('Dossier hors de votre périmètre.');
        }

        return $this->render('grands_comptes/deposer.html.twig', $vue + [
            'exigences' => Referentiel::EXIGENCES,
            'etats' => Circuit::ETATS,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /** Deposer une piece, ou certifier et soumettre. */
    #[Route('/deposer/{id}', name: 'app_gc_deposer_agir', methods: ['POST'],
        requirements: ['id' => 'DLV-\d+'])]
    public function deposerAgir(string $id, Request $requete): RedirectResponse
    {
        $acte = (string) $requete->request->get('acte', '');
        $dossier = $this->cnx->fetchAssociative(
            'SELECT id FROM grands_comptes.dossier WHERE id = ?', [$id]);
        if (false === $dossier) {
            throw $this->createNotFoundException('Dossier inconnu : '.$id);
        }

        if ('deposer_piece' === $acte) {
            $pieceId = (string) $requete->request->get('piece_id', '');
            $piece = $this->cnx->fetchAssociative(
                'SELECT id, type_piece FROM grands_comptes.piece WHERE id = ? AND dossier_id = ?',
                [$pieceId, $id]);
            if (false === $piece) {
                $this->addFlash('erreur', 'Cette pièce n’appartient pas à ce dossier.');

                return $this->redirectToRoute('app_gc_deposer', ['id' => $id]);
            }
            // Le depot rend la piece presente et lisible : c'est le geste reel.
            $this->cnx->executeStatement(
                'UPDATE grands_comptes.piece SET presente = true, lisible = true WHERE id = ?',
                [$pieceId]);
            $this->circuit->poser($id, 'deposer_piece', $pieceId, null,
                (string) $requete->request->get('commentaire', ''));
            $this->addFlash('fait', sprintf('Pièce %s déposée au dossier.', $piece['type_piece']));

            return $this->redirectToRoute('app_gc_deposer', ['id' => $id]);
        }

        if ('certifier' === $acte) {
            if (!$requete->request->getBoolean('certification')) {
                $this->addFlash('erreur', 'La certification est obligatoire pour soumettre le dossier.');

                return $this->redirectToRoute('app_gc_deposer', ['id' => $id]);
            }
            $etat = $this->circuit->etat($id);
            if ([] !== $etat['manquantes']) {
                $this->addFlash('erreur', sprintf(
                    'Il manque encore %s : le dossier ne peut pas être soumis.',
                    implode(', ', $etat['manquantes'])));

                return $this->redirectToRoute('app_gc_deposer', ['id' => $id]);
            }
            $this->circuit->poser($id, 'certifier', null, null,
                (string) $requete->request->get('commentaire', ''));
            $this->addFlash('fait', 'Dossier certifié et soumis au contrôle comptable.');

            return $this->redirectToRoute('app_gc_secretaire', ['etat' => 'a_verifier']);
        }

        $this->addFlash('erreur', 'Acte inconnu : rien n’a été écrit.');

        return $this->redirectToRoute('app_gc_deposer', ['id' => $id]);
    }

    // ===================================================== la file du comptable

    // La file du groupe est reservee aux postes qui controlent. Un directeur de
    // concession ne la parcourt pas : il ouvre les dossiers de SON site depuis
    // « Mon site », et le perimetre l'y borne.
    #[Route('/controle', name: 'app_gc_controle', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_COMPTABLE') or is_granted('ROLE_MANAGER')"))]
    public function fileControle(Request $requete): Response
    {
        $t0 = microtime(true);
        $etat = (string) $requete->query->get('etat', 'a_verifier');
        $q = trim((string) $requete->query->get('q', ''));
        $page = max(1, (int) $requete->query->get('page', 1));

        [$lignes, $total] = $this->fileDeTravail($etat, $q, $page, false);

        return $this->render('grands_comptes/controle_file.html.twig', [
            'sommaire' => $this->sommaire(false),
            'etat' => $etat,
            'lignes' => $lignes,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAR_PAGE)),
            'par_page' => self::PAR_PAGE,
            'q' => $q,
            'etats' => Circuit::ETATS,
            'verdicts' => Referentiel::VERDICTS,
            'arrete' => Referentiel::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /** Le controle, en deux volets : la piece a gauche, le document a droite. */
    #[Route('/controle/{id}', name: 'app_gc_controle_dossier', methods: ['GET'],
        requirements: ['id' => 'DLV-\d+'])]
    public function controler(string $id, Request $requete): Response
    {
        $t0 = microtime(true);
        $vue = $this->vueDossier($id);

        // Le perimetre PRIME. Un directeur de concession peut ouvrir un dossier
        // de SON site ; il ne doit pas atteindre celui d'un autre en devinant
        // son identifiant. Le controle se fait ici, pas seulement a l'affichage.
        $mien = $this->perimetre->etablissement();
        if (null !== $mien && $mien !== ($vue['dossier']['etablissement_id'] ?? null)) {
            throw $this->createAccessDeniedException('Dossier hors de votre périmètre.');
        }
        $analyse = $this->controle->analyser($id);

        // La piece ouverte a droite. Par defaut, celle qui porte la premiere
        // anomalie : c'est celle que le comptable veut voir.
        $ouverte = (string) $requete->query->get('piece', '');
        if ('' === $ouverte) {
            $typeVise = $analyse['anomalies'][0]['type_piece'] ?? '';
            foreach ($vue['pieces'] as $p) {
                if ($p['type_piece'] === $typeVise && $p['presente']) {
                    $ouverte = (string) $p['id'];
                    break;
                }
            }
        }
        if ('' === $ouverte) {
            foreach ($vue['pieces'] as $p) {
                if ($p['presente']) {
                    $ouverte = (string) $p['id'];
                    break;
                }
            }
        }

        $parPiece = [];
        foreach ($analyse['anomalies'] as $a) {
            $parPiece[(string) $a['type_piece']][] = $a;
        }

        return $this->render('grands_comptes/controle_dossier.html.twig', $vue + [
            'analyse' => $analyse,
            'anomalies_par_piece' => $parPiece,
            'piece_ouverte' => $ouverte,
            'verdicts' => Referentiel::VERDICTS,
            'exigences' => Referentiel::EXIGENCES,
            'suites' => Referentiel::SUITES,
            'etats' => Circuit::ETATS,
            'actes' => Circuit::ACTES,
            'limite' => Referentiel::LIMITE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /** La decision du comptable. POST : une decision n'est pas une lecture. */
    #[Route('/controle/{id}/decider', name: 'app_gc_decider', methods: ['POST'],
        requirements: ['id' => 'DLV-\d+'])]
    public function decider(string $id, Request $requete): RedirectResponse
    {
        $acte = (string) $requete->request->get('acte', '');
        if (!\in_array($acte, ['valider', 'renvoyer', 'instruire', 'note'], true)) {
            $this->addFlash('erreur', 'Décision inconnue : rien n’a été écrit.');

            return $this->redirectToRoute('app_gc_controle_dossier', ['id' => $id]);
        }

        $analyse = $this->controle->analyser($id);

        // Le garde-fou : on ne valide pas un dossier que le controle n'a pas
        // pu conclure, et on ne renvoie pas un dossier dont aucune anomalie
        // n'est etablie. La doctrine passe avant le clic.
        if ('valider' === $acte && 'conforme' !== $analyse['verdict']) {
            $this->addFlash('erreur', sprintf(
                'Le contrôle conclut « %s » : la validation est refusée. '
                .'Un dossier incomplet s’instruit, un dossier non conforme se renvoie.',
                Referentiel::VERDICTS[$analyse['verdict']]['libelle'] ?? $analyse['verdict']));

            return $this->redirectToRoute('app_gc_controle_dossier', ['id' => $id]);
        }
        if ('renvoyer' === $acte && 'non_conforme' !== $analyse['verdict']) {
            $this->addFlash('erreur',
                'Aucune anomalie établie : renvoyer le dossier au site serait un rejet à tort. '
                .'Un doute s’instruit, il ne se tranche pas.');

            return $this->redirectToRoute('app_gc_controle_dossier', ['id' => $id]);
        }

        $this->circuit->poser($id, $acte, null,
            (string) $requete->request->get('code_anomalie', '') ?: null,
            (string) $requete->request->get('commentaire', ''));

        $this->addFlash('fait', match ($acte) {
            'valider' => 'Dossier validé conforme et transmis au loueur.',
            'renvoyer' => sprintf('Dossier renvoyé au site avec %d anomalie%s nommée%s.',
                \count($analyse['anomalies']), \count($analyse['anomalies']) > 1 ? 's' : '',
                \count($analyse['anomalies']) > 1 ? 's' : ''),
            'instruire' => 'Dossier mis à instruire : la pièce ou la valeur manquante est demandée.',
            default => 'Note consignée sur le dossier.',
        });

        return $this->redirectToRoute('note' === $acte
            ? 'app_gc_controle_dossier' : 'app_gc_controle', 'note' === $acte ? ['id' => $id] : []);
    }

    // ======================================================== le document lui-meme

    #[Route('/piece/{id}', name: 'app_gc_piece', methods: ['GET'],
        requirements: ['id' => 'PGC-\d+'])]
    public function piece(string $id): Response
    {
        $doc = $this->fabricant->document($id);
        if (null === $doc) {
            throw $this->createNotFoundException('Pièce inconnue : '.$id);
        }

        // Le document est un fichier synthetique. Il est servi tel quel, dans
        // le volet de droite de l'ecran de controle.
        $reponse = new Response($doc['html']);
        $reponse->headers->set('Content-Type', 'text/html; charset=utf-8');
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    #[Route('/methode', name: 'app_gc_methode', methods: ['GET'])]
    public function methode(): Response
    {
        $t0 = microtime(true);

        return $this->render('grands_comptes/methode.html.twig', [
            'types' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM grands_comptes.type_piece ORDER BY rang'),
            'loueurs' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM grands_comptes.loueur ORDER BY loueur_id'),
            'grille' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM grands_comptes.exigence ORDER BY loueur_id, type_piece'),
            'anomalies' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM grands_comptes.anomalie_ref ORDER BY rang'),
            'scenarios' => $this->cnx->fetchAllAssociative(
                'SELECT * FROM grands_comptes.scenario ORDER BY code'),
            'verdicts' => Referentiel::VERDICTS,
            'exigences' => Referentiel::EXIGENCES,
            'suites' => Referentiel::SUITES,
            'limite' => Referentiel::LIMITE,
            'variantes' => Controle::VARIANTES,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    // ------------------------------------------------------------------ interne

    /**
     * Le sommaire des files : combien de dossiers dans chaque etat.
     *
     * L'etat n'est pas stocke : il se deduit des pieces et des actes. La
     * requete le recalcule donc, et c'est ce qui garantit qu'aucun compteur ne
     * peut mentir.
     *
     * @return list<array{code: string, libelle: string, teinte: string, n: int, montant: float}>
     */
    private function sommaire(bool $pourSecretaire): array
    {
        /** @var array<string, array{n: int, montant: float}> $brut */
        $brut = [];
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT etat, count(*) n, coalesce(sum(montant_facture), 0) montant
              FROM etats GROUP BY 1');
        foreach ($lignes as $l) {
            $brut[(string) $l['etat']] = ['n' => (int) $l['n'], 'montant' => (float) $l['montant']];
        }

        $ordre = $pourSecretaire
            ? ['a_completer', 'renvoye', 'a_instruire', 'a_verifier', 'conforme']
            : ['a_verifier', 'a_instruire', 'renvoye', 'a_completer', 'conforme'];

        $out = [];
        foreach ($ordre as $code) {
            $out[] = [
                'code' => $code,
                'libelle' => Circuit::ETATS[$code]['libelle'],
                'teinte' => Circuit::ETATS[$code]['teinte'],
                'n' => $brut[$code]['n'] ?? 0,
                'montant' => $brut[$code]['montant'] ?? 0.0,
            ];
        }

        return $out;
    }

    /**
     * Les lignes d'une file.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function fileDeTravail(string $etat, string $q, int $page, bool $pourSecretaire): array
    {
        $args = ['etat' => $etat];
        $ou = '';
        if ('' !== $q) {
            $ou = ' AND (d.id ILIKE :q OR d.immatriculation ILIKE :q OR d.vin ILIKE :q
                       OR d.numero_facture ILIKE :q OR l.nom ILIKE :q
                       OR d.etablissement_id ILIKE :q OR e.nom ILIKE :q)';
            $args['q'] = '%'.$q.'%';
        }

        $total = (int) $this->cnx->fetchOne($this->sqlEtat().'
            SELECT count(*) FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
              JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
              LEFT JOIN affectation.etablissement e ON e.id = d.etablissement_id
             WHERE x.etat = :etat'.$ou, $args);

        $decalage = ($page - 1) * self::PAR_PAGE;
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT d.id, d.immatriculation, d.vin8, d.modele, d.energie, d.montant_facture,
                   d.numero_facture, d.date_livraison, d.etablissement_id, d.loueur_id,
                   d.code_scenario, d.premier_reglement,
                   l.nom AS loueur_nom, l.delai_paiement, l.exige_tampon,
                   e.nom AS etablissement_nom,
                   x.etat, x.manquantes, x.attendues, x.presentes,
                   c.verdict, c.nb_anomalies, c.nb_bloquantes
              FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
              JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
              LEFT JOIN affectation.etablissement e ON e.id = d.etablissement_id
              LEFT JOIN grands_comptes.controle c ON c.dossier_id = d.id
             WHERE x.etat = :etat'.$ou.'
             ORDER BY d.montant_facture DESC
             LIMIT '.self::PAR_PAGE.' OFFSET '.$decalage, $args);

        return [$lignes, $total];
    }

    /**
     * La requete qui deduit l'etat de chaque dossier.
     *
     * Elle est ecrite une fois et reutilisee : deux definitions de l'etat
     * divergeraient, et le compteur d'une file finirait par contredire son
     * contenu.
     */
    private function sqlEtat(): string
    {
        return <<<'SQL'
            WITH decision AS (
              SELECT a.dossier_id, a.type,
                     row_number() OVER (PARTITION BY a.dossier_id ORDER BY a.fait_le DESC, a.id DESC) r
                FROM grands_comptes.acte a
               WHERE a.type IN ('certifier','valider','renvoyer','instruire')
            ), pieces AS (
              SELECT p.dossier_id,
                     count(*) FILTER (WHERE p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')) attendues,
                     -- Les presentes se comptent sur la MEME population que les
                     -- attendues : sinon la colonne affiche « 4 / 2 » parce
                     -- qu'une piece optionnelle deposee gonfle le numerateur.
                     count(*) FILTER (WHERE p.presente
                       AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')) presentes,
                     count(*) FILTER (WHERE NOT p.presente
                       AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')) manquantes
                FROM grands_comptes.piece p GROUP BY 1
            ), etats AS (
              SELECT d.id, d.montant_facture,
                     coalesce(pi.attendues, 0) attendues,
                     coalesce(pi.presentes, 0) presentes,
                     coalesce(pi.manquantes, 0) manquantes,
                     CASE
                       WHEN de.type = 'valider' THEN 'conforme'
                       WHEN de.type = 'renvoyer' THEN 'renvoye'
                       WHEN de.type = 'instruire' THEN 'a_instruire'
                       WHEN coalesce(pi.manquantes, 0) > 0 THEN 'a_completer'
                       ELSE 'a_verifier'
                     END etat
                FROM grands_comptes.dossier d
                LEFT JOIN pieces pi ON pi.dossier_id = d.id
                LEFT JOIN decision de ON de.dossier_id = d.id AND de.r = 1
            )
            SQL;
    }

    /**
     * Le socle commun aux deux ecrans de dossier.
     *
     * @return array<string, mixed>
     */
    private function vueDossier(string $id): array
    {
        $dossier = $this->cnx->fetchAssociative(
            'SELECT d.*, l.nom AS loueur_nom, l.delai_paiement, l.exige_tampon,
                    l.accepte_pv_electronique, l.tolerance_centimes,
                    e.nom AS etablissement_nom
               FROM grands_comptes.dossier d
               JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
               LEFT JOIN affectation.etablissement e ON e.id = d.etablissement_id
              WHERE d.id = ?', [$id]);
        if (false === $dossier) {
            throw $this->createNotFoundException('Dossier inconnu : '.$id);
        }

        /** @var list<array<string, mixed>> $pieces */
        $pieces = $this->cnx->fetchAllAssociative(
            'SELECT p.*, t.libelle AS type_libelle, t.role AS type_role, t.rang
               FROM grands_comptes.piece p
               JOIN grands_comptes.type_piece t ON t.code = p.type_piece
              WHERE p.dossier_id = ? ORDER BY t.rang', [$id]);

        return [
            'id' => $id,
            'dossier' => $dossier,
            'pieces' => $pieces,
            'circuit' => $this->circuit->etat($id),
            'historique' => $this->circuit->historique($id),
            // La creance que ce dossier debloque, s'il en debloque une. C'est
            // le chemin de retour vers l'outil 6, et il compte autant que
            // l'aller : un dossier valide ici doit se lire la-bas.
            'creance' => $this->pont->creanceDuDossier($id),
            'qualites_pont' => Pont::QUALITES,
        ];
    }
}
