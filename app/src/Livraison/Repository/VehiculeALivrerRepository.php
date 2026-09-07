<?php

declare(strict_types=1);

namespace App\Livraison\Repository;

use Doctrine\DBAL\Connection;

/**
 * Lecture de la file de travail Livraison depuis `livraison.v_a_livrer`.
 *
 * La vue expose des alias propres : le code appelant n'ecrit jamais de
 * `donnees->>'...'`, meme convention que `creances.v_creances_ouvertes`.
 *
 * Aucune donnee n'est stockee ici : la vue derive integralement de
 * `mirror.bal_eloficash`. Voir docs/MODULE_LIVRAISON.md section 3.
 *
 * @phpstan-type LigneALivrer array{
 *     cle_ecriture: string,
 *     code_payeur: string,
 *     loueur: string,
 *     code_facture: ?string,
 *     immatriculation: ?string,
 *     identifiant_vehicule: string,
 *     vin: ?string,
 *     num_or: ?string,
 *     ref_icar: ?string,
 *     code_etab: ?string,
 *     code_entite: ?string,
 *     libelle: ?string,
 *     reference: ?string,
 *     num_piece: ?string,
 *     date_piece: ?string,
 *     date_echeance: ?string,
 *     solde: ?string,
 *     url_scan: ?string,
 *     vu_le: ?string
 * }
 */
final class VehiculeALivrerRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Toutes les ecritures de la file de travail.
     *
     * @return list<LigneALivrer>
     */
    public function toutes(): array
    {
        /** @var list<LigneALivrer> $lignes */
        $lignes = $this->connection
            ->executeQuery('SELECT * FROM livraison.v_a_livrer ORDER BY identifiant_vehicule, date_piece')
            ->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Les vehicules a livrer d'un etablissement, un par identifiant : c'est l'unite
     * que la secretaire declare, alors que la vue rend une ligne par facture.
     *
     * Les factures sont agregees en tableau, sans plafond — le tableur s'arretait a
     * six et tronquait 37 vehicules (cf. docs/MODULE_LIVRAISON.md section 2.5).
     *
     * @return list<array{
     *     identifiant_vehicule: string,
     *     immatriculation: ?string,
     *     vin: ?string,
     *     loueur: string,
     *     code_payeur: string,
     *     code_etab: string,
     *     nb_factures: int,
     *     solde_total: ?string,
     *     derniere_facture: ?string
     * }>
     */
    public function parEtablissement(string $codeEtab): array
    {
        /** @var list<array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string, nb_factures: int, solde_total: ?string, derniere_facture: ?string}> $lignes */
        $lignes = $this->connection->executeQuery(
            'SELECT identifiant_vehicule,
                    max(immatriculation)            AS immatriculation,
                    max(vin)                        AS vin,
                    min(loueur)                     AS loueur,
                    min(code_payeur)                AS code_payeur,
                    min(code_etab)                  AS code_etab,
                    count(*)                        AS nb_factures,
                    sum(coalesce(solde, 0))         AS solde_total,
                    max(date_piece)                 AS derniere_facture
             FROM livraison.v_a_livrer
             WHERE code_etab = :etab
             GROUP BY identifiant_vehicule
             ORDER BY max(date_piece) DESC NULLS LAST, identifiant_vehicule',
            ['etab' => $codeEtab]
        )->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Les factures d'un vehicule, pour l'ecran de controle. Aucun plafond : le
     * tableur s'arretait a six et tronquait 37 vehicules, dont deux qui en portent
     * seize (cf. docs/MODULE_LIVRAISON.md section 2.5).
     *
     * @return list<array{
     *     cle_ecriture: string,
     *     num_piece: ?string,
     *     reference: ?string,
     *     libelle: ?string,
     *     date_piece: ?string,
     *     date_echeance: ?string,
     *     solde: ?string,
     *     url_scan: ?string
     * }>
     */
    public function facturesDuVehicule(string $identifiant): array
    {
        /** @var list<array{cle_ecriture: string, num_piece: ?string, reference: ?string, libelle: ?string, date_piece: ?string, date_echeance: ?string, solde: ?string, url_scan: ?string}> $lignes */
        $lignes = $this->connection->executeQuery(
            'SELECT cle_ecriture, num_piece, reference, libelle,
                    date_piece, date_echeance, solde, url_scan
             FROM livraison.v_a_livrer
             WHERE identifiant_vehicule = :id
             ORDER BY date_piece, cle_ecriture',
            ['id' => $identifiant]
        )->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Un vehicule precis, pour revalider la declaration cote serveur : ce que le
     * navigateur envoie n'est jamais une source de verite.
     *
     * @return array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string}|null
     */
    public function vehicule(string $codeEtab, string $identifiant): ?array
    {
        /** @var array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string}|false $ligne */
        $ligne = $this->connection->executeQuery(
            'SELECT identifiant_vehicule,
                    max(immatriculation) AS immatriculation,
                    max(vin)             AS vin,
                    min(loueur)          AS loueur,
                    min(code_payeur)     AS code_payeur,
                    min(code_etab)       AS code_etab
             FROM livraison.v_a_livrer
             WHERE code_etab = :etab AND identifiant_vehicule = :id
             GROUP BY identifiant_vehicule',
            ['etab' => $codeEtab, 'id' => $identifiant]
        )->fetchAssociative();

        return false === $ligne ? null : $ligne;
    }

    /**
     * Les cles d'ecriture presentes dans la vue, indexees pour comparaison rapide.
     *
     * @return array<string, true>
     */
    public function clesEcriture(): array
    {
        /** @var list<string> $cles */
        $cles = $this->connection
            ->executeQuery('SELECT cle_ecriture FROM livraison.v_a_livrer')
            ->fetchFirstColumn();

        return array_fill_keys($cles, true);
    }

    /**
     * Nombre d'immatriculations distinctes — c'est l'unite de comparaison avec
     * le Sheet, une immatriculation pouvant porter plusieurs factures.
     */
    public function nombreImmatriculations(): int
    {
        return (int) $this->connection
            ->executeQuery('SELECT count(DISTINCT identifiant_vehicule) FROM livraison.v_a_livrer')
            ->fetchOne();
    }

    /**
     * Repartition par loueur, pour le controle de parite et le pilotage.
     *
     * @return list<array{loueur: string, immats: int, factures: int}>
     */
    public function repartitionParLoueur(): array
    {
        /** @var list<array{loueur: string, immats: int, factures: int}> $lignes */
        $lignes = $this->connection->executeQuery(
            'SELECT loueur,
                    count(DISTINCT identifiant_vehicule) AS immats,
                    count(*)                        AS factures
             FROM livraison.v_a_livrer
             GROUP BY loueur
             ORDER BY immats DESC'
        )->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Nombre de factures par immatriculation, pour verifier que le module ne
     * reproduit pas le plafond de six du tableur (37 immatriculations le
     * depassent, cf. docs/MODULE_LIVRAISON.md section 2.5).
     *
     * @return list<array{identifiant_vehicule: string, factures: int}>
     */
    public function immatriculationsAuDelaDe(int $seuil): array
    {
        /** @var list<array{identifiant_vehicule: string, factures: int}> $lignes */
        $lignes = $this->connection->executeQuery(
            'SELECT identifiant_vehicule, count(*) AS factures
             FROM livraison.v_a_livrer
             GROUP BY identifiant_vehicule
             HAVING count(*) > :seuil
             ORDER BY factures DESC',
            ['seuil' => $seuil]
        )->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Fragment commun aux requetes par concession : la vue, enrichie de sa concession
     * et de l'etat de declaration du vehicule.
     *
     * Jointures EXTERNES par necessite : deux etablissements de la vue sont absents du
     * referentiel (111 et 364 au 2026-09-02), et un vehicule non declare n'a pas de
     * ligne dans `livraison.declaration`. `livraison.declaration` porte une contrainte
     * d'unicite sur `identifiant_vehicule`, donc la jointure n'y multiplie aucune ligne
     * et `count(*)` compte toujours des factures.
     *
     *
     * `solde_vehicule` totalise TOUTES les factures du vehicule. Un solde nul signifie
     * que le loueur a paye : le dossier n'a donc plus a partir, la raison d'etre du
     * module etant precisement d'obtenir ce paiement. Ces vehicules sortent de la file
     * — 20 des 182 restants au 2026-09-02, et c'est ce qui explique les vehicules a
     * 0,00 EUR que le circuit Google ne proposait pas. Les soldes NEGATIFS, eux, sont
     * conserves : un trop-paye est une anomalie qui doit rester visible.
     */
    private const BASE_CONCESSION = <<<'SQL'
        SELECT v.*,
               COALESCE(NULLIF(e.code_societe, ''), v.code_etab) AS code_soc,
               (d.identifiant_vehicule IS NOT NULL)              AS deja,
               sum(coalesce(v.solde, 0))
                 OVER (PARTITION BY v.identifiant_vehicule)      AS solde_vehicule
        FROM livraison.v_a_livrer v
        LEFT JOIN shared.etablissement e ON e.code_etab = v.code_etab
        LEFT JOIN livraison.declaration d ON d.identifiant_vehicule = v.identifiant_vehicule
        SQL;

    /**
     * Les loueurs d'une concession, avec ce qu'il reste a declarer chez chacun.
     *
     * C'est le deuxieme niveau du formulaire Google : sa liste deroulante
     * « Choisissez un client : » portait le libelle `OVERLEASE (24 non livres)`.
     * Un loueur sans reste apparait quand meme, signale « complet » comme dans le
     * formulaire Google : la secretaire doit pouvoir le constater, plutot que de
     * chercher un menu disparu. Tri alphabetique, comme le formulaire remplace.
     *
     * @return list<array{loueur: string, code_payeur: string, total: int, restants: int}>
     */
    public function loueursDeLaConcession(string $codeSociete): array
    {
        /** @var list<array{loueur: string, code_payeur: string, total: int|string, restants: int|string}> $lignes */
        $lignes = $this->connection->executeQuery(
            'WITH base AS ('.self::BASE_CONCESSION.')
             SELECT loueur,
                    code_payeur,
                    count(DISTINCT identifiant_vehicule)
                      FILTER (WHERE solde_vehicule <> 0)                        AS total,
                    count(DISTINCT identifiant_vehicule)
                      FILTER (WHERE solde_vehicule <> 0 AND NOT deja)           AS restants
             FROM base
             WHERE code_soc = :soc
             GROUP BY loueur, code_payeur
             -- Tri : ceux qui attendent un dossier en tete, puis par ordre
             -- alphabetique. La secretaire doit voir son travail, pas le parcourir.
             ORDER BY (count(DISTINCT identifiant_vehicule)
                         FILTER (WHERE solde_vehicule <> 0 AND NOT deja) = 0), loueur',
            ['soc' => $codeSociete]
        )->fetchAllAssociative();

        return array_map(static fn (array $l): array => [
            'loueur' => (string) $l['loueur'],
            'code_payeur' => (string) $l['code_payeur'],
            'total' => (int) $l['total'],
            'restants' => (int) $l['restants'],
        ], $lignes);
    }

    /**
     * Les vehicules d'une concession CHEZ UN SEUL LOUEUR, un par identifiant.
     *
     * Le loueur est dans la clause WHERE et non dans un filtre d'affichage : un depot
     * ne vaut que pour un loueur, puisque les trois pieces jointes sont les memes pour
     * tous les vehicules coches. Un PV de livraison ne peut pas partir a la fois chez
     * deux loueurs distincts.
     *
     * @return list<array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string, nb_factures: int, solde_total: ?string, derniere_facture: ?string, deja_declare: int}>
     */
    public function parConcessionEtLoueur(string $codeSociete, string $codePayeur): array
    {
        /** @var list<array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string, nb_factures: int, solde_total: ?string, derniere_facture: ?string, deja_declare: int}> $lignes */
        $lignes = $this->connection->executeQuery(
            'WITH base AS ('.self::BASE_CONCESSION.')
             SELECT identifiant_vehicule,
                    max(immatriculation)    AS immatriculation,
                    max(vin)                AS vin,
                    min(loueur)             AS loueur,
                    min(code_payeur)        AS code_payeur,
                    min(code_etab)          AS code_etab,
                    count(*)                AS nb_factures,
                    sum(coalesce(solde, 0)) AS solde_total,
                    max(date_piece)         AS derniere_facture,
                    bool_or(deja)::int      AS deja_declare
             FROM base
             WHERE code_soc = :soc AND code_payeur = :payeur AND solde_vehicule <> 0
             GROUP BY identifiant_vehicule
             ORDER BY max(date_piece) DESC NULLS LAST, identifiant_vehicule',
            ['soc' => $codeSociete, 'payeur' => $codePayeur]
        )->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Un vehicule precis, revalide dans SA concession et CHEZ SON loueur.
     *
     * Ce que le navigateur envoie n'est jamais une source de verite : le couple
     * (concession, loueur) est refait cote serveur, ce qui rend la regle « un depot,
     * un loueur » impossible a contourner en bricolant le formulaire.
     *
     * @return array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string, deja_declare: int}|null
     */
    public function vehiculeDansConcession(string $codeSociete, string $codePayeur, string $identifiant): ?array
    {
        /** @var array{identifiant_vehicule: string, immatriculation: ?string, vin: ?string, loueur: string, code_payeur: string, code_etab: string, deja_declare: int}|false $ligne */
        $ligne = $this->connection->executeQuery(
            'WITH base AS ('.self::BASE_CONCESSION.')
             SELECT identifiant_vehicule,
                    max(immatriculation) AS immatriculation,
                    max(vin)             AS vin,
                    min(loueur)          AS loueur,
                    min(code_payeur)     AS code_payeur,
                    min(code_etab)       AS code_etab,
                    bool_or(deja)::int   AS deja_declare
             FROM base
             WHERE code_soc = :soc AND code_payeur = :payeur AND identifiant_vehicule = :id
               AND solde_vehicule <> 0
             GROUP BY identifiant_vehicule',
            ['soc' => $codeSociete, 'payeur' => $codePayeur, 'id' => $identifiant]
        )->fetchAssociative();

        return false === $ligne ? null : $ligne;
    }
}
