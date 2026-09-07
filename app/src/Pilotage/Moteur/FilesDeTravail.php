<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Les files de travail du comptable.
 *
 * Un tableau de bord repond a « comment ca va ». Une file de travail repond a
 * « qu'est-ce que je fais maintenant ». Ce sont deux objets differents, et le
 * comptable n'a pas besoin du premier pour commencer sa journee : il a besoin
 * de dossiers, dans un ordre, avec un geste possible sur chaque ligne.
 *
 * Chaque file porte donc quatre choses, et pas seulement une requete :
 *   - la QUESTION a laquelle elle repond, ecrite pour l'utilisateur ;
 *   - ce qu'elle COMPTE exactement, pour qu'aucun total ne surprenne ;
 *   - les GESTES autorises sur ses lignes, qui dependent de la nature du
 *     dossier -- on ne relance pas un virement, on ne lettre pas une facture ;
 *   - l'OUTIL vers lequel la suite du dossier appartient.
 *
 * Les dix files sortent des donnees deja produites par les outils 4, 5 et 6.
 * Aucune ne s'invente un chiffre : elles lisent les tables de decision.
 */
final class FilesDeTravail
{
    /**
     * Les dix files, dans l'ordre ou un comptable les traite.
     *
     * L'ordre n'est pas cosmetique. On commence par l'argent deja recu et mal
     * impute -- le traiter fait disparaitre des creances qui n'existent pas --
     * et on finit par la relance, qui ne doit jamais partir avant que les
     * neuf files precedentes aient retire ce qui n'est pas du.
     *
     * @var array<string, array{
     *     libelle: string, question: string, compte: string, objet: string,
     *     outil: string, teinte: string
     * }>
     */
    public const FILES = [
        'virements_non_affectes' => [
            'libelle' => 'Règlements encore non affectés',
            'question' => "L'argent est en banque et n'a pas trouvé son compte client. "
                .'Tant que ce virement dort, la créance correspondante paraît ouverte alors '
                ."qu'elle est payée.",
            'compte' => 'Virements dont le moteur d\'affectation n\'a pas retenu de compte '
                .'automatiquement : exception, refus, ou proposition à valider.',
            'objet' => 'virement',
            'outil' => 'outil-4',
            'teinte' => 'navy',
        ],
        'exceptions_45' => [
            'libelle' => 'Exceptions transmises par les outils 4 et 5',
            'question' => 'Le moteur a rencontré une ambiguïté et a refusé de trancher seul. '
                .'La règle est de ne jamais accepter deux candidats plausibles : la main revient donc ici.',
            'compte' => 'Virements arrêtés par une règle d\'arrêt nommée, et lots de lettrage '
                .'laissés au contrôle humain, avec le motif du moteur.',
            'objet' => 'exception',
            'outil' => 'outil-4',
            'teinte' => 'warning',
        ],
        'credits_non_lettres' => [
            'libelle' => 'Crédits du compte client non lettrés',
            'question' => 'Un crédit dort dans le compte du client sans être rapproché de sa facture. '
                .'Il diminue la créance réelle sans diminuer la créance affichée.',
            'compte' => 'Lignes CRÉDITRICES des comptes 411x qui ne portent ni code de lettrage ni '
                .'appartenance à un lot traité. On compte ici des lignes d\'écriture, pas des factures : '
                ."le retraitement du cockpit, lui, compte les factures soldées par l'outil 5.",
            'objet' => 'ecriture',
            'outil' => 'outil-5',
            'teinte' => 'navy',
        ],
        'comptes_anomalie' => [
            'libelle' => 'Comptes présentant une anomalie',
            'question' => 'Un compte client dont le solde est créditeur est une anomalie : '
                .'le client a trop versé, ou un règlement est imputé au mauvais compte. '
                .'Dans les deux cas, une relance serait une faute.',
            'compte' => 'Comptes 411x dont la somme des débits est inférieure à la somme des crédits, '
                .'toutes écritures et tous établissements confondus. Le décompte porte sur le COMPTE '
                .'client, pas sur le couple compte-établissement : un même compte créditeur présent '
                .'sur trois sites reste un seul compte à traiter.',
            'objet' => 'compte',
            'outil' => 'outil-5',
            'teinte' => 'negative',
        ],
        'dues_30' => [
            'libelle' => 'Factures réellement dues, échues de plus de 30 jours',
            'question' => 'Créances encore ouvertes après la chaîne, sans blocage technique et sans '
                .'décision interne en attente. Le premier palier de relance commence ici.',
            'compte' => 'Factures de cause « ouverte sans cause technique bloquante », non soldées par '
                .'la chaîne, dont l\'échéance dépasse 30 jours à la date d\'arrêté.',
            'objet' => 'facture',
            'outil' => 'outil-10',
            'teinte' => 'warning',
        ],
        'dues_45' => [
            'libelle' => 'Factures réellement dues, échues de plus de 45 jours',
            'question' => 'Le palier où la relance change de ton et de destinataire : '
                .'on écrit au responsable du compte, plus au service comptable.',
            'compte' => 'Même population, échéance dépassée de plus de 45 jours.',
            'objet' => 'facture',
            'outil' => 'outil-10',
            'teinte' => 'warning',
        ],
        'dues_90' => [
            'libelle' => 'Factures réellement dues, échues de plus de 90 jours',
            'question' => 'Au-delà de 90 jours, une créance change de nature aux yeux du recouvrement : '
                .'elle se traite en comité, pas par un courrier de plus.',
            'compte' => 'Même population, échéance dépassée de plus de 90 jours.',
            'objet' => 'facture',
            'outil' => 'outil-9',
            'teinte' => 'negative',
        ],
        'grands_comptes_bloques' => [
            // Le libelle disait « grands comptes » ; la population, elle, est
            // celle des creances bloquees par une piece manquante, ou les
            // loueurs dominent sans etre seuls. On nomme donc ce qui est
            // compte, pas ce qu'on attend d'y trouver.
            'libelle' => 'Dossiers bloqués par une pièce manquante',
            'question' => "Le loueur ne paiera pas tant qu'une pièce manque au dossier. "
                .'Relancer ne sert à rien : il faut nommer la pièce et la demander au site.',
            'compte' => 'Factures dont la cause d\'ouverture est une pièce manquante, non soldées par la chaîne.',
            'objet' => 'facture',
            'outil' => 'outil-7',
            'teinte' => 'gold',
        ],
        'action_concession' => [
            'libelle' => 'Dossiers nécessitant une action de la concession',
            'question' => "La décision n'appartient pas au comptable : geste commercial, litige, "
                .'ou abandon. Le comptable prépare, la concession tranche en comité.',
            'compte' => 'Factures dont la cause d\'ouverture est une décision de concession en attente, '
                .'non soldées par la chaîne.',
            'objet' => 'facture',
            'outil' => 'outil-9',
            'teinte' => 'gold',
        ],
        'relancables' => [
            'libelle' => 'Lignes réellement relançables',
            'question' => 'Ce qui reste après les neuf files précédentes : des créances dues par le '
                .'client, sans blocage, sans décision interne. La relance a un sens ici, et seulement ici.',
            'compte' => "Factures de l'exposition financière à traiter, échues, dont le payeur est le "
                .'client facturé — ni financeur, ni constructeur.',
            'objet' => 'facture',
            'outil' => 'outil-10',
            'teinte' => 'warning',
        ],
    ];

    /** Taille d'une page de file. Assez pour une matinee de travail. */
    public const PAR_PAGE = 50;

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Les dix files avec leur volume, pour la barre d'entree de l'ecran.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array{code: string, libelle: string, teinte: string, objet: string,
     *                    lignes: int, montant: float, traites: int}>
     */
    public function sommaire(array $filtres = []): array
    {
        $out = [];
        foreach (self::FILES as $code => $def) {
            [$sql, $args] = $this->requete($code, $filtres, true);
            $ligne = $this->cnx->fetchAssociative($sql, $args) ?: [];
            $out[] = [
                'code' => $code,
                'libelle' => $def['libelle'],
                'teinte' => $def['teinte'],
                'objet' => $def['objet'],
                'lignes' => (int) ($ligne['lignes'] ?? 0),
                'montant' => (float) ($ligne['montant'] ?? 0),
                'traites' => (int) ($ligne['traites'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Les lignes d'une file.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    public function lignes(string $code, array $filtres = [], int $limite = self::PAR_PAGE, int $decalage = 0): array
    {
        [$sql, $args] = $this->requete($code, $filtres, false);
        $limite = max(1, min($limite, 200));
        $decalage = max(0, $decalage);

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            $sql.' LIMIT '.$limite.' OFFSET '.$decalage, $args);

        return $lignes;
    }

    /**
     * La definition d'une file, ou null si le code est inconnu.
     *
     * @return array{libelle: string, question: string, compte: string, objet: string,
     *               outil: string, teinte: string}|null
     */
    public function definition(string $code): ?array
    {
        return self::FILES[$code] ?? null;
    }

    /**
     * La requete d'une file : soit son volume, soit ses lignes.
     *
     * Le perimetre est applique DANS la requete, jamais a l'affichage : un
     * total calcule puis masque reste un total transmis au navigateur.
     *
     * @param array<string, string> $filtres
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function requete(string $code, array $filtres, bool $volume): array
    {
        $args = [];
        $etab = '';
        if (isset($filtres['etablissement_id']) && '' !== $filtres['etablissement_id']) {
            $etab = (string) $filtres['etablissement_id'];
            $args['etab'] = $etab;
        }
        $arrete = Conventions::ARRETE;
        $args['arrete'] = $arrete;

        // L'origine, pour la seule file qui melange deux natures d'objet.
        $origine = $filtres['origine'] ?? '';
        $sansVirement = 'lot' === $origine ? ' AND false' : '';
        $sansLot = 'virement' === $origine ? ' AND false' : '';

        // La trace : une ligne deja touchee aujourd'hui reste visible, mais
        // porte son geste. On ne fait pas disparaitre un dossier traite --
        // le comptable doit pouvoir verifier ce qu'il a fait.
        $geste = 'LEFT JOIN LATERAL (
                    SELECT g.type, g.niveau, g.note, g.fait_le, g.auteur
                      FROM pilotage.geste g
                     WHERE g.objet_type = %s AND g.objet_id = %s
                     ORDER BY g.fait_le DESC LIMIT 1
                  ) g ON true';

        return match ($code) {
            // ---------------------------------------------------------- outil 4
            'virements_non_affectes' => [$volume
                ? 'SELECT count(*) lignes, coalesce(sum(v.montant),0) montant,
                          count(g.type) traites
                     FROM affectation.decision d
                     JOIN affectation.virement v ON v.id = d.virement_id
                     '.sprintf($geste, "'virement'", 'v.id')."
                    WHERE d.mode = 'ENRICHED' AND d.decision <> 'automatique'"
                : 'SELECT v.id, v.date_operation, v.montant, v.libelle, v.nom_donneur_ordre,
                          d.decision, d.score, d.score_suivant, d.client_propose, d.nb_candidats,
                          c.nom AS client_nom, g.type AS geste, g.note AS geste_note,
                          g.fait_le AS geste_le, g.auteur AS geste_par
                     FROM affectation.decision d
                     JOIN affectation.virement v ON v.id = d.virement_id
                     LEFT JOIN affectation.client c ON c.id = d.client_propose
                     '.sprintf($geste, "'virement'", 'v.id')."
                    WHERE d.mode = 'ENRICHED' AND d.decision <> 'automatique'
                    ORDER BY v.montant DESC", $args],

            'exceptions_45' => [$volume
                ? 'SELECT count(*) lignes, coalesce(sum(x.montant),0) montant, count(x.geste) traites
                     FROM (
                       SELECT v.id, v.montant, g.type AS geste
                         FROM affectation.decision d
                         JOIN affectation.virement v ON v.id = d.virement_id
                         '.sprintf($geste, "'virement'", 'v.id')."
                        WHERE d.mode = 'ENRICHED' AND d.decision IN ('exception','validation')
                          $sansVirement
                       UNION ALL
                       SELECT l.id, l.montant, g.type
                         FROM lettrage.decision ld
                         JOIN lettrage.lot l ON l.id = ld.lot_id
                         ".sprintf($geste, "'lot'", 'l.id')."
                        WHERE ld.verdict IN ('humain','proposition')
                          $sansLot
                     ) x"
                : "SELECT * FROM (
                       SELECT 'virement' AS source, v.id, v.montant, v.libelle AS intitule,
                              d.decision AS verdict, NULL AS methode,
                              coalesce(d.factures, '') AS detail, d.score,
                              g.type AS geste, g.note AS geste_note, g.fait_le AS geste_le,
                              g.auteur AS geste_par
                         FROM affectation.decision d
                         JOIN affectation.virement v ON v.id = d.virement_id
                         ".sprintf($geste, "'virement'", 'v.id')."
                        WHERE d.mode = 'ENRICHED' AND d.decision IN ('exception','validation')
                          $sansVirement
                       UNION ALL
                       SELECT 'lot', l.id, l.montant, coalesce(l.client_id, l.ancre_id),
                              ld.verdict, ld.methode, coalesce(ld.motif, coalesce(ld.arret, '')),
                              ld.nb_indices, g.type, g.note, g.fait_le, g.auteur
                         FROM lettrage.decision ld
                         JOIN lettrage.lot l ON l.id = ld.lot_id
                         ".sprintf($geste, "'lot'", 'l.id')."
                        WHERE ld.verdict IN ('humain','proposition')
                          $sansLot
                     ) x ORDER BY x.montant DESC", $args],

            // ---------------------------------------------------------- outil 5
            'credits_non_lettres' => [$volume
                ? 'SELECT count(*) lignes, coalesce(sum(e.montant),0) montant, count(g.type) traites
                     FROM lettrage.ecriture e
                     '.sprintf($geste, "'ecriture'", 'e.id')."
                    WHERE e.sens = 'C' AND e.compte LIKE '411%' AND e.lot_demo IS NULL
                      AND (e.lettrage IS NULL OR e.lettrage = '')
                      ".($etab ? 'AND e.etablissement_id = :etab' : '')
                : 'SELECT e.id, e.date_ecriture, e.montant, e.compte, e.journal,
                          e.reference_piece, e.immatriculation, e.vin8, e.client_id,
                          e.etablissement_id, c.nom AS client_nom,
                          -- Le pont vers loutil 8 : couche ADDITIVE, jointe en
                          -- lecture. Aucune ecriture, aucun lettrage, aucune cause
                          -- douverture de loutil 6 nest pas modifiee par sa presence.
                          pont.dossier_id AS rbc_dossier_id, pont.reference AS rbc_reference,
                          pont.qualite AS rbc_qualite, rbc.statut AS rbc_statut,
                          g.type AS geste, g.note AS geste_note, g.fait_le AS geste_le, g.auteur AS geste_par
                     FROM lettrage.ecriture e
                     LEFT JOIN affectation.client c ON c.id = e.client_id
                     LEFT JOIN remboursement.pont_o6_demo pont ON pont.ecriture_id = e.id
                     LEFT JOIN remboursement.dossier rbc ON rbc.id = pont.dossier_id
                     '.sprintf($geste, "'ecriture'", 'e.id')."
                    WHERE e.sens = 'C' AND e.compte LIKE '411%' AND e.lot_demo IS NULL
                      AND (e.lettrage IS NULL OR e.lettrage = '')
                      ".($etab ? 'AND e.etablissement_id = :etab' : '').'
                    ORDER BY e.montant DESC', $args],

            'comptes_anomalie' => [$volume
                ? "SELECT count(*) lignes, coalesce(sum(abs(x.solde)),0) montant, count(g.type) traites
                     FROM (
                       SELECT e.client_id, e.compte,
                              sum(CASE WHEN e.sens='D' THEN e.montant ELSE -e.montant END) solde
                         FROM lettrage.ecriture e
                        WHERE e.compte LIKE '411%' ".($etab ? 'AND e.etablissement_id = :etab' : '')."
                        GROUP BY 1,2
                       HAVING sum(CASE WHEN e.sens='D' THEN e.montant ELSE -e.montant END) < 0
                     ) x
                     ".sprintf($geste, "'compte'", 'x.client_id')
                : "SELECT x.client_id, x.compte, x.etablissement_id, x.etablissements,
                          x.solde, x.lignes AS nb_lignes,
                          x.credits, x.debits, c.nom AS client_nom, c.type AS client_type,
                          g.type AS geste, g.note AS geste_note, g.fait_le AS geste_le, g.auteur AS geste_par
                     FROM (
                       SELECT e.client_id, e.compte,
                              min(e.etablissement_id) etablissement_id,
                              count(DISTINCT e.etablissement_id) etablissements,
                              sum(CASE WHEN e.sens='D' THEN e.montant ELSE -e.montant END) solde,
                              count(*) lignes,
                              coalesce(sum(e.montant) FILTER (WHERE e.sens='C'),0) credits,
                              coalesce(sum(e.montant) FILTER (WHERE e.sens='D'),0) debits
                         FROM lettrage.ecriture e
                        WHERE e.compte LIKE '411%' ".($etab ? 'AND e.etablissement_id = :etab' : '')."
                        GROUP BY 1,2
                       HAVING sum(CASE WHEN e.sens='D' THEN e.montant ELSE -e.montant END) < 0
                     ) x
                     LEFT JOIN affectation.client c ON c.id = x.client_id
                     ".sprintf($geste, "'compte'", 'x.client_id').'
                    ORDER BY x.solde ASC', $args],

            // ------------------------------------------------- files de creances
            'dues_30', 'dues_45', 'dues_90', 'grands_comptes_bloques',
            'action_concession', 'relancables' => $this->requeteCreances($code, $volume, $etab, $args, $geste),

            default => throw new InvalidArgumentException('File inconnue : '.$code),
        };
    }

    /**
     * Les six files assises sur les causes d'ouverture des creances.
     *
     * Elles partagent la meme colonne vertebrale -- la table des causes, celle
     * que le cockpit utilise deja -- et ne different que par leur clause.
     * Ecrire six fois la meme requete aurait garanti six divergences.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function requeteCreances(string $code, bool $volume, string $etab, array $args, string $geste): array
    {
        $clause = match ($code) {
            'dues_30' => "co.cause = 'reellement_due' AND NOT co.soldee_par_suite
                          AND co.echeance < :arrete::date - 30",
            'dues_45' => "co.cause = 'reellement_due' AND NOT co.soldee_par_suite
                          AND co.echeance < :arrete::date - 45",
            'dues_90' => "co.cause = 'reellement_due' AND NOT co.soldee_par_suite
                          AND co.echeance < :arrete::date - 90",
            'grands_comptes_bloques' => "co.cause = 'piece_manquante' AND NOT co.soldee_par_suite",
            'action_concession' => "co.cause = 'decision_concession' AND NOT co.soldee_par_suite",
            'relancables' => "co.cause = 'reellement_due' AND NOT co.soldee_par_suite
                              AND co.echeance < :arrete::date",
            default => '1 = 0',
        };
        $perimetre = $etab ? ' AND co.etablissement_id = :etab' : '';

        if ($volume) {
            return ['SELECT count(*) lignes, coalesce(sum(co.montant),0) montant, count(g.type) traites
                       FROM pilotage.cause_ouverture co
                       '.sprintf($geste, "'facture'", 'co.facture_id')."
                      WHERE $clause$perimetre", $args];
        }

        return ['SELECT co.facture_id, co.client_id, co.etablissement_id, co.montant,
                        co.date_facture, co.echeance, co.cause, co.suite,
                        (:arrete::date - co.echeance) AS retard,
                        f.numero, f.type AS type_facture,
                        c.nom AS client_nom, c.type AS client_type,
                        e.nom AS etablissement_nom,
                        -- Le pont vers le septieme outil. Additif : une jointure
                        -- de plus, aucune colonne existante touchee, aucun total
                        -- change.
                        lien.dossier_id, lien.qualite AS lien_qualite,
                        g.type AS geste, g.niveau AS geste_niveau, g.note AS geste_note,
                        g.fait_le AS geste_le, g.auteur AS geste_par
                   FROM pilotage.cause_ouverture co
                   LEFT JOIN grands_comptes.lien_creance_dossier lien
                          ON lien.facture_id = co.facture_id
                   LEFT JOIN affectation.facture f ON f.id = co.facture_id
                   LEFT JOIN affectation.client c ON c.id = co.client_id
                   LEFT JOIN affectation.etablissement e ON e.id = co.etablissement_id
                   '.sprintf($geste, "'facture'", 'co.facture_id')."
                  WHERE $clause$perimetre
                  ORDER BY co.montant DESC", $args];
    }
}
