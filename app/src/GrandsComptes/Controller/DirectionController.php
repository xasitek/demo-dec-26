<?php

declare(strict_types=1);

namespace App\GrandsComptes\Controller;

use App\GrandsComptes\Moteur\Circuit;
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
 * Les deux vues de direction de l'outil 7, et le pont vers l'outil 6.
 *
 *   /dossiers/mon-site    directeur de concession : SON perimetre, et rien d'autre
 *   /dossiers/pilotage    directeur comptable : toutes les files, chaque chiffre
 *                         ouvrant la file qui le traite
 *   /dossiers/pont        les creances de l'outil 6 que l'outil 7 suit
 *
 * Le perimetre est reutilise du module de pilotage : c'est le meme service qui
 * resout l'etablissement d'un directeur de concession, et deux resolutions
 * concurrentes finiraient par diverger. Il filtre DANS les requetes, jamais a
 * l'affichage.
 */
#[Route('/dossiers')]
#[IsGranted('MODULE_LIVRAISON')]
final class DirectionController extends AbstractController
{
    private const PAR_PAGE = 40;

    public function __construct(
        private readonly Connection $cnx,
        private readonly Pont $pont,
        private readonly Perimetre $perimetre,
    ) {
    }

    // ============================================ le directeur de concession

    /**
     * Son perimetre, et rien d'autre.
     *
     * Une vue de production : ce qui bloque chez LUI, qui en est responsable,
     * depuis quand, et quelle est la prochaine action. Pas un tableau de bord.
     */
    #[Route('/mon-site', name: 'app_gc_mon_site', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_DIRECTEUR') or is_granted('ROLE_MANAGER')"))]
    public function monSite(Request $requete): Response
    {
        $t0 = microtime(true);
        $mien = $this->perimetre->etablissement();
        $etat = (string) $requete->query->get('etat', 'a_completer');
        $page = max(1, (int) $requete->query->get('page', 1));

        $filtres = null !== $mien ? ['etablissement_id' => $mien] : [];
        [$lignes, $total] = $this->lignes($etat, $filtres, $page);

        return $this->render('grands_comptes/mon_site.html.twig', [
            'perimetre' => $mien,
            'perimetre_par_defaut' => $this->perimetre->resoluParDefaut(),
            'etablissement' => null !== $mien ? $this->cnx->fetchAssociative(
                'SELECT * FROM affectation.etablissement WHERE id = ?', [$mien]) : null,
            'sommaire' => $this->sommaire($filtres),
            'etat' => $etat,
            'lignes' => $lignes,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAR_PAGE)),
            'par_page' => self::PAR_PAGE,
            'pieces_manquantes' => $this->parPieceManquante($filtres),
            'anciennete' => $this->anciennete($filtres),
            'etats' => Circuit::ETATS,
            'verdicts' => Referentiel::VERDICTS,
            'arrete' => Referentiel::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    /** Demander a la secretaire de completer, ou commenter. Trace, comme le reste. */
    #[Route('/mon-site/{id}/demander', name: 'app_gc_demander', methods: ['POST'],
        requirements: ['id' => 'DLV-\d+'])]
    #[IsGranted(new Expression("is_granted('ROLE_DIRECTEUR') or is_granted('ROLE_MANAGER')"))]
    public function demander(string $id, Request $requete): RedirectResponse
    {
        $mien = $this->perimetre->etablissement();
        $dossier = $this->cnx->fetchAssociative(
            'SELECT id, etablissement_id FROM grands_comptes.dossier WHERE id = ?', [$id]);
        if (false === $dossier) {
            throw $this->createNotFoundException('Dossier inconnu : '.$id);
        }
        // Le perimetre PRIME : un directeur ne relance pas le site d'un autre.
        if (null !== $mien && $mien !== $dossier['etablissement_id']) {
            throw $this->createAccessDeniedException('Dossier hors de votre périmètre.');
        }

        $commentaire = trim((string) $requete->request->get('commentaire', ''));
        $this->cnx->insert('grands_comptes.acte', [
            'dossier_id' => $id,
            'type' => 'note',
            'commentaire' => '' !== $commentaire
                ? mb_substr('Demande de la direction : '.$commentaire, 0, 400)
                : 'Demande de la direction : compléter le dossier.',
            'auteur' => $this->getUser()?->getUserIdentifier() ?? 'inconnu',
            'role' => 'ROLE_DIRECTEUR',
        ]);
        $this->addFlash('fait', 'Demande adressée au site, datée et signée, sur le dossier '.$id.'.');

        return $this->redirectToRoute('app_gc_mon_site', ['etat' => (string) $requete->request->get('etat', 'a_completer')]);
    }

    // ============================================= le directeur comptable

    /**
     * Toutes les files, et chaque chiffre ouvre la file qui le traite.
     *
     * Aucun graphique sans action derriere : c'est la regle posee pour les dix
     * outils, et elle vaut ici comme ailleurs.
     */
    #[Route('/pilotage', name: 'app_gc_pilotage', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_MANAGER') or is_granted('ROLE_ADMIN')"))]
    public function pilotage(Request $requete): Response
    {
        $t0 = microtime(true);
        $filtres = [];
        $etab = (string) $requete->query->get('etablissement_id', '');
        if ('' !== $etab) {
            $filtres['etablissement_id'] = $etab;
        }

        return $this->render('grands_comptes/pilotage.html.twig', [
            'sommaire' => $this->sommaire($filtres),
            'par_loueur' => $this->parLoueur($filtres),
            'pieces_manquantes' => $this->parPieceManquante($filtres),
            'par_etablissement' => $this->parEtablissement($filtres),
            'anciennete' => $this->anciennete($filtres),
            'charge' => $this->charge($filtres),
            'verdicts_controle' => $this->parVerdict($filtres),
            'pont' => $this->pont->sousTotal($filtres),
            'qualites' => Pont::QUALITES,
            'etats' => Circuit::ETATS,
            'verdicts' => Referentiel::VERDICTS,
            'filtres' => $filtres,
            'etablissements' => $this->cnx->fetchAllKeyValue(
                'SELECT id, nom FROM affectation.etablissement ORDER BY id'),
            'arrete' => Referentiel::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    // ==================================================== le pont vers l'outil 6

    #[Route('/pont', name: 'app_gc_pont', methods: ['GET'])]
    public function pont(Request $requete): Response
    {
        $t0 = microtime(true);
        $mien = $this->perimetre->etablissement();
        $filtres = [];
        if (null !== $mien) {
            $filtres['etablissement_id'] = $mien;
        }
        foreach (['qualite', 'resolus'] as $cle) {
            $v = (string) $requete->query->get($cle, '');
            if ('' !== $v) {
                $filtres[$cle] = $v;
            }
        }
        $page = max(1, (int) $requete->query->get('page', 1));

        return $this->render('grands_comptes/pont.html.twig', [
            'pont' => $this->pont->sousTotal(
                null !== $mien ? ['etablissement_id' => $mien] : []),
            'lignes' => $this->pont->creancesLiees($filtres, self::PAR_PAGE, ($page - 1) * self::PAR_PAGE),
            'qualites' => Pont::QUALITES,
            'filtres' => $filtres,
            'page' => $page,
            'perimetre' => $mien,
            'etats' => Circuit::ETATS,
            'verdicts' => Referentiel::VERDICTS,
            'arrete' => Referentiel::ARRETE,
            'ms' => (microtime(true) - $t0) * 1000,
        ]);
    }

    // ------------------------------------------------------------------ interne

    /**
     * Le sommaire des cinq etats. Meme requete que la file du comptable, un
     * seul endroit ou l'etat se calcule.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array{code: string, libelle: string, teinte: string, n: int, montant: float}>
     */
    private function sommaire(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT x.etat, count(*) n, coalesce(sum(d.montant_facture), 0) montant
              FROM etats x JOIN grands_comptes.dossier d ON d.id = x.id
             WHERE 1 = 1'.$ou.' GROUP BY 1', $args);

        $brut = [];
        foreach ($lignes as $l) {
            $brut[(string) $l['etat']] = ['n' => (int) $l['n'], 'montant' => (float) $l['montant']];
        }

        $out = [];
        foreach (['a_completer', 'renvoye', 'a_instruire', 'a_verifier', 'conforme'] as $code) {
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
     * Les lignes d'un etat, avec ce que le directeur doit voir : la piece qui
     * manque, le montant bloque, le responsable, l'anciennete, la suite.
     *
     * @param array<string, string> $filtres
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function lignes(string $etat, array $filtres, int $page): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        $args['etat'] = $etat;
        $args['arrete'] = Referentiel::ARRETE;

        $total = (int) $this->cnx->fetchOne($this->sqlEtat().'
            SELECT count(*) FROM etats x JOIN grands_comptes.dossier d ON d.id = x.id
             WHERE x.etat = :etat'.$ou, $args);

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT d.id, d.immatriculation, d.modele, d.energie, d.montant_facture,
                   d.date_livraison, d.etablissement_id, d.secretaire_id,
                   (:arrete::date - d.date_livraison) AS age,
                   l.nom AS loueur_nom, l.delai_paiement,
                   e.nom AS etablissement_nom,
                   x.etat, x.manquantes, x.attendues, x.presentes,
                   ct.verdict, ct.nb_anomalies, ct.nb_bloquantes,
                   (SELECT string_agg(p.type_piece, \', \' ORDER BY p.type_piece)
                      FROM grands_comptes.piece p
                     WHERE p.dossier_id = d.id AND NOT p.presente
                       AND p.exigence IN (\'obligatoire\',\'si_electrique\',\'si_premier_reglt\')) AS pieces_manquantes,
                   (SELECT max(a.fait_le) FROM grands_comptes.acte a WHERE a.dossier_id = d.id) AS dernier_acte,
                   lien.facture_id AS creance_liee
              FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
              JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
              LEFT JOIN affectation.etablissement e ON e.id = d.etablissement_id
              LEFT JOIN grands_comptes.controle ct ON ct.dossier_id = d.id
              LEFT JOIN grands_comptes.lien_creance_dossier lien ON lien.dossier_id = d.id
             WHERE x.etat = :etat'.$ou.'
             ORDER BY d.montant_facture DESC
             LIMIT '.self::PAR_PAGE.' OFFSET '.(($page - 1) * self::PAR_PAGE), $args);

        return [$lignes, $total];
    }

    /**
     * La repartition par piece manquante : quelle piece bloque le plus d'argent.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    private function parPieceManquante(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            "SELECT p.type_piece, t.libelle, count(*) n,
                    coalesce(sum(d.montant_facture), 0) montant
               FROM grands_comptes.piece p
               JOIN grands_comptes.dossier d ON d.id = p.dossier_id
               JOIN grands_comptes.type_piece t ON t.code = p.type_piece
              WHERE NOT p.presente
                AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')".$ou.'
              GROUP BY 1, 2 ORDER BY 4 DESC', $args);

        return $lignes;
    }

    /**
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    private function parLoueur(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT l.loueur_id, l.nom, l.delai_paiement, l.nb_obligatoires,
                   count(*) dossiers,
                   count(*) FILTER (WHERE x.etat = \'a_completer\') a_completer,
                   count(*) FILTER (WHERE x.etat = \'a_verifier\') a_verifier,
                   count(*) FILTER (WHERE x.etat = \'renvoye\') renvoyes,
                   count(*) FILTER (WHERE x.etat = \'conforme\') conformes,
                   coalesce(sum(d.montant_facture) FILTER (WHERE x.etat IN (\'a_completer\',\'renvoye\',\'a_instruire\')), 0) bloque
              FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
              JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
             WHERE 1 = 1'.$ou.'
             GROUP BY 1, 2, 3, 4 ORDER BY 10 DESC', $args);

        return $lignes;
    }

    /**
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    private function parEtablissement(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT d.etablissement_id, e.nom, e.societe_id, count(*) dossiers,
                   count(*) FILTER (WHERE x.etat = \'a_completer\') a_completer,
                   count(*) FILTER (WHERE x.etat = \'renvoye\') renvoyes,
                   coalesce(sum(d.montant_facture) FILTER (WHERE x.etat IN (\'a_completer\',\'renvoye\',\'a_instruire\')), 0) bloque
              FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
              LEFT JOIN affectation.etablissement e ON e.id = d.etablissement_id
             WHERE 1 = 1'.$ou.'
             GROUP BY 1, 2, 3 ORDER BY 7 DESC LIMIT 20', $args);

        return $lignes;
    }

    /**
     * L'anciennete des dossiers bloques. Un dossier qui dort trois mois est un
     * loueur qui n'a pas paye depuis trois mois.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    private function anciennete(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        $args['arrete'] = Referentiel::ARRETE;
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat()."
            SELECT CASE
                     WHEN (:arrete::date - d.date_livraison) < 30 THEN '0 a 30 jours'
                     WHEN (:arrete::date - d.date_livraison) < 90 THEN '31 a 90 jours'
                     WHEN (:arrete::date - d.date_livraison) < 180 THEN '91 a 180 jours'
                     ELSE 'plus de 180 jours'
                   END tranche,
                   CASE
                     WHEN (:arrete::date - d.date_livraison) < 30 THEN 1
                     WHEN (:arrete::date - d.date_livraison) < 90 THEN 2
                     WHEN (:arrete::date - d.date_livraison) < 180 THEN 3
                     ELSE 4
                   END rang,
                   count(*) n, coalesce(sum(d.montant_facture), 0) montant
              FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
             WHERE x.etat IN ('a_completer','renvoye','a_instruire')".$ou.'
             GROUP BY 1, 2 ORDER BY 2', $args);

        return $lignes;
    }

    /**
     * La charge de travail : combien de dossiers par secretaire, et combien
     * attendent le comptable.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    private function charge(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($this->sqlEtat().'
            SELECT d.secretaire_id, d.secretaire_id AS nom,
                   count(*) FILTER (WHERE x.etat = \'a_completer\') a_completer,
                   count(*) FILTER (WHERE x.etat = \'renvoye\') renvoyes,
                   count(*) dossiers,
                   coalesce(sum(d.montant_facture) FILTER (WHERE x.etat IN (\'a_completer\',\'renvoye\')), 0) bloque
              FROM etats x
              JOIN grands_comptes.dossier d ON d.id = x.id
             WHERE 1 = 1'.$ou.'
             GROUP BY 1, 2 HAVING count(*) FILTER (WHERE x.etat IN (\'a_completer\',\'renvoye\')) > 0
             ORDER BY 6 DESC LIMIT 15', $args);

        return $lignes;
    }

    /**
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    private function parVerdict(array $filtres): array
    {
        [$ou, $args] = $this->clause($filtres, 'd');
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT ct.verdict, count(*) n, coalesce(sum(d.montant_facture), 0) montant,
                    coalesce(sum(ct.nb_anomalies), 0) anomalies
               FROM grands_comptes.controle ct
               JOIN grands_comptes.dossier d ON d.id = ct.dossier_id
              WHERE 1 = 1'.$ou.'
              GROUP BY 1 ORDER BY 2 DESC', $args);

        return $lignes;
    }

    /**
     * La clause de perimetre. Il filtre DANS la requete : un total calcule puis
     * masque resterait un total transmis au navigateur.
     *
     * @param array<string, string> $filtres
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function clause(array $filtres, string $alias): array
    {
        if (isset($filtres['etablissement_id']) && '' !== $filtres['etablissement_id']) {
            return [' AND '.$alias.'.etablissement_id = :etab',
                ['etab' => $filtres['etablissement_id']]];
        }

        return ['', []];
    }

    /** La meme deduction d'etat que la file du comptable, ecrite une seule fois. */
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
                     count(*) FILTER (WHERE p.presente
                       AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')) presentes,
                     count(*) FILTER (WHERE NOT p.presente
                       AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')) manquantes
                FROM grands_comptes.piece p GROUP BY 1
            ), etats AS (
              SELECT d.id,
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
}
