<?php

declare(strict_types=1);

namespace App\Garanties\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Vue comptable de l'audit garanties.
 *
 * Unite d'affichage : 1 ECRITURE Progiciel (compte 4116000), enrichie avec les
 * DG du portail constructeur quand on peut faire le rapprochement par chassis
 * (right(numvin, 8) = d.chassis) ou par numor (d.numero_or = b.donnees->>'numor'
 * eventuellement avec prefixe '600' cote Progiciel).
 *
 * Progiciel ne lette pas le compte 4116000 chez Synthauto (codeLettrage vide). On reconstruit
 * le rapprochement implicite via 3 heuristiques :
 *   - chassis : sum solde par chassis -> indique si le VIN est solde
 *   - numor   : sum solde par numor -> identifie les OD globales lettrables
 *   - montant : matching exact montant TTC d'une DG payee vs OD negative
 *
 * @phpstan-type Filtres array{
 *     types_piece?: ?list<string>,
 *     concessions?: ?list<string>,
 *     etablissements?: ?list<string>,
 *     marques?: ?list<string>,
 *     payeurs?: ?list<string>,
 *     statuts_dg?: ?list<string>,
 *     etats?: ?list<string>,
 *     sites?: ?list<string>,
 *     signe?: ?string,
 *     recherche?: ?string,
 *     age?: ?string,
 *     cle_ecriture?: ?string,
 *     lettrees?: ?bool
 * }
 */
final class ReconciliationRepository
{
    /**
     * Tris autorises : cle publique => expression SQL (anti-injection).
     * Les colonnes referencées existent toutes dans le SELECT externe.
     *
     * @var array<string, string>
     */
    private const TRIS = [
        'date' => 'date_piece',
        'piece' => 'no_piece',
        'type' => 'type_piece',
        'concession' => 'concession_sage',
        'etablissement' => 'etablissement_sage',
        'marque' => 'marque_sage',
        'payeur' => 'payeur_sage',
        'chassis' => 'chassis',
        'numor' => 'numor',
        'debit' => 'debit',
        'credit' => 'credit',
        'solde' => 'solde',
        'solde_chassis' => 'solde_chassis_net',
        'etat' => 'etat_rapprochement',
        'statut' => 'statut_dg_principal',
        // Colonne calculee par la jointure des commentaires (cf. sqlBase) : n'existe
        // que sur les requetes de listing / export, les seules a pouvoir trier.
        'commentaire' => 'nb_commentaires',
    ];

    /**
     * Auteur d'un commentaire, en clair. `first_name` / `last_name` sont NOT NULL en
     * base, le repli sur l'e-mail couvre le cas d'un compte pre-cree jamais complete.
     */
    private const SQL_AUTEUR = "COALESCE(NULLIF(TRIM(u.first_name || ' ' || u.last_name), ''), u.email)";

    /**
     * Une entree de commentaire telle qu'elle part dans l'export : date, auteur, texte.
     * Les retours a la ligne sont aplatis — une cellule de tableur reste lisible.
     */
    private const SQL_ENTREE_COMMENTAIRE = "to_char(n.cree_le, 'DD/MM/YYYY') || ' — ' || "
        .self::SQL_AUTEUR." || ' : ' || regexp_replace(n.texte, '\\s+', ' ', 'g')";

    /**
     * Tranches d'age (date_piece -> aujourd'hui), cle publique => [min, max].
     *
     * @var array<string, array{0: int, 1: ?int}>
     */
    private const TRANCHES_AGE = [
        'lt3m' => [0, 90],
        '3a6m' => [90, 180],
        '6a12m' => [180, 365],
        '12a24m' => [365, 730],
        'gt24m' => [730, null],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Page paginee. Retourne une liste d'écritures Progiciel enrichies.
     *
     * @param Filtres $filtres
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $page, int $parPage, array $filtres = [], string $tri = 'date', string $sens = 'desc'): array
    {
        $offset = max(0, ($page - 1) * $parPage);
        $colonneTri = self::TRIS[$tri] ?? self::TRIS['date'];
        $sensTri = 'asc' === strtolower($sens) ? 'ASC' : 'DESC';

        // Tri secondaire : par chassis puis date pour grouper visuellement.
        $sql = $this->sqlBase($filtres, true)
            .sprintf(
                ' ORDER BY %s %s NULLS LAST, chassis ASC NULLS LAST, date_piece DESC NULLS LAST, no_piece ASC NULLS LAST LIMIT %d OFFSET %d',
                $colonneTri,
                $sensTri,
                $parPage,
                $offset,
            );

        return $this->connection->fetchAllAssociative($sql, $this->params($filtres), $this->types($filtres));
    }

    /**
     * Iteration streamee pour l'export CSV (memoire constante).
     *
     * @param Filtres $filtres
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterer(array $filtres = [], string $tri = 'date', string $sens = 'desc'): iterable
    {
        $colonneTri = self::TRIS[$tri] ?? self::TRIS['date'];
        $sensTri = 'asc' === strtolower($sens) ? 'ASC' : 'DESC';
        $sql = $this->sqlBase($filtres, true)
            .sprintf(' ORDER BY %s %s NULLS LAST, chassis ASC NULLS LAST, date_piece DESC NULLS LAST', $colonneTri, $sensTri);

        return $this->connection->iterateAssociative($sql, $this->params($filtres), $this->types($filtres));
    }

    /**
     * @param Filtres $filtres
     */
    public function compter(array $filtres = []): int
    {
        $sql = 'SELECT count(*) FROM ('.$this->sqlBase($filtres).') c';

        return (int) $this->connection->fetchOne($sql, $this->params($filtres), $this->types($filtres));
    }

    /**
     * Rafraichit la vue materialisee de reconciliation. A appeler apres chaque
     * import qui modifie mirror.bal_eloficash (ETL) ou garanties.dossier (sync).
     * CONCURRENTLY : ne bloque pas les lectures (necessite l'index unique).
     */
    public function rafraichirVue(): void
    {
        // Les tris de la reconciliation tiennent en RAM (sinon debordement disque
        // sur petit work_mem). On n'eleve que pour cette connexion CLI d'import,
        // jamais pour les requetes web.
        $this->connection->executeStatement("SET work_mem = '256MB'");
        $this->connection->executeStatement('REFRESH MATERIALIZED VIEW CONCURRENTLY garanties.mv_reconciliation');
    }

    /**
     * KPI du bandeau : volumetrie + totaux comptables Progiciel + DG rapprochees.
     *
     * @param Filtres $filtres
     *
     * @return array{lignes: int, total_debit: float, total_credit: float, nb_dg: int, total_paye_ttc: float}
     */
    public function synthese(array $filtres = []): array
    {
        // Requete 1 : KPI sur les lignes Progiciel (et nb_dg = nb de matches au total,
        // qui peut compter plusieurs fois une DG si matchee par plusieurs ecritures).
        $sql = 'SELECT '
            .'count(*) AS lignes, '
            .'COALESCE(sum(debit), 0) AS total_debit, '
            .'COALESCE(sum(credit), 0) AS total_credit, '
            .'COALESCE(sum(nb_dg), 0) AS nb_dg '
            .'FROM ('.$this->sqlBase($filtres).') c';

        /** @var array{lignes: int|string, total_debit: int|string|float, total_credit: int|string|float, nb_dg: int|string}|false $row */
        $row = $this->connection->fetchAssociative($sql, $this->params($filtres), $this->types($filtres));
        if (false === $row) {
            return ['lignes' => 0, 'total_debit' => 0.0, 'total_credit' => 0.0, 'nb_dg' => 0, 'total_paye_ttc' => 0.0];
        }

        // Requete 2 : total Paye TTC sur DG DISTINCTES (chaque DG payée comptée une seule fois)
        // qui matchent au moins une ecriture Progiciel filtrée. On unnest la liste des
        // IDs DG matches dans sqlBase, on dedup, puis on somme cote dossier.
        $sqlPaye = 'SELECT COALESCE(sum(d.montant_dg), 0) * 1.20 AS total_paye_ttc '
            .'FROM garanties.dossier d '
            ."WHERE d.statut_code = '21' "
            .'  AND d.id IN ( '
            .'      SELECT DISTINCT unnest(liste_ids_dg) FROM ('.$this->sqlBase($filtres).') c '
            .'  )';

        /** @var int|string|float|false $totalPaye */
        $totalPaye = $this->connection->fetchOne($sqlPaye, $this->params($filtres), $this->types($filtres));

        return [
            'lignes' => (int) $row['lignes'],
            'total_debit' => (float) $row['total_debit'],
            'total_credit' => (float) $row['total_credit'],
            'nb_dg' => (int) $row['nb_dg'],
            'total_paye_ttc' => false === $totalPaye ? 0.0 : (float) $totalPaye,
        ];
    }

    /**
     * Statistiques agregees par marque Progiciel (BU), sur le meme socle que la page
     * d'audit : reutilise sqlBase() donc applique exactement les memes filtres.
     * Pour comparer les marques entre elles, l'appelant doit neutraliser le
     * filtre 'marques'. Zero N+1 : une seule requete groupee.
     *
     * @param array<string, mixed> $filtres
     *
     * @return list<array{marque: string, lignes: int, total_debit: float, total_credit: float, solde: float, nb_dg_matchees: int}>
     */
    public function statistiquesParMarque(array $filtres = []): array
    {
        $sql = "SELECT COALESCE(NULLIF(trim(marque_sage), ''), '(sans marque)') AS marque, "
            .'count(*) AS lignes, '
            .'COALESCE(sum(debit), 0) AS total_debit, '
            .'COALESCE(sum(credit), 0) AS total_credit, '
            .'COALESCE(sum(nb_dg), 0) AS nb_dg_matchees '
            .'FROM ('.$this->sqlBase($filtres).') c '
            .'GROUP BY 1 '
            .'ORDER BY (COALESCE(sum(debit), 0) - COALESCE(sum(credit), 0)) DESC';

        /** @var list<array{marque: string, lignes: int|string, total_debit: int|string|float, total_credit: int|string|float, nb_dg_matchees: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $this->params($filtres), $this->types($filtres));

        return array_map(static function (array $r): array {
            $debit = (float) $r['total_debit'];
            $credit = (float) $r['total_credit'];

            return [
                'marque' => (string) $r['marque'],
                'lignes' => (int) $r['lignes'],
                'total_debit' => $debit,
                'total_credit' => $credit,
                'solde' => $debit - $credit,
                'nb_dg_matchees' => (int) $r['nb_dg_matchees'],
            ];
        }, $rows);
    }

    /**
     * Couverture du scraping par emetteur DG : nombre de DG actuellement en base
     * et date du dernier scrap recu. Sert a distinguer les marques suivies par un
     * robot de celles pas encore couvertes.
     *
     * @return array<string, array{nb_dg: int, dernier_scrap: ?string}> emetteur => infos
     */
    public function couvertureMarques(): array
    {
        $sql = 'SELECT emetteur, count(*) AS nb_dg, max(derniere_maj) AS dernier_scrap '
            .'FROM garanties.dossier '
            ."WHERE emetteur IS NOT NULL AND emetteur <> '' "
            .'GROUP BY emetteur';

        /** @var list<array{emetteur: string, nb_dg: int|string, dernier_scrap: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql);

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['emetteur']] = [
                'nb_dg' => (int) $r['nb_dg'],
                'dernier_scrap' => $r['dernier_scrap'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Date de la derniere synchro ETL du mirror (max vu_le de bal_eloficash).
     */
    public function fraicheurMirror(): ?string
    {
        /** @var string|false $v */
        $v = $this->connection->fetchOne('SELECT max(vu_le) FROM mirror.bal_eloficash');

        return false === $v ? null : $v;
    }

    /**
     * Listes pour les multi-select de filtres.
     *
     * @return array{types_piece: list<string>, concessions: list<string>, etablissements: list<string>, marques: list<string>, payeurs: list<string>, statuts_dg: list<string>}
     */
    public function optionsFiltres(): array
    {
        // Les options ne bougent qu'a l'import Progiciel / scrap : TTL 1h suffit largement.
        // A invalider explicitement post-ingest si on veut une mise a jour immediate.
        return $this->cache->get('garanties.options_filtres.v1', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->optionsFiltresFraiches();
        });
    }

    /**
     * @return array{types_piece: list<string>, concessions: list<string>, etablissements: list<string>, marques: list<string>, payeurs: list<string>, statuts_dg: list<string>}
     */
    private function optionsFiltresFraiches(): array
    {
        $base = "FROM mirror.bal_eloficash WHERE donnees->>'collectif' = '4116000' AND present_dans_sage";

        /** @var list<string> $typesPiece */
        $typesPiece = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT trim(donnees->>'Code type pièce') AS v $base "
            ."AND donnees->>'Code type pièce' IS NOT NULL AND trim(donnees->>'Code type pièce') <> '' ORDER BY 1",
        );
        /** @var list<string> $concessions */
        $concessions = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT trim(donnees->>'Code entité') AS v $base "
            ."AND donnees->>'Code entité' IS NOT NULL AND trim(donnees->>'Code entité') <> '' ORDER BY 1",
        );
        /** @var list<string> $etablissements */
        $etablissements = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT trim(donnees->>'codeetab') AS v $base "
            ."AND donnees->>'codeetab' IS NOT NULL AND trim(donnees->>'codeetab') <> '' ORDER BY 1",
        );
        /** @var list<string> $marques */
        $marques = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT trim(donnees->>'Marque (BU)') AS v $base "
            ."AND donnees->>'Marque (BU)' IS NOT NULL AND trim(donnees->>'Marque (BU)') <> '' ORDER BY 1",
        );
        /** @var list<string> $payeurs */
        $payeurs = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT trim(donnees->>'Code payeur') AS v $base "
            ."AND donnees->>'Code payeur' IS NOT NULL AND trim(donnees->>'Code payeur') <> '' ORDER BY 1",
        );
        /** @var list<string> $statutsDg */
        $statutsDg = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT statut_code FROM garanties.dossier '
            ."WHERE statut_code IS NOT NULL AND statut_code <> '' ORDER BY 1",
        );
        /** @var list<string> $sites */
        $sites = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT concession FROM garanties.dossier '
            ."WHERE concession IS NOT NULL AND concession <> '' ORDER BY 1",
        );

        return [
            'types_piece' => $typesPiece,
            'concessions' => $concessions,
            'etablissements' => $etablissements,
            'marques' => $marques,
            'payeurs' => $payeurs,
            'statuts_dg' => $statutsDg,
            'sites' => $sites,
        ];
    }

    /**
     * Detail d'une ligne Progiciel : l'ecriture + toutes les ecritures du meme chassis
     * + toutes du meme numor + les DG matchees (par chassis OU par numor).
     *
     * @return array{ecriture: array<string, mixed>, ecritures_chassis: list<array<string, mixed>>, ecritures_numor: list<array<string, mixed>>, dossiers: list<array<string, mixed>>}|null
     */
    public function detail(string $cleEcriture): ?array
    {
        // Filtre injecte dans le WHERE interieur de sqlBase pour ne traiter
        // qu'1 ligne (sinon : full scan 34k lignes + LATERAL JOIN -> timeout).
        $sql = $this->sqlBase(['cle_ecriture' => $cleEcriture]);
        $ecriture = $this->connection->fetchAssociative(
            $sql.' LIMIT 1',
            ['cle_ecriture' => $cleEcriture],
        );
        if (false === $ecriture) {
            return null;
        }

        $vinComplet = isset($ecriture['vin_complet']) ? (string) $ecriture['vin_complet'] : '';
        $chassis = isset($ecriture['chassis']) ? (string) $ecriture['chassis'] : '';
        $numor = isset($ecriture['numor']) ? (string) $ecriture['numor'] : '';

        $ecrituresChassis = '' === $chassis ? [] : $this->ecrituresDuChassis($chassis);
        $ecrituresNumor = '' === $numor ? [] : $this->ecrituresDuNumor($numor);

        $dossiers = [];
        if ('' !== $vinComplet || '' !== $chassis || '' !== $numor) {
            $dossiers = $this->dossiersAssocies($vinComplet, $chassis, $numor);
        }

        return [
            'ecriture' => $ecriture,
            'ecritures_chassis' => $ecrituresChassis,
            'ecritures_numor' => $ecrituresNumor,
            'dossiers' => $dossiers,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ecrituresDuChassis(string $chassis): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT donnees->>'Date de pièce' AS date_piece, "
            ."donnees->>'No pièce' AS no_piece, "
            ."donnees->>'Code type pièce' AS type_piece, "
            ."donnees->>'Libellé' AS libelle, "
            ."donnees->>'Code payeur' AS payeur, "
            ."donnees->>'numor' AS numor, "
            ."donnees->>'Code entité' AS concession, "
            ."donnees->>'codeetab' AS etablissement, "
            ."NULLIF(regexp_replace(replace(donnees->>'Montant initial en devise entité', ',', '.'), '[^0-9.\\-]', '', 'g'), '')::numeric AS montant_initial, "
            ."NULLIF(regexp_replace(replace(donnees->>'Montant solde en devise entité', ',', '.'), '[^0-9.\\-]', '', 'g'), '')::numeric AS solde "
            .'FROM mirror.bal_eloficash '
            ."WHERE donnees->>'collectif' = '4116000' AND present_dans_sage "
            ."AND right(donnees->>'numvin', 8) = ? "
            ."ORDER BY donnees->>'Date de pièce' DESC NULLS LAST",
            [$chassis],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ecrituresDuNumor(string $numor): array
    {
        // Match exact OU avec/sans prefixe '600' (Progiciel prefixe parfois le numor du portail Fiat).
        return $this->connection->fetchAllAssociative(
            "SELECT donnees->>'Date de pièce' AS date_piece, "
            ."donnees->>'No pièce' AS no_piece, "
            ."donnees->>'Code type pièce' AS type_piece, "
            ."donnees->>'Libellé' AS libelle, "
            ."donnees->>'Code payeur' AS payeur, "
            ."donnees->>'numvin' AS numvin, "
            ."donnees->>'Code entité' AS concession, "
            ."donnees->>'codeetab' AS etablissement, "
            ."NULLIF(regexp_replace(replace(donnees->>'Montant initial en devise entité', ',', '.'), '[^0-9.\\-]', '', 'g'), '')::numeric AS montant_initial, "
            ."NULLIF(regexp_replace(replace(donnees->>'Montant solde en devise entité', ',', '.'), '[^0-9.\\-]', '', 'g'), '')::numeric AS solde "
            .'FROM mirror.bal_eloficash '
            ."WHERE donnees->>'collectif' = '4116000' AND present_dans_sage "
            ."AND donnees->>'numor' IN (?, ?, ?) "
            ."ORDER BY donnees->>'Date de pièce' DESC NULLS LAST",
            [$numor, '600'.$numor, ltrim($numor, '6')],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dossiersAssocies(string $vinComplet, string $chassis, string $numor): array
    {
        // Miroir exact de la logique de la vue materialisee (Version20260623160000) :
        //  - DG a VIN complet (mvs 17 car.) -> match par VIN complet (Toyota/Opel/BMW)
        //  - sinon (Fiat, 8 car.) -> chassis 8 + OR (right(numor, 4))
        // Le VIN complet desambiguise sans scope marque ; le chassis 8 n'est utilise
        // que pour Fiat, couple a l'OR (donc pas de collision cross-marque).
        $clauses = [];
        $params = [];
        if ('' !== $vinComplet) {
            $clauses[] = 'length(mvs) = 17 AND mvs = ?';
            $params[] = $vinComplet;
        }
        if ('' !== $chassis && '' !== $numor) {
            $clauses[] = 'length(mvs) <> 17 AND chassis = ? AND numero_or = right(?, 4)';
            $params[] = $chassis;
            $params[] = $numor;
        }
        if ([] === $clauses) {
            return [];
        }

        $where = implode(' OR ', array_map(static fn (string $c): string => '('.$c.')', $clauses));

        return $this->connection->fetchAllAssociative(
            'SELECT id, num_dg, mvs, emetteur, chassis, marque, concession, statut_code, montant_dg, numero_or, date_intervention '
            .'FROM garanties.dossier WHERE '.$where.' ORDER BY id',
            $params,
        );
    }

    /**
     * Liste pour les tranches d'age (affichage UI).
     *
     * @return array<string, string>
     */
    public static function tranchesAge(): array
    {
        return [
            'lt3m' => 'Moins de 3 mois',
            '3a6m' => 'De 3 à 6 mois',
            '6a12m' => 'De 6 à 12 mois',
            '12a24m' => 'De 1 à 2 ans',
            'gt24m' => 'Plus de 2 ans',
        ];
    }

    /**
     * Requete principale : 1 ligne = 1 ecriture Progiciel 4116000, enrichie via window
     * functions et LATERAL JOIN garanties.dossier.
     *
     * Etat de rapprochement (calcule en SQL) :
     *   - 'soldee'         : solde = 0 (lettrage implicite reussi cote chassis)
     *   - 'normale'        : solde > 0, chassis avec DG payee -> creance en attente
     *   - 'orpheline'      : creance avec VIN mais aucune DG portail matchee
     *   - 'od_sans_vin'    : OD sans VIN (paiement constructeur ou autre)
     *   - 'sans_vin_sans_or' : pas de VIN ni de numor exploitable
     *   - 'trop_percu'     : sum solde du chassis < 0 (Synthauto doit au constructeur)
     *
     * @param Filtres $filtres
     */
    private function sqlBase(array $filtres, bool $avecCommentaires = false): string
    {
        // Lit la vue materialisee precalculee (migration mv_reconciliation,
        // rafraichie aux imports ETL/sync). Plus de LATERAL ni de fenetrage a la
        // volee : un simple SELECT filtre sur une table plate indexee. Les filtres
        // portent sur les colonnes deja extraites (donc indexables / instantanes).
        // IMPORTANT : la logique de calcul vit desormais dans la MV (migration) ;
        // toute evolution du rapprochement doit etre repercutee la-bas.
        $lettrees = !empty($filtres['lettrees']);
        $where = ['vue_lettree = '.($lettrees ? 'TRUE' : 'FALSE')];

        if (!empty($filtres['types_piece'])) {
            $where[] = 'type_piece IN (:types_piece)';
        }
        if (!empty($filtres['concessions'])) {
            $where[] = 'concession_sage IN (:concessions)';
        }
        if (!empty($filtres['etablissements'])) {
            $where[] = 'etablissement_sage IN (:etablissements)';
        }
        if (!empty($filtres['marques'])) {
            $where[] = 'marque_sage IN (:marques)';
        }
        if (!empty($filtres['payeurs'])) {
            $where[] = 'payeur_sage IN (:payeurs)';
        }
        // Perimetre garanties : compte collectif 4116000 uniquement.
        $where[] = "collectif = '4116000'";
        if (!empty($filtres['cle_ecriture'])) {
            $where[] = 'cle_ecriture = :cle_ecriture';
        }
        if (!empty($filtres['recherche'])) {
            $where[] = '(no_piece ILIKE :recherche '
                .'OR vin_complet ILIKE :recherche '
                .'OR chassis ILIKE :recherche '
                .'OR numor ILIKE :recherche '
                .'OR libelle ILIKE :recherche '
                .'OR payeur_sage ILIKE :recherche '
                ."OR replace(abs(montant_initial)::text, '.', ',') ILIKE :recherche_montant "
                ."OR replace(abs(solde)::text, '.', ',') ILIKE :recherche_montant)";
        }
        if (!empty($filtres['statuts_dg'])) {
            $where[] = 'statut_dg_principal IN (:statuts_dg)';
        }
        if (!empty($filtres['etats'])) {
            $where[] = 'etat_rapprochement IN (:etats)';
        }
        if (!empty($filtres['signe'])) {
            $signe = $filtres['signe'];
            if ('creance' === $signe) {
                $where[] = 'solde > 0';
            } elseif ('paiement' === $signe) {
                $where[] = 'solde < 0';
            } elseif ('soldee' === $signe) {
                $where[] = 'solde = 0';
            }
        }
        if (!empty($filtres['age']) && isset(self::TRANCHES_AGE[$filtres['age']])) {
            [$min, $max] = self::TRANCHES_AGE[$filtres['age']];
            $where[] = sprintf('(CURRENT_DATE - date_piece)::int >= %d', $min);
            if (null !== $max) {
                $where[] = sprintf('(CURRENT_DATE - date_piece)::int < %d', $max);
            }
        }
        if (!empty($filtres['sites'])) {
            // Approximation : on filtre sur le site de la DG principale (la
            // reconciliation est deja faite tous sites confondus dans la MV).
            $where[] = 'site_principal IN (:sites)';
        }

        if (!$avecCommentaires) {
            return 'SELECT * FROM garanties.mv_reconciliation WHERE '.implode(' AND ', $where);
        }

        // Commentaires rattaches par JOINTURE, jamais par la MV : les notes changent en
        // continu alors que la vue est un instantane rafraichi par cron — les compteurs
        // y seraient perimes, et chaque evolution imposerait de recreer la vue.
        //
        // Deux agregats GROUPES (un par ancrage : DG, ecriture) plutot qu'un LATERAL par
        // ligne. Le LATERAL serait evalue AVANT le LIMIT, donc ~90 000 fois pour afficher
        // 50 lignes ; ici garanties.note est parcourue une seule fois puis jointe par
        // hachage. Les colonnes des sous-requetes sont prefixees `ancre_` pour ne jamais
        // entrer en collision avec celles de la vue dans le WHERE.
        return 'SELECT mv.*, '
            .'COALESCE(cdg.nb, 0) + COALESCE(cec.nb, 0) AS nb_commentaires, '
            ."NULLIF(concat_ws(' | ', cdg.textes, cec.textes), '') AS commentaires "
            .'FROM garanties.mv_reconciliation mv '
            .'LEFT JOIN ('
                .'SELECT n.dossier_id AS ancre_dossier, count(*) AS nb, '
                .'string_agg('.self::SQL_ENTREE_COMMENTAIRE.", ' | ' ORDER BY n.cree_le) AS textes "
                .'FROM garanties.note n JOIN shared.users u ON u.id = n.auteur_id '
                .'WHERE n.dossier_id IS NOT NULL GROUP BY n.dossier_id'
            .') cdg ON cdg.ancre_dossier = mv.id_dg_principal '
            .'LEFT JOIN ('
                ."SELECT n.cle_ecriture AS ancre_cle, COALESCE(n.oidech, '') AS ancre_oidech, count(*) AS nb, "
                .'string_agg('.self::SQL_ENTREE_COMMENTAIRE.", ' | ' ORDER BY n.cree_le) AS textes "
                .'FROM garanties.note n JOIN shared.users u ON u.id = n.auteur_id '
                ."WHERE n.cle_ecriture IS NOT NULL GROUP BY n.cle_ecriture, COALESCE(n.oidech, '')"
            .') cec ON cec.ancre_cle = mv.cle_ecriture '
                ."AND cec.ancre_oidech = COALESCE(mv.oidech, '') "
            .'WHERE '.implode(' AND ', $where);
    }

    /**
     * @param Filtres $filtres
     *
     * @return array<string, mixed>
     */
    private function params(array $filtres): array
    {
        $params = [];
        foreach (['types_piece', 'concessions', 'etablissements', 'marques', 'payeurs', 'statuts_dg', 'etats', 'sites'] as $k) {
            if (!empty($filtres[$k])) {
                /** @var list<string> $valeurs */
                $valeurs = $filtres[$k];
                $params[$k] = $valeurs;
            }
        }
        if (!empty($filtres['recherche'])) {
            $params['recherche'] = '%'.$filtres['recherche'].'%';
            // Recherche montant : on garde uniquement chiffres + virgule + point,
            // puis on convertit point -> virgule (format canonique Progiciel).
            // Exemples : "24 472,53" / "24472.53" / "24472" -> "24472,53" / "24472,53" / "24472".
            $normalise = (string) preg_replace('/[^0-9,.]/', '', $filtres['recherche']);
            $normalise = str_replace('.', ',', $normalise);
            $params['recherche_montant'] = '%'.$normalise.'%';
        }
        if (!empty($filtres['cle_ecriture'])) {
            $params['cle_ecriture'] = (string) $filtres['cle_ecriture'];
        }

        return $params;
    }

    /**
     * @param Filtres $filtres
     *
     * @return array<string, ArrayParameterType>
     */
    private function types(array $filtres): array
    {
        $types = [];
        foreach (['types_piece', 'concessions', 'etablissements', 'marques', 'payeurs', 'statuts_dg', 'etats', 'sites'] as $k) {
            if (!empty($filtres[$k])) {
                $types[$k] = ArrayParameterType::STRING;
            }
        }

        return $types;
    }
}
