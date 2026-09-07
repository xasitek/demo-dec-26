<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\RetourClient;
use App\Recouvrement\Referentiel\Etablissements;
use App\Recouvrement\Regle\MoteurFiltre;
use App\Recouvrement\Repository\MessageSortantRepository;
use App\Recouvrement\Repository\RelanceEnvoiRepository;
use App\Recouvrement\Repository\RetourClientRepository;
use App\Recouvrement\Repository\RetourPieceJointeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Assemble la FICHE CLIENT d'un compte : identité (mirror.tiers), encours + buckets
 * de retard et écritures (v_impayes), et la timeline des échanges (relances envoyées,
 * réponses clients, réponses de la compta). Une requête par bloc, indexées, zéro N+1.
 */
final class FicheClientService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RelanceEnvoiRepository $relances,
        private readonly RetourClientRepository $retours,
        private readonly MessageSortantRepository $messagesSortants,
        private readonly RetourPieceJointeRepository $piecesJointes,
    ) {
    }

    /**
     * Toutes les données de la fiche d'un compte, ou null si le compte est totalement
     * inconnu (ni tiers, ni écriture).
     *
     * @return array{
     *     compte: string,
     *     identite: array<string, mixed>,
     *     encours: array<string, string|int>,
     *     nb_ecritures: int,
     *     timeline: list<array<string, mixed>>,
     *     ecarte: bool
     * }|null
     */
    public function assembler(string $compte): ?array
    {
        $compte = trim($compte);
        if ('' === $compte) {
            return null;
        }

        $identite = $this->identite($compte);
        $encours = $this->encours($compte);

        if (null === $identite && 0 === (int) $encours['nb']) {
            return null;
        }

        return [
            'compte' => $compte,
            'identite' => $identite ?? ['raison_sociale' => $compte],
            'encours' => $encours,
            'nb_ecritures' => $this->compterEcritures($compte),
            'timeline' => $this->timeline($compte),
            'ecarte' => $this->estEcarte($compte),
        ];
    }

    /** Le compte est-il ecarte de la relance (decision de curation) ? */
    private function estEcarte(string $compte): bool
    {
        return 'ecarte' === $this->connection->fetchOne(
            'SELECT etat FROM recouvrement.compte_exclusion WHERE compte_code = :c LIMIT 1',
            ['c' => $compte],
        );
    }

    /**
     * Identité depuis le JSONB mirror.tiers (clés Progiciel en français), avec libellés
     * métier pour les codes (famille, conditions, mode de règlement). null si absent.
     *
     * @return array<string, mixed>|null
     */
    private function identite(string $compte): ?array
    {
        $json = $this->connection->fetchOne(
            "SELECT donnees FROM mirror.tiers WHERE donnees->>'code' = :c LIMIT 1",
            ['c' => $compte],
        );
        if (false === $json || !\is_string($json)) {
            return null;
        }

        /** @var array<string, mixed> $d */
        $d = json_decode($json, true) ?: [];

        $adresse = array_filter([
            self::v($d, 'Adresse 1'), self::v($d, 'Adresse 2'),
            self::v($d, 'Adresse 3'), self::v($d, 'Adresse 4'),
            trim(self::v($d, 'Code postal').' '.self::v($d, 'ville')),
        ], static fn (string $l): bool => '' !== trim($l));

        $famille = self::v($d, 'Code statut');
        $cond = self::v($d, 'Code conditions de règlement') ?: self::v($d, 'condcomid');
        $mode = self::v($d, 'Code mode de paiement') ?: self::v($d, 'modecomid');

        return [
            'raison_sociale' => self::v($d, 'Raison Sociale') ?: trim(self::v($d, 'prenom').' '.self::v($d, 'nom')) ?: $compte,
            'titre' => self::v($d, 'titre'),
            'siren' => self::v($d, 'siren'),
            'siret' => self::v($d, 'siret'),
            'tva' => self::v($d, 'No TVA intracom'),
            'adresse' => array_values($adresse),
            'email' => self::v($d, 'email') ?: self::v($d, 'E-mail Particulier'),
            'telephone' => self::v($d, 'Téléphone'),
            'portable' => self::v($d, 'portable'),
            'statut_juridique' => self::v($d, 'Statut juridique'),
            'famille' => self::codeLibelle('code_statut', $famille),
            'conditions' => self::codeLibelle('code_conditions_reglement', $cond),
            'mode_paiement' => self::codeLibelle('code_mode_paiement', $mode),
            'plafond' => self::v($d, 'Plafond autorisé'),
            'iban' => self::v($d, 'iban'),
            'bic' => self::v($d, 'bic'),
            'bloque' => \in_array(mb_strtolower(self::v($d, 'bloqué')), ['1', 'o', 'oui', 'true', 't'], true),
        ];
    }

    /**
     * Encours total + buckets de retard + société/établissement/marque dominants. Les
     * COUNT(DISTINCT) permettent à l'en-tête de dire « 3 établissements » plutôt que
     * d'afficher un site arbitraire pour un client multi-sites.
     *
     * @return array<string, string|int>
     */
    private function encours(string $compte): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS nb,
                    COALESCE(SUM(montant_solde), 0) AS total,
                    COALESCE(SUM(CASE WHEN jours_retard <= 0 THEN montant_solde ELSE 0 END), 0) AS a_echoir,
                    COALESCE(SUM(CASE WHEN jours_retard > 0 THEN montant_solde ELSE 0 END), 0) AS echu,
                    COALESCE(SUM(CASE WHEN jours_retard BETWEEN 1 AND 30 THEN montant_solde ELSE 0 END), 0) AS b_0_30,
                    COALESCE(SUM(CASE WHEN jours_retard > 30 THEN montant_solde ELSE 0 END), 0) AS b_30_plus,
                    COALESCE(MAX(jours_retard), 0) AS retard_max,
                    MAX(codeetab) AS codeetab,
                    COUNT(DISTINCT codeetab) AS nb_etab,
                    MAX(code_entite) AS code_entite,
                    COUNT(DISTINCT code_entite) AS nb_societes,
                    MAX(marque) AS marque,
                    MIN(date_echeance) FILTER (WHERE montant_solde > 0) AS creance_ancienne,
                    MAX(date_echeance) FILTER (WHERE montant_solde > 0) AS creance_recente
             FROM recouvrement.v_impayes WHERE compte = :c',
            ['c' => $compte],
        );
        if (false === $row) {
            $row = [];
        }

        return [
            'nb' => (int) ($row['nb'] ?? 0),
            'total' => (string) ($row['total'] ?? '0'),
            'a_echoir' => (string) ($row['a_echoir'] ?? '0'),
            'echu' => (string) ($row['echu'] ?? '0'),
            'b_0_30' => (string) ($row['b_0_30'] ?? '0'),
            'b_30_plus' => (string) ($row['b_30_plus'] ?? '0'),
            'retard_max' => (int) ($row['retard_max'] ?? 0),
            'codeetab' => (string) ($row['codeetab'] ?? ''),
            'nb_etab' => (int) ($row['nb_etab'] ?? 0),
            'code_entite' => (string) ($row['code_entite'] ?? ''),
            'nb_societes' => (int) ($row['nb_societes'] ?? 0),
            'marque' => (string) ($row['marque'] ?? ''),
            'creance_ancienne' => (string) ($row['creance_ancienne'] ?? ''),
            'creance_recente' => (string) ($row['creance_recente'] ?? ''),
        ];
    }

    /** @var array<string, string> tris autorisés des écritures -> clause ORDER BY sûre. */
    private const TRIS_ECRITURES = [
        'echeance' => 'date_echeance ASC NULLS LAST, numpiece',
        'retard' => 'jours_retard DESC, date_echeance NULLS LAST',
        'montant' => 'montant_solde DESC, date_echeance NULLS LAST',
        'libelle' => 'libelle ASC NULLS LAST, date_echeance NULLS LAST',
    ];

    /**
     * Écritures (factures) du compte, paginées et triées (tri en liste blanche).
     *
     * @return list<array<string, mixed>>
     */
    public function ecritures(string $compte, string $tri = 'echeance', int $page = 1, int $parPage = 50, string $etablissement = '', string $societe = ''): array
    {
        $ordre = self::TRIS_ECRITURES[$tri] ?? self::TRIS_ECRITURES['echeance'];
        [$where, $params, $types] = $this->filtreEcritures($compte, $etablissement, $societe);
        $params['limit'] = max(1, $parPage);
        $params['offset'] = (max(1, $page) - 1) * max(1, $parPage);
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ecriture_id, numpiece, reference_facture, date_piece, date_echeance,
                    jours_retard, montant_solde, montant_initial_facturation, libelle, type_piece, chemin_pdf,
                    code_entite, codeetab,
                    (SELECT fs.type FROM recouvrement.facture_site fs WHERE fs.ecriture_id = v_impayes.ecriture_id AND fs.actif = true LIMIT 1) AS gel_type
             FROM recouvrement.v_impayes WHERE '.$where.'
             ORDER BY '.$ordre.'
             LIMIT :limit OFFSET :offset',
            $params,
            $types,
        );

        return $rows;
    }

    /** Nombre d'écritures du compte (mêmes filtres société/établissement), pour l'en-tête + la pagination. */
    public function compterEcritures(string $compte, string $etablissement = '', string $societe = ''): int
    {
        [$where, $params, $types] = $this->filtreEcritures($compte, $etablissement, $societe);

        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.v_impayes WHERE '.$where,
            $params,
            $types,
        );
    }

    /**
     * Établissements (codeetab) présents dans les écritures du compte, avec leur libellé,
     * pour alimenter le filtre déroulant de la fiche. Restreint à une société si demandé,
     * pour que les deux filtres restent cohérents entre eux. Triés par code.
     *
     * @return list<array{code: string, libelle: string}>
     */
    public function etablissementsDuCompte(string $compte, string $societe = ''): array
    {
        $sql = "SELECT DISTINCT codeetab FROM recouvrement.v_impayes
                WHERE compte = :c AND codeetab IS NOT NULL AND btrim(codeetab) <> ''";
        $params = ['c' => $compte];

        $societe = trim($societe);
        if ('' !== $societe) {
            $sql .= ' AND code_entite = :soc';
            $params['soc'] = $societe;
        }

        /** @var list<string> $vals */
        $vals = $this->connection->fetchFirstColumn($sql.' ORDER BY codeetab', $params);

        return array_map(static function (mixed $v): array {
            $code = trim((string) $v);

            return ['code' => $code, 'libelle' => Etablissements::avecCode($code)];
        }, $vals);
    }

    /**
     * Sociétés (code_entite) présentes dans les écritures du compte, pour le filtre
     * déroulant. Triées.
     *
     * @return list<string>
     */
    public function societesDuCompte(string $compte): array
    {
        /** @var list<string> $vals */
        $vals = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT code_entite FROM recouvrement.v_impayes
             WHERE compte = :c AND code_entite IS NOT NULL AND btrim(code_entite) <> ''
             ORDER BY code_entite",
            ['c' => $compte],
        );

        return array_map(static fn (mixed $v): string => trim((string) $v), $vals);
    }

    /**
     * Clause WHERE + paramètres des écritures : compte + établissement (codeetab) et
     * société (code_entite) optionnels.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private function filtreEcritures(string $compte, string $etablissement, string $societe = ''): array
    {
        $where = 'compte = :c';
        $params = ['c' => $compte];

        $etablissement = trim($etablissement);
        if ('' !== $etablissement) {
            $where .= ' AND codeetab = :etab';
            $params['etab'] = $etablissement;
        }

        $societe = trim($societe);
        if ('' !== $societe) {
            $where .= ' AND code_entite = :soc';
            $params['soc'] = $societe;
        }

        return [$where, $params, []];
    }

    /**
     * Timeline chronologique inverse des échanges (relances + retours + réponses),
     * assemblée comme le panneau détail des Retours.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(string $compte): array
    {
        $timeline = [];

        foreach ($this->relances->findEnvoyeesParCompte($compte) as $relance) {
            $date = $relance->getEnvoyeLe() ?? $relance->getCreeLe();
            $timeline[] = ['type' => 'relance', 'ts' => $date->getTimestamp(), 'date' => $date, 'relance' => $relance];
        }

        $retoursCompte = $this->retours->findByCompte($compte);
        $idsRetours = array_values(array_filter(array_map(
            static fn (RetourClient $r): ?int => $r->getId(),
            $retoursCompte,
        )));
        $piecesParRetour = $this->piecesJointes->metaParRetours($idsRetours);
        foreach ($retoursCompte as $retour) {
            $timeline[] = [
                'type' => 'retour',
                'ts' => $retour->getRecuLe()->getTimestamp(),
                'date' => $retour->getRecuLe(),
                'retour' => $retour,
                'pieces' => $piecesParRetour[$retour->getId()] ?? [],
            ];
        }

        foreach ($this->messagesSortants->findParCompte($compte) as $sortant) {
            $timeline[] = ['type' => 'reponse', 'ts' => $sortant->getEnvoyeLe()->getTimestamp(), 'date' => $sortant->getEnvoyeLe(), 'message' => $sortant];
        }

        usort($timeline, static fn (array $a, array $b): int => ((int) $b['ts']) <=> ((int) $a['ts']));

        return $timeline;
    }

    /**
     * Valeur texte d'une clé JSONB (chaîne vide si absente).
     *
     * @param array<string, mixed> $donnees
     */
    private static function v(array $donnees, string $cle): string
    {
        $valeur = $donnees[$cle] ?? null;

        return \is_scalar($valeur) ? trim((string) $valeur) : '';
    }

    /**
     * Associe un code à son libellé métier (MoteurFiltre::LIBELLES_VALEURS) : renvoie
     * ['code' => …, 'libelle' => …] ou null si pas de code.
     *
     * @return array{code: string, libelle: string}|null
     */
    private static function codeLibelle(string $champ, string $code): ?array
    {
        if ('' === $code) {
            return null;
        }
        $libelles = MoteurFiltre::LIBELLES_VALEURS[$champ] ?? [];

        return ['code' => $code, 'libelle' => (string) ($libelles[$code] ?? $code)];
    }
}
