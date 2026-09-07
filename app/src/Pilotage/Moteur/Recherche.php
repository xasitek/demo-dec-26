<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

use Doctrine\DBAL\Connection;

/**
 * La recherche transverse du poste de travail.
 *
 * Devant un jury, on tape un numero de facture et on doit tomber sur le
 * dossier. Pas sur une page de resultats a filtrer, pas sur un ecran qui
 * demande d'abord de choisir une file : le dossier.
 *
 * La recherche couvre les huit entrees par lesquelles un comptable reconnait
 * un dossier : le client, son code, la facture, le montant, le VIN,
 * l'immatriculation, le compte general, l'etablissement. Chacune interroge
 * l'objet ou elle vit reellement -- l'immatriculation est portee par le
 * vehicule et par l'ecriture, pas par la facture -- et le resultat dit
 * toujours de quelle NATURE il est, parce que le geste possible en depend.
 */
final class Recherche
{
    /** Ce que la recherche sait reconnaitre, dit a l'utilisateur. */
    public const CHAMPS = [
        'un client' => 'son nom, même partiel',
        'un code client' => 'CLI-0012029, ou le code de la balance',
        'une facture' => 'FAC-0055461, ou son numéro de pièce',
        'un montant' => '67899.40 — au centime, débit ou crédit',
        'un VIN' => 'complet, ou ses huit derniers caractères',
        'une immatriculation' => 'AB-123-CD',
        'un compte' => '4111000, 4114000, 4116000',
        'un établissement' => 'ETB-003, ou son nom',
        'un virement' => 'TX-003078, ou le libellé bancaire',
        'un lot de lettrage' => 'LOT-000412',
    ];

    /** Combien de lignes par nature. Au-dela, on affine sa recherche. */
    private const PAR_NATURE = 12;

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * L'identifiant vers lequel sauter directement, s'il n'y a aucun doute.
     *
     * Un terme qui EST un identifiant ne merite pas une page de resultats :
     * il merite le dossier. C'est ce qui permet de taper un numero de facture
     * devant le jury et d'ouvrir la creance dans la seconde.
     *
     * @return array{type: string, id: string}|null
     */
    public function saut(string $terme): ?array
    {
        $t = strtoupper(trim($terme));
        if ('' === $t) {
            return null;
        }

        $formes = [
            'facture' => ['/^FAC-?0*(\d+)$/', 'FAC-%07d', 'SELECT 1 FROM affectation.facture WHERE id = ?'],
            'virement' => ['/^TX-?0*(\d+)$/', 'TX-%06d', 'SELECT 1 FROM affectation.virement WHERE id = ?'],
            'lot' => ['/^LOT-?0*(\d+)$/', 'LOT-%06d', 'SELECT 1 FROM lettrage.lot WHERE id = ?'],
            'compte' => ['/^CLI-?0*(\d+)$/', 'CLI-%07d', 'SELECT 1 FROM affectation.client WHERE id = ?'],
            'ecriture' => ['/^EC-?0*(\d+)$/', 'EC-%07d', 'SELECT 1 FROM lettrage.ecriture WHERE id = ?'],
        ];

        foreach ($formes as $type => [$motif, $gabarit, $verif]) {
            if (1 === preg_match($motif, $t, $m)) {
                // On essaie la forme normalisee, puis le terme tel quel : les
                // identifiants du monde synthetique sont zero-padded, mais
                // personne ne tape les zeros.
                foreach ([sprintf($gabarit, (int) $m[1]), $t] as $candidat) {
                    if (null !== $this->cnx->fetchOne($verif, [$candidat])
                        && false !== $this->cnx->fetchOne($verif, [$candidat])) {
                        return ['type' => $type, 'id' => $candidat];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Les resultats, groupes par nature.
     *
     * @param array<string, string> $filtres
     *
     * @return array{terme: string, montant: ?float, total: int,
     *               groupes: list<array{nature: string, libelle: string, lignes: list<array<string, mixed>>}>}
     */
    public function chercher(string $terme, array $filtres = []): array
    {
        $terme = trim($terme);
        $vide = ['terme' => $terme, 'montant' => null, 'total' => 0, 'groupes' => []];
        if (mb_strlen($terme) < 2) {
            return $vide;
        }

        $comme = '%'.$terme.'%';
        $montant = $this->montantDe($terme);
        $etab = $filtres['etablissement_id'] ?? null;
        $n = self::PAR_NATURE;

        $groupes = [];

        // ---------------------------------------------------------- creances
        $args = ['q' => $comme, 'arrete' => Conventions::ARRETE];
        $ou = ['f.id ILIKE :q', 'f.numero ILIKE :q', 'c.nom ILIKE :q', 'c.id ILIKE :q',
            'c.code_balance ILIKE :q', 'v.immatriculation ILIKE :q', 'v.serie ILIKE :q',
            'v.serie8 ILIKE :q', 'f.etablissement_id ILIKE :q', 'e.nom ILIKE :q'];
        if (null !== $montant) {
            $ou[] = 'f.montant = :m';
            $args['m'] = $montant;
        }
        if (null !== $etab) {
            $args['etab'] = $etab;
        }
        $groupes[] = $this->groupe('facture', 'Créances', '
            SELECT f.id, f.numero, f.montant, f.echeance, f.statut, f.type,
                   (:arrete::date - f.echeance) AS retard,
                   f.client_id, c.nom AS client_nom, f.etablissement_id, e.nom AS etablissement_nom,
                   v.immatriculation, v.serie8, co.cause, co.soldee_par_suite, co.soldee_par
              FROM affectation.facture f
              LEFT JOIN affectation.client c ON c.id = f.client_id
              LEFT JOIN affectation.vehicule v ON v.id = f.vehicule_id
              LEFT JOIN affectation.etablissement e ON e.id = f.etablissement_id
              LEFT JOIN pilotage.cause_ouverture co ON co.facture_id = f.id
             WHERE ('.implode(' OR ', $ou).')'
            .(null !== $etab ? ' AND f.etablissement_id = :etab' : '')."
             ORDER BY f.montant DESC LIMIT $n", $args);

        // --------------------------------------------------------- virements
        $args = ['q' => $comme];
        $ou = ['w.id ILIKE :q', 'w.libelle ILIKE :q', 'w.nom_donneur_ordre ILIKE :q',
            'w.reference_bout_en_bout ILIKE :q', 'd.client_propose ILIKE :q', 'c.nom ILIKE :q'];
        if (null !== $montant) {
            $ou[] = 'w.montant = :m';
            $args['m'] = $montant;
        }
        $groupes[] = $this->groupe('virement', 'Règlements reçus', "
            SELECT w.id, w.date_operation, w.montant, w.libelle, w.nom_donneur_ordre,
                   d.decision, d.score, d.client_propose, c.nom AS client_nom
              FROM affectation.virement w
              LEFT JOIN affectation.decision d ON d.virement_id = w.id AND d.mode = 'ENRICHED'
              LEFT JOIN affectation.client c ON c.id = d.client_propose
             WHERE (".implode(' OR ', $ou).")
             ORDER BY w.montant DESC LIMIT $n", $args);

        // --------------------------------------------------------- ecritures
        $args = ['q' => $comme];
        $ou = ['x.id ILIKE :q', 'x.reference_piece ILIKE :q', 'x.immatriculation ILIKE :q',
            'x.vin ILIKE :q', 'x.vin8 ILIKE :q', 'x.compte ILIKE :q', 'x.client_id ILIKE :q',
            'c.nom ILIKE :q', 'x.lot_demo ILIKE :q', 'x.etablissement_id ILIKE :q'];
        if (null !== $montant) {
            $ou[] = 'x.montant = :m';
            $args['m'] = $montant;
        }
        if (null !== $etab) {
            $args['etab'] = $etab;
        }
        $groupes[] = $this->groupe('ecriture', 'Écritures du compte client', '
            SELECT x.id, x.date_ecriture, x.compte, x.journal, x.sens, x.montant,
                   x.reference_piece, x.immatriculation, x.vin8, x.lettrage, x.lot_demo,
                   x.client_id, c.nom AS client_nom, x.etablissement_id
              FROM lettrage.ecriture x
              LEFT JOIN affectation.client c ON c.id = x.client_id
             WHERE ('.implode(' OR ', $ou).')'
            .(null !== $etab ? ' AND x.etablissement_id = :etab' : '')."
             ORDER BY x.montant DESC LIMIT $n", $args);

        // -------------------------------------------------------------- lots
        $args = ['q' => $comme];
        $ou = ['l.id ILIKE :q', 'l.client_id ILIKE :q', 'c.nom ILIKE :q', 'l.ancre_id ILIKE :q'];
        if (null !== $montant) {
            $ou[] = 'l.montant = :m';
            $args['m'] = $montant;
        }
        $groupes[] = $this->groupe('lot', 'Lots de lettrage', '
            SELECT l.id, l.montant, l.nb_ecritures, l.client_id, c.nom AS client_nom,
                   l.etablissement_id, d.verdict, d.methode, d.solde
              FROM lettrage.lot l
              LEFT JOIN lettrage.decision d ON d.lot_id = l.id
              LEFT JOIN affectation.client c ON c.id = l.client_id
             WHERE ('.implode(' OR ', $ou).")
             ORDER BY l.montant DESC LIMIT $n", $args);

        // ----------------------------------------------------------- comptes
        $groupes[] = $this->groupe('compte', 'Comptes clients', "
            SELECT c.id, c.nom, c.type, c.code_balance, c.siren,
                   (SELECT count(*) FROM pilotage.cause_ouverture o WHERE o.client_id = c.id) creances,
                   (SELECT coalesce(sum(o.montant), 0) FROM pilotage.cause_ouverture o
                     WHERE o.client_id = c.id AND NOT o.soldee_par_suite) ouvert
              FROM affectation.client c
             WHERE c.id ILIKE :q OR c.nom ILIKE :q OR c.code_balance ILIKE :q OR c.siren ILIKE :q
             ORDER BY 7 DESC LIMIT $n", ['q' => $comme]);

        // ---------------------------------------------------- etablissements
        $groupes[] = $this->groupe('etablissement', 'Établissements', "
            SELECT e.id, e.nom, e.societe_id,
                   (SELECT count(*) FROM pilotage.cause_ouverture o
                     WHERE o.etablissement_id = e.id AND NOT o.soldee_par_suite) creances,
                   (SELECT coalesce(sum(o.montant), 0) FROM pilotage.cause_ouverture o
                     WHERE o.etablissement_id = e.id AND NOT o.soldee_par_suite) ouvert
              FROM affectation.etablissement e
             WHERE e.id ILIKE :q OR e.nom ILIKE :q OR e.societe_id ILIKE :q
             ORDER BY 5 DESC LIMIT $n", ['q' => $comme]);

        $groupes = array_values(array_filter($groupes, static fn (array $g): bool => [] !== $g['lignes']));
        $total = 0;
        foreach ($groupes as $g) {
            $total += \count($g['lignes']);
        }

        return ['terme' => $terme, 'montant' => $montant, 'total' => $total, 'groupes' => $groupes];
    }

    /**
     * Un groupe de resultats.
     *
     * @param array<string, mixed> $args
     *
     * @return array{nature: string, libelle: string, lignes: list<array<string, mixed>>}
     */
    private function groupe(string $nature, string $libelle, string $sql, array $args): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative($sql, $args);

        return ['nature' => $nature, 'libelle' => $libelle, 'lignes' => $lignes];
    }

    /**
     * Le terme est-il un montant ?
     *
     * Un comptable tape « 67899,40 » avec la virgule francaise, ou
     * « 67 899.40 » avec l'espace du copier-coller. Les deux doivent marcher,
     * et un identifiant comme 4111000 doit rester lisible comme un compte
     * ET comme un montant : on ne choisit pas a la place de l'utilisateur.
     */
    private function montantDe(string $terme): ?float
    {
        $t = str_replace([' ', "\u{a0}", "\u{202f}"], '', $terme);
        $t = str_replace(',', '.', $t);
        if (1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $t)) {
            return null;
        }

        return (float) $t;
    }
}
