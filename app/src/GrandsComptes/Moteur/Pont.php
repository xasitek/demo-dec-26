<?php

declare(strict_types=1);

namespace App\GrandsComptes\Moteur;

use Doctrine\DBAL\Connection;

/**
 * Le pont entre l'outil 6 et l'outil 7.
 *
 * Il repond a deux questions, dans les deux sens :
 *
 *   depuis l'outil 6 : « cette creance bloquee par une piece, quel dossier
 *   documentaire la suit ? » ;
 *   depuis l'outil 7 : « ce dossier, quelle creance debloque-t-il ? »
 *
 * Et il porte la COUCHE DE SESSION. Quand un dossier est valide dans l'outil 7,
 * la creance liee n'est plus bloquee par une piece -- mais le monde fige, lui,
 * ne bouge pas d'une ligne : la resolution appartient a la session de
 * demonstration. Les huit reconciliations de l'outil 6 portent sur l'arrete et
 * restent inchangees ; la couche de session s'affiche A COTE, jamais dedans.
 *
 * Cette distinction n'est pas une precaution technique, c'est la meme doctrine
 * que partout : on ne melange pas une position arretee et un mouvement de
 * demonstration.
 *
 * ET UNE REGLE D'ARCHITECTURE, sans exception : ce module ne lit JAMAIS le
 * schema `grands_comptes_verite`. Les relations declarees de l'univers vivent
 * dans le MONDE, table `grands_comptes.lien_declare_demo`, avec leur nature,
 * leur niveau de preuve, leur provenance et la date de creation de l'univers.
 * La verite en garde une copie, mais comme reponse attendue des controles :
 * elle sert a mesurer, jamais a naviguer.
 */
final class Pont
{
    /**
     * Ce que chaque niveau de preuve affirme, et ce qu'il n'affirme pas.
     *
     * @var array<string, array{libelle: string, nature: string, teinte: string, explication: string}>
     */
    public const QUALITES = [
        'blocage_declare' => [
            'libelle' => 'Dossier correspondant',
            'nature' => 'relation déclarée',
            'teinte' => 'positive',
            'explication' => "La relation est DÉCLARÉE dans la vérité de l'univers de "
                .'démonstration : le loueur retient le paiement de cette facture tant que ce '
                .'dossier documentaire est incomplet. Même payeur, même établissement, dossier '
                ."réellement incomplet. Elle n'est pas inférée : elle est posée, et rien du monde "
                .'ne la contredit — le dossier garde sa propre facture.',
        ],
        'facture' => [
            'libelle' => 'Dossier correspondant — même facture',
            'nature' => 'identité',
            'teinte' => 'positive',
            'explication' => 'La créance et le dossier portent la même facture. '
                ."C'est une identité portée par le monde, pas un rapprochement.",
        ],
        'vehicule' => [
            'libelle' => 'Dossier correspondant — même véhicule et même site',
            'nature' => 'identité',
            'teinte' => 'positive',
            'explication' => 'Le même véhicule, livré et facturé par le même établissement. '
                .'Identité portée par le monde.',
        ],
        'payeur_site' => [
            'libelle' => 'Dossier associé — même loueur, même établissement',
            'nature' => 'association',
            'teinte' => 'navy',
            'explication' => 'Le même payeur et le même site, et un dossier lui-même incomplet. '
                ."C'est un appariement de démonstration : il est cohérent, il n'est pas prouvé.",
        ],
        'payeur_societe' => [
            'libelle' => 'Dossier associé — même loueur, même société',
            'nature' => 'association',
            'teinte' => 'gold',
            'explication' => 'Le même payeur et la même société, et un dossier lui-même incomplet. '
                .'Appariement de démonstration, plus large que le précédent.',
        ],
    ];

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Le sous-total que l'outil 6 affiche sous « bloquée par une pièce manquante ».
     *
     * @param array<string, string> $filtres
     *
     * @return array{creances: int, montant: float, resolus: int, montant_resolu: float,
     *               par_qualite: list<array<string, mixed>>, total_cause: int, montant_cause: float}
     */
    public function sousTotal(array $filtres = []): array
    {
        $ou = '';
        $args = [];
        if (isset($filtres['etablissement_id']) && '' !== $filtres['etablissement_id']) {
            $ou = ' AND l.etablissement_id = :etab';
            $args['etab'] = $filtres['etablissement_id'];
        }

        $ligne = $this->cnx->fetchAssociative(
            'SELECT count(*) creances, coalesce(sum(l.montant_creance), 0) montant,
                    count(*) FILTER (WHERE v.dossier_id IS NOT NULL) resolus,
                    coalesce(sum(l.montant_creance) FILTER (WHERE v.dossier_id IS NOT NULL), 0) montant_resolu
               FROM grands_comptes.lien_creance_dossier l
               LEFT JOIN ('.$this->sqlValides().') v ON v.dossier_id = l.dossier_id
              WHERE 1 = 1'.$ou, $args) ?: [];

        /** @var list<array<string, mixed>> $parQualite */
        $parQualite = $this->cnx->fetchAllAssociative(
            'SELECT l.qualite, count(*) n, coalesce(sum(l.montant_creance), 0) montant,
                    count(*) FILTER (WHERE v.dossier_id IS NOT NULL) resolus
               FROM grands_comptes.lien_creance_dossier l
               LEFT JOIN ('.$this->sqlValides().') v ON v.dossier_id = l.dossier_id
              WHERE 1 = 1'.$ou.'
              GROUP BY 1 ORDER BY 2 DESC', $args);

        // Le total de la cause, cote outil 6, pour que le sous-total se lise
        // comme une part et pas comme un chiffre isole.
        $ouCause = '';
        $argsCause = [];
        if (isset($filtres['etablissement_id']) && '' !== $filtres['etablissement_id']) {
            $ouCause = ' AND o.etablissement_id = :etab';
            $argsCause['etab'] = $filtres['etablissement_id'];
        }
        $cause = $this->cnx->fetchAssociative(
            "SELECT count(*) n, coalesce(sum(o.montant), 0) montant
               FROM pilotage.cause_ouverture o
              WHERE o.cause = 'piece_manquante' AND NOT o.soldee_par_suite".$ouCause, $argsCause) ?: [];

        return [
            'creances' => (int) ($ligne['creances'] ?? 0),
            'montant' => (float) ($ligne['montant'] ?? 0),
            'resolus' => (int) ($ligne['resolus'] ?? 0),
            'montant_resolu' => (float) ($ligne['montant_resolu'] ?? 0),
            'par_qualite' => $parQualite,
            'total_cause' => (int) ($cause['n'] ?? 0),
            'montant_cause' => (float) ($cause['montant'] ?? 0),
        ];
    }

    /**
     * Les creances liees, avec leur dossier et son etat courant.
     *
     * @param array<string, string> $filtres
     *
     * @return list<array<string, mixed>>
     */
    public function creancesLiees(array $filtres = [], int $limite = 60, int $decalage = 0): array
    {
        $ou = '';
        $args = [];
        if (isset($filtres['etablissement_id']) && '' !== $filtres['etablissement_id']) {
            $ou .= ' AND l.etablissement_id = :etab';
            $args['etab'] = $filtres['etablissement_id'];
        }
        if (isset($filtres['qualite']) && '' !== $filtres['qualite']) {
            $ou .= ' AND l.qualite = :q';
            $args['q'] = $filtres['qualite'];
        }
        if (isset($filtres['resolus']) && 'oui' === $filtres['resolus']) {
            $ou .= ' AND v.dossier_id IS NOT NULL';
        }
        if (isset($filtres['resolus']) && 'non' === $filtres['resolus']) {
            $ou .= ' AND v.dossier_id IS NULL';
        }

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT l.*, d.immatriculation, d.modele, d.energie, d.montant_facture,
                    d.date_livraison, d.code_scenario,
                    e.nom AS etablissement_nom,
                    c.nom AS client_nom,
                    v.dossier_id IS NOT NULL AS resolu_session,
                    v.fait_le AS resolu_le, v.auteur AS resolu_par,
                    (SELECT count(*) FROM grands_comptes.piece p
                      WHERE p.dossier_id = l.dossier_id AND NOT p.presente
                        AND p.exigence IN (\'obligatoire\',\'si_electrique\',\'si_premier_reglt\')) manquantes,
                    ct.verdict, ct.nb_anomalies,
                    dd.provenance, dd.univers_cree_le
               FROM grands_comptes.lien_creance_dossier l
               JOIN grands_comptes.dossier d ON d.id = l.dossier_id
               LEFT JOIN grands_comptes.lien_declare_demo dd ON dd.facture_id = l.facture_id
               LEFT JOIN affectation.etablissement e ON e.id = l.etablissement_id
               LEFT JOIN affectation.client c ON c.id = l.client_id
               LEFT JOIN grands_comptes.controle ct ON ct.dossier_id = l.dossier_id
               LEFT JOIN ('.$this->sqlValides().') v ON v.dossier_id = l.dossier_id
              WHERE 1 = 1'.$ou.'
              ORDER BY l.montant_creance DESC
              LIMIT '.max(1, min($limite, 200)).' OFFSET '.max(0, $decalage), $args);

        return $lignes;
    }

    /**
     * Le lien d'un dossier, vu depuis l'outil 7 : quelle creance debloque-t-il ?
     *
     * @return array<string, mixed>|null
     */
    public function creanceDuDossier(string $dossierId): ?array
    {
        $l = $this->cnx->fetchAssociative(
            'SELECT l.*, c.nom AS client_nom, e.nom AS etablissement_nom,
                    o.cause, o.echeance, o.montant AS montant_cause, o.soldee_par_suite,
                    dd.nature_lien, dd.niveau_preuve, dd.provenance, dd.univers_cree_le,
                    v.dossier_id IS NOT NULL AS resolu_session, v.fait_le AS resolu_le
               FROM grands_comptes.lien_creance_dossier l
               LEFT JOIN affectation.client c ON c.id = l.client_id
               LEFT JOIN affectation.etablissement e ON e.id = l.etablissement_id
               LEFT JOIN pilotage.cause_ouverture o ON o.facture_id = l.facture_id
               LEFT JOIN grands_comptes.lien_declare_demo dd ON dd.facture_id = l.facture_id
               LEFT JOIN ('.$this->sqlValides().') v ON v.dossier_id = l.dossier_id
              WHERE l.dossier_id = ?', [$dossierId]);

        return false === $l ? null : $l;
    }

    /** Le dossier d'une creance, s'il existe. */
    public function dossierDeLaCreance(string $factureId): ?string
    {
        $id = $this->cnx->fetchOne(
            'SELECT dossier_id FROM grands_comptes.lien_creance_dossier WHERE facture_id = ?',
            [$factureId]);

        return \is_string($id) ? $id : null;
    }

    /**
     * Les dossiers valides dans la session, c'est-a-dire resolus.
     *
     * L'etat n'est pas stocke en double : un dossier est resolu SI un acte de
     * validation a ete pose sur lui. Un drapeau recopie a cote finirait par
     * contredire l'historique.
     */
    private function sqlValides(): string
    {
        return "SELECT a.dossier_id, max(a.fait_le) fait_le, max(a.auteur) auteur
                  FROM grands_comptes.acte a
                 WHERE a.type = 'valider'
                   AND a.fait_le = (SELECT max(b.fait_le) FROM grands_comptes.acte b
                                     WHERE b.dossier_id = a.dossier_id
                                       AND b.type IN ('valider','renvoyer','instruire','certifier'))
                 GROUP BY a.dossier_id";
    }
}
