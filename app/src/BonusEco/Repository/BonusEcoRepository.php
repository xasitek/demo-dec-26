<?php

declare(strict_types=1);

namespace App\BonusEco\Repository;

use Doctrine\DBAL\Connection;

/**
 * Audit des bonus ecologiques.
 *
 * Unite d'affichage : 1 ECRITURE Progiciel (table public.t_ari_balance_agee_bonuseco
 * cote compta externe), enrichie avec le dossier ASP correspondant (matching
 * par numvin = num_chassis) quand on en a un dans bonus_eco.dossier_asp.
 *
 * Lecture pure : aucune ecriture en BDD ici. La table dossier_asp est alimentee
 * par la commande de sync sheet.
 *
 * @phpstan-type Filtres array{
 *     codesoc?: ?list<string>,
 *     marque?: ?list<string>,
 *     retard?: ?list<string>,
 *     lib_etat?: ?list<string>,
 *     recherche?: ?string
 * }
 */
final class BonusEcoRepository
{
    /**
     * Tris autorises : cle publique => expression SQL (anti-injection).
     *
     * @var array<string, string>
     */
    private const TRIS = [
        'date' => 'dateecriture',
        'piece' => 'numpiece',
        'concession' => 'codeetab',
        'client' => 'nom',
        'chassis' => 'numvin',
        'montant' => 'montant_abs',
        'retard' => 'retard',
        'etat_asp' => 'asp_lib_etat',
    ];

    /**
     * Compte comptable des creances bonus ecologique chez Synthauto.
     * Progiciel stocke aussi le compte 4432100 (autres aides) dans la meme table balance agee :
     * il faut filtrer pour ne pas mélanger les deux flux.
     */
    private const CODECOMPTE_BONUS_ECO = '4432000';

    public function __construct(
        private readonly Connection $sageConnection,
        private readonly Connection $defaultConnection,
    ) {
    }

    /**
     * Page paginee. 1 ligne = 1 ecriture Progiciel bonuseco enrichie ASP si match.
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

        // 1. Lecture des lignes Progiciel avec filtres (cote compta externe).
        [$whereSql, $params] = $this->whereSage($filtres);
        $sage = $this->sageConnection->fetchAllAssociative(
            'SELECT '
            .'numero, codesoc, codeetab, codecompte, compte, nom, '
            .'dateecriture, reference, numpiece, '
            .'debit, credit, "Montant signe" AS montant_signe, '
            .'"Montant (valeur absolue)" AS montant_abs, '
            .'numimmat, numvin, retard, marque, modele, '
            .'prenom, nomclientproprietaire, email, numtel1, '
            .'prenomvendeur, nomvendeur '
            .'FROM public.t_ari_balance_agee_bonuseco '
            ."WHERE $whereSql "
            ."ORDER BY $colonneTri $sensTri NULLS LAST, numero DESC "
            ."LIMIT $parPage OFFSET $offset",
            $params,
        );

        if ([] === $sage) {
            return [];
        }

        // 2. Lookup ASP par VIN (lot, 1 requete) cote fc_pro.
        $vins = [];
        foreach ($sage as $r) {
            $v = isset($r['numvin']) ? trim((string) $r['numvin']) : '';
            if ('' !== $v) {
                $vins[$v] = true;
            }
        }
        $vinsList = array_keys($vins);
        $aspParChassis = [] === $vinsList ? [] : $this->aspParChassis($vinsList);

        // 3. Enrichissement.
        foreach ($sage as &$r) {
            $vin = isset($r['numvin']) ? trim((string) $r['numvin']) : '';
            $r['asp'] = '' !== $vin ? ($aspParChassis[$vin] ?? null) : null;
        }
        unset($r);

        /* @var list<array<string, mixed>> $sage */
        return $sage;
    }

    /**
     * Regroupe les ecritures Progiciel dont le dossier ASP est en correction
     * (lib_etat = 'Demande initialisée'), par destinataire (vendeur ou secretaire).
     *
     * Retourne une liste de groupes :
     *   - cle : email Progiciel si renseigne, sinon hash nom+prenom (pour groupement)
     *   - chaque groupe : prenom/nom du destinataire + liste des ecritures
     *
     * @param 'vendeur'|'secretaire' $type
     *
     * @return list<array{prenom: string, nom: string, email_sage: string, ecritures: list<array<string, mixed>>}>
     */
    public function dossiersGroupesPourRelance(string $type): array
    {
        // 1) recuperer les VINs en correction cote BDD locale
        $vinsEnCorrection = $this->defaultConnection->fetchFirstColumn(
            "SELECT DISTINCT num_chassis FROM bonus_eco.dossier_asp WHERE lib_etat = 'Demande initialisée'",
        );
        if ([] === $vinsEnCorrection) {
            return [];
        }

        // 2) recuperer les ecritures Progiciel correspondantes (sur le compte bonus eco)
        $ph = implode(',', array_fill(0, \count($vinsEnCorrection), '?'));
        /** @var list<array<string, mixed>> $ecritures */
        $ecritures = $this->sageConnection->fetchAllAssociative(
            'SELECT numero, codesoc, codeetab, dateecriture, numpiece, numvin, numimmat, '
            .'marque, modele, nom, "Montant signe" AS montant_signe, retard, '
            .'prenomvendeur, nomvendeur, emailvendeur, nomsecretaire, emailsecr '
            .'FROM public.t_ari_balance_agee_bonuseco '
            ."WHERE codecompte = '".self::CODECOMPTE_BONUS_ECO."' AND numvin IN ($ph) "
            .'ORDER BY codeetab, dateecriture DESC',
            $vinsEnCorrection,
        );
        if ([] === $ecritures) {
            return [];
        }

        // 3) grouper par destinataire (cle = email Progiciel si dispo, sinon norm(prenom+nom))
        $groupes = [];
        foreach ($ecritures as $ec) {
            if ('vendeur' === $type) {
                $prenom = trim((string) ($ec['prenomvendeur'] ?? ''));
                $nom = trim((string) ($ec['nomvendeur'] ?? ''));
                $email = trim((string) ($ec['emailvendeur'] ?? ''));
            } else {
                $prenom = '';
                $nom = trim((string) ($ec['nomsecretaire'] ?? ''));
                $email = trim((string) ($ec['emailsecr'] ?? ''));
            }

            if ('' === $email && '' === $prenom && '' === $nom) {
                continue;
            }

            $cle = '' !== $email ? strtolower($email) : 'sansmail::'.strtolower($prenom.'|'.$nom);

            if (!isset($groupes[$cle])) {
                $groupes[$cle] = [
                    'prenom' => $prenom,
                    'nom' => $nom,
                    'email_sage' => $email,
                    'ecritures' => [],
                ];
            }
            $groupes[$cle]['ecritures'][] = $ec;
        }

        return array_values($groupes);
    }

    /**
     * Iteration complete pour l'export CSV. Charge tout en memoire (volumetrie
     * faible : ~1k lignes max) avec enrichissement ASP en 1 requete batch.
     *
     * @param Filtres $filtres
     *
     * @return list<array<string, mixed>>
     */
    public function toutes(array $filtres = [], string $tri = 'date', string $sens = 'desc'): array
    {
        $colonneTri = self::TRIS[$tri] ?? self::TRIS['date'];
        $sensTri = 'asc' === strtolower($sens) ? 'ASC' : 'DESC';

        [$whereSql, $params] = $this->whereSage($filtres);
        $sage = $this->sageConnection->fetchAllAssociative(
            'SELECT '
            .'numero, codesoc, codeetab, codecompte, compte, nom, '
            .'dateecriture, reference, numpiece, '
            .'debit, credit, "Montant signe" AS montant_signe, '
            .'"Montant (valeur absolue)" AS montant_abs, '
            .'numimmat, numvin, retard, marque, modele, '
            .'prenom, nomclientproprietaire '
            .'FROM public.t_ari_balance_agee_bonuseco '
            ."WHERE $whereSql "
            ."ORDER BY $colonneTri $sensTri NULLS LAST, numero DESC",
            $params,
        );

        if ([] === $sage) {
            return [];
        }

        // Lookup ASP batch.
        $vins = [];
        foreach ($sage as $r) {
            $v = isset($r['numvin']) ? trim((string) $r['numvin']) : '';
            if ('' !== $v) {
                $vins[$v] = true;
            }
        }
        $aspParChassis = [] === $vins ? [] : $this->aspParChassis(array_keys($vins));

        foreach ($sage as &$r) {
            $vin = isset($r['numvin']) ? trim((string) $r['numvin']) : '';
            $r['asp'] = '' !== $vin ? ($aspParChassis[$vin] ?? null) : null;
        }
        unset($r);

        /* @var list<array<string, mixed>> $sage */
        return $sage;
    }

    /**
     * @param Filtres $filtres
     */
    public function compter(array $filtres = []): int
    {
        [$whereSql, $params] = $this->whereSage($filtres);

        /** @var int $n */
        $n = (int) $this->sageConnection->fetchOne(
            "SELECT count(*) FROM public.t_ari_balance_agee_bonuseco WHERE $whereSql",
            $params,
        );

        return $n;
    }

    /**
     * Synthese pour les KPI du dashboard.
     *
     * @param Filtres $filtres
     *
     * @return array{total_lignes: int, total_montant_du: float, lignes_soldees_asp: int, lignes_sans_asp: int}
     */
    public function synthese(array $filtres = []): array
    {
        [$whereSql, $params] = $this->whereSage($filtres);

        /** @var array<string, mixed>|false $row */
        $row = $this->sageConnection->fetchAssociative(
            'SELECT count(*) AS total_lignes, '
            .'COALESCE(sum("Montant signe"), 0) AS total_montant_signe '
            ."FROM public.t_ari_balance_agee_bonuseco WHERE $whereSql",
            $params,
        );

        $totalLignes = false === $row ? 0 : (int) ($row['total_lignes'] ?? 0);
        $totalDu = false === $row ? 0.0 : (float) ($row['total_montant_signe'] ?? 0.0);

        // Pour le decoupe ASP, on requete la BDD locale.
        $stats = $this->defaultConnection->fetchAssociative(
            'SELECT '
            ."count(*) FILTER (WHERE lib_etat = 'Soldé') AS soldees, "
            ."count(*) FILTER (WHERE lib_etat <> 'Soldé' OR lib_etat IS NULL) AS en_cours "
            .'FROM bonus_eco.dossier_asp',
        );

        return [
            'total_lignes' => $totalLignes,
            'total_montant_du' => $totalDu,
            'lignes_soldees_asp' => false === $stats ? 0 : (int) ($stats['soldees'] ?? 0),
            'lignes_sans_asp' => false === $stats ? 0 : (int) ($stats['en_cours'] ?? 0),
        ];
    }

    /**
     * Options pour les multi-select de filtres.
     *
     * @return array{codesoc: list<string>, marques: list<string>, retards: list<string>, lib_etats: list<string>}
     */
    public function optionsFiltres(): array
    {
        // Filtre obligatoire codecompte = bonus eco : on ne propose que les
        // valeurs presentes sur ce compte (sinon on remonterait des marques /
        // societes qui n'ont pas de bonus eco).
        $scope = sprintf("codecompte = '%s'", self::CODECOMPTE_BONUS_ECO);

        /** @var list<string> $codesoc */
        $codesoc = $this->sageConnection->fetchFirstColumn(
            'SELECT DISTINCT codesoc FROM public.t_ari_balance_agee_bonuseco '
            ."WHERE $scope AND codesoc IS NOT NULL AND codesoc <> '' ORDER BY 1",
        );
        /** @var list<string> $marques */
        $marques = $this->sageConnection->fetchFirstColumn(
            'SELECT DISTINCT trim(marque) FROM public.t_ari_balance_agee_bonuseco '
            ."WHERE $scope AND marque IS NOT NULL AND trim(marque) <> '' ORDER BY 1",
        );
        // Tri logique pour retard : <30, >30, >60, >90, >120, >180, >240
        /** @var list<string> $retards */
        $retards = $this->sageConnection->fetchFirstColumn(
            'SELECT retard FROM public.t_ari_balance_agee_bonuseco '
            ."WHERE $scope AND retard IS NOT NULL AND retard <> '' "
            .'GROUP BY retard ORDER BY CASE retard '
            ."WHEN '<30' THEN 0 WHEN '>30' THEN 1 WHEN '>60' THEN 2 WHEN '>90' THEN 3 "
            ."WHEN '>120' THEN 4 WHEN '>180' THEN 5 WHEN '>240' THEN 6 ELSE 99 END",
        );
        /** @var list<string> $libEtats */
        $libEtats = $this->defaultConnection->fetchFirstColumn(
            'SELECT DISTINCT lib_etat FROM bonus_eco.dossier_asp '
            ."WHERE lib_etat IS NOT NULL AND lib_etat <> '' ORDER BY 1",
        );

        return ['codesoc' => $codesoc, 'marques' => $marques, 'retards' => $retards, 'lib_etats' => $libEtats];
    }

    /**
     * Detail d'une ecriture par son numero.
     *
     * @return array{ecriture: array<string, mixed>, asp: ?array<string, mixed>, dossiers_asp_chassis: list<array<string, mixed>>}|null
     */
    public function detail(int $numero): ?array
    {
        /** @var array<string, mixed>|false $ecriture */
        $ecriture = $this->sageConnection->fetchAssociative(
            "SELECT * FROM public.t_ari_balance_agee_bonuseco WHERE numero = ? AND codecompte = '".self::CODECOMPTE_BONUS_ECO."'",
            [$numero],
        );
        if (false === $ecriture) {
            return null;
        }

        $vin = isset($ecriture['numvin']) ? trim((string) $ecriture['numvin']) : '';
        $aspParChassis = '' === $vin ? [] : $this->aspParChassis([$vin]);

        $dossiersChassis = '' === $vin ? [] : $this->defaultConnection->fetchAllAssociative(
            'SELECT * FROM bonus_eco.dossier_asp WHERE num_chassis = ? ORDER BY date_creation DESC NULLS LAST, id DESC',
            [$vin],
        );

        return [
            'ecriture' => $ecriture,
            'asp' => $aspParChassis[$vin] ?? null,
            'dossiers_asp_chassis' => $dossiersChassis,
        ];
    }

    /**
     * Recherche batch des dossiers ASP par chassis (1 requete pour N VINs).
     *
     * @param list<string> $vins
     *
     * @return array<string, array<string, mixed>> cle = num_chassis, valeur = ligne ASP la plus recente
     */
    private function aspParChassis(array $vins): array
    {
        $placeholders = implode(',', array_fill(0, \count($vins), '?'));

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->defaultConnection->fetchAllAssociative(
            'SELECT DISTINCT ON (num_chassis) * '
            .'FROM bonus_eco.dossier_asp '
            ."WHERE num_chassis IN ($placeholders) "
            .'ORDER BY num_chassis, date_creation DESC NULLS LAST, id DESC',
            $vins,
        );

        $map = [];
        foreach ($rows as $row) {
            $chassis = isset($row['num_chassis']) ? (string) $row['num_chassis'] : '';
            if ('' !== $chassis) {
                $map[$chassis] = $row;
            }
        }

        return $map;
    }

    /**
     * Construit le WHERE Progiciel selon les filtres.
     *
     * Pour le filtre statut ASP (lib_etat) : on ne peut pas faire de JOIN
     * cross-base, donc on resout en amont la liste des VINs concernes cote
     * BDD locale, puis on filtre Progiciel par "numvin IN (...)".
     *
     * @param Filtres $filtres
     *
     * @return array{0: string, 1: list<string>}
     */
    private function whereSage(array $filtres): array
    {
        // Filtre comptable obligatoire : on ne traite que le compte 4432000
        // (bonus ecologique). 4432100 est un autre type d'aide.
        $where = [sprintf("codecompte = '%s'", self::CODECOMPTE_BONUS_ECO)];
        $params = [];

        if (!empty($filtres['codesoc'])) {
            $ph = implode(',', array_fill(0, \count($filtres['codesoc']), '?'));
            $where[] = "codesoc IN ($ph)";
            foreach ($filtres['codesoc'] as $v) {
                $params[] = $v;
            }
        }
        if (!empty($filtres['marque'])) {
            $ph = implode(',', array_fill(0, \count($filtres['marque']), '?'));
            $where[] = "trim(marque) IN ($ph)";
            foreach ($filtres['marque'] as $v) {
                $params[] = $v;
            }
        }
        if (!empty($filtres['retard'])) {
            $ph = implode(',', array_fill(0, \count($filtres['retard']), '?'));
            $where[] = "retard IN ($ph)";
            foreach ($filtres['retard'] as $v) {
                $params[] = $v;
            }
        }
        if (!empty($filtres['lib_etat'])) {
            $vins = $this->vinsParStatutAsp($filtres['lib_etat']);
            if ([] === $vins) {
                // Aucun VIN ne correspond -> retour vide garanti.
                $where[] = '1 = 0';
            } else {
                $ph = implode(',', array_fill(0, \count($vins), '?'));
                $where[] = "numvin IN ($ph)";
                foreach ($vins as $v) {
                    $params[] = $v;
                }
            }
        }
        if (!empty($filtres['recherche'])) {
            $needle = '%'.$filtres['recherche'].'%';
            $where[] = '(numvin ILIKE ? OR numimmat ILIKE ? OR nom ILIKE ? OR numpiece ILIKE ? OR reference ILIKE ?)';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Retourne la liste des num_chassis cote BDD locale dont le statut ASP
     * fait partie de la liste passee. Gere aussi le pseudo-statut '__sans__'
     * (= ecritures Progiciel sans dossier ASP : on renvoie tous les VINs PRESENTS
     * et le code appelant inverse via NOT IN).
     *
     * @param list<string> $libEtats
     *
     * @return list<string>
     */
    private function vinsParStatutAsp(array $libEtats): array
    {
        $ph = implode(',', array_fill(0, \count($libEtats), '?'));

        /** @var list<string> $vins */
        $vins = $this->defaultConnection->fetchFirstColumn(
            "SELECT DISTINCT num_chassis FROM bonus_eco.dossier_asp WHERE lib_etat IN ($ph)",
            $libEtats,
        );

        return $vins;
    }
}
