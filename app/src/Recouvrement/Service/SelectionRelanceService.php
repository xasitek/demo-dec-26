<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\RegleRelance;
use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Regle\CadenceRegle;
use App\Recouvrement\Regle\MoteurFiltre;
use App\Recouvrement\Repository\RegleRelanceRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Selectionne les comptes clients a relancer maintenant, GROUPES par compte, en
 * appliquant les REGLES DE RELANCE configurables (recouvrement.regle_relance).
 *
 * Pour chaque compte ayant au moins une facture echue positive dans v_impayes :
 *   1. on cherche la 1re REGLE active (par priorite) dont les filtres matchent une
 *      des factures echues du compte ; aucune regle -> compte ignore ;
 *   2. le NIVEAU vient de la cadence de la regle (delai initial + intervalle) selon
 *      le retard MAX du compte, avec demarrage doux (escalade d'un palier a la fois) ;
 *   3. GARDE-FOU ANTI-SPAM : on ne renvoie pas si le compte a deja recu une relance
 *      il y a moins de `intervalle` jours (au max une relance / compte / intervalle) ;
 *   4. idempotence (compte, niveau) via l'historique relance_envoi ;
 *   5. garde-fou avoirs : jamais de relance si le net (factures - avoirs) est <= 0.
 *
 * @phpstan-type FactureARelancer array{
 *     numpiece: ?string, reference: ?string, date_echeance: ?string, jours_retard: int,
 *     retard: ?string, montant: numeric-string, montant_ht: numeric-string,
 *     codeetab: ?string, marque: ?string,
 *     numimmat: ?string, numor: ?string, chemin_pdf: ?string, ecriture_id: ?string
 * }
 * @phpstan-type GroupeARelancer array{
 *     compte_code: string, regle_id: ?int, regle_nom: string, releve_seul: bool,
 *     niveau: int, mise_en_demeure: bool, seuil_med: int, email: ?string, adresse: ?string,
 *     vecteur: RelanceVecteur, destinataire_nom: string, formule_appel: string,
 *     total: numeric-string, total_brut: numeric-string, total_avoirs: numeric-string,
 *     total_ht: numeric-string,
 *     nb_factures: int, factures: list<FactureARelancer>, avoirs: list<FactureARelancer>,
 *     ecriture_id: ?string, reference_facture: ?string,
 *     releve: list<array{titulaire: string, banque: string, iban: string, bic: string, montant: numeric-string, factures: list<FactureARelancer>}>
 * }
 */
final class SelectionRelanceService
{
    /** @var list<array{0: RegleRelance, 1: CadenceRegle}>|null memo des regles actives + cadence */
    private ?array $reglesActives = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly RegleRelanceRepository $regles,
        private readonly MoteurFiltre $moteurFiltre,
        private readonly CacheInterface $cache,
        private readonly RibEtablissementProvider $ribProvider,
    ) {
    }

    /**
     * Comptes a relancer, regroupes (un groupe = un email/releve par compte).
     *
     * @param int|null $limit nombre maximal de GROUPES (comptes) retournes (null = tout)
     *
     * @return list<GroupeARelancer>
     */
    public function aRelancer(?int $limit = null, ?int $regleId = null): array
    {
        $resultat = [];
        $aujourdhui = new DateTimeImmutable('today');

        // Jeu de regles evalue : les regles actives, plus la regle ciblee si un
        // lancement manuel la vise (elle peut etre EN PAUSE : le bouton "Lancer
        // maintenant" doit fonctionner sur une strategie mise en pause).
        $regles = $this->reglesPourSelection($regleId);

        foreach ($this->lignesEchuesParCompte() as $lignesCompte) {
            $premiere = $lignesCompte[0];

            // Retard max + net + factures positives echues.
            $maxJoursRetard = 0;
            $nbFactures = 0;
            $net = '0';
            $lignesPositives = [];
            foreach ($lignesCompte as $ligne) {
                $montant = self::montantNumerique($ligne['montant_solde'] ?? null);
                $net = bcadd($net, $montant, 2);
                if (1 === bccomp($montant, '0', 2)) {
                    $maxJoursRetard = max($maxJoursRetard, (int) $ligne['jours_retard']);
                    ++$nbFactures;
                    $lignesPositives[] = $ligne;
                }
            }

            // Garde-fou : au moins une facture positive echue (les avoirs restent deduits).
            if (0 === $nbFactures) {
                continue;
            }

            // Regle applicable : 1re regle du jeu dont les filtres matchent une facture.
            $applicable = $this->regleApplicable($lignesPositives, $regles);
            if (null === $applicable) {
                continue;
            }
            [$regle, $cadence] = $applicable;

            // Garde-fou montant : on ne relance pas un net TTC sous le seuil de la regle
            // (0 = pas de seuil). La relance manuelle d'un compte n'est pas concernee.
            $montantMin = (string) $regle->getMontantMin();
            if (1 === bccomp($montantMin, '0', 2) && bccomp($net, $montantMin, 2) < 0) {
                continue;
            }

            // Niveau : cadence de la regle bornee par le retard, escalade sequentielle.
            $niveauTemps = $cadence->niveauPourRetard($maxJoursRetard);
            if (0 === $niveauTemps) {
                continue;
            }
            $niveauxEnvoyes = $this->parseNiveauxEnvoyes($premiere['niveaux_envoyes'] ?? null);
            $maxEnvoye = [] === $niveauxEnvoyes ? 0 : max($niveauxEnvoyes);
            $niveau = min($niveauTemps, $maxEnvoye + 1);
            if (\in_array($niveau, $niveauxEnvoyes, true)) {
                continue;
            }

            // Garde-fou anti-spam : pas deux relances au meme compte a moins de
            // `intervalle` jours d'ecart (evite le rattrapage de niveau en rafale).
            if ($this->relanceTropRecente($premiere['derniere_relance'] ?? null, $regle->getIntervalle(), $aujourdhui)) {
                continue;
            }

            $resultat[] = $this->construireGroupe($lignesCompte, $regle, $niveau);

            if (null !== $limit && \count($resultat) >= $limit) {
                break;
            }
        }

        return $resultat;
    }

    /**
     * Comptes RELANCABLES pour la relance MANUELLE : un compte par ligne (groupe),
     * matchant au moins une regle active, hors comptes ecartes. On ne filtre pas sur
     * la cadence (la comptable declenche quand elle veut). Filtres d'affichage
     * optionnels (ex. collectif, type de piece).
     *
     * @param list<array<string, mixed>>|null $filtresSupp filtres d'affichage additionnels
     *
     * @return list<array{compte_code: string, raison_sociale: ?string, nom: ?string,
     *     prenom: ?string, email: ?string, total: string, nb_factures: int,
     *     retard_max: int, niveau_max_envoye: ?int}>
     */
    public function comptesRelancables(?string $recherche = null, int $page = 1, int $parPage = 30, ?array $filtresSupp = null): array
    {
        $page = max(1, $page);
        $parPage = max(1, $parPage);
        [$where, $params, $types] = $this->filtreComptes($recherche);
        [$inClause, $paramsIn] = $this->contrainteRelancable($filtresSupp);
        $params = array_merge($params, $paramsIn);
        $params['limit'] = $parPage;
        $params['offset'] = ($page - 1) * $parPage;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $sql = <<<SQL
            SELECT v.compte AS compte_code,
                   max(v.raison_sociale) AS raison_sociale,
                   max(v.nom) AS nom,
                   max(v.prenom) AS prenom,
                   max(v.email) AS email,
                   sum(v.montant_solde) AS total,
                   count(*) FILTER (WHERE v.jours_retard > 0 AND v.montant_solde > 0) AS nb_factures,
                   max(v.jours_retard) FILTER (WHERE v.montant_solde > 0) AS retard_max,
                   max(rel.niveau_max) AS niveau_max_envoye
            FROM recouvrement.v_impayes v
            LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte
            LEFT JOIN (
                SELECT compte_code, max(niveau) AS niveau_max
                FROM recouvrement.relance_envoi WHERE statut = 'envoye' AND cycle_clos = false AND ecriture_id IS NULL
                GROUP BY compte_code
            ) rel ON rel.compte_code = v.compte
            WHERE (e.etat IS NULL OR e.etat <> 'ecarte')
              AND ((v.jours_retard > 0 AND v.montant_solde > 0) OR v.montant_solde < 0)
              AND {$inClause}
              {$where}
            GROUP BY v.compte
            HAVING sum(v.montant_solde) > 0
               AND count(*) FILTER (WHERE v.jours_retard > 0 AND v.montant_solde > 0) > 0
            ORDER BY retard_max DESC, total DESC
            LIMIT :limit OFFSET :offset
            SQL;

        /** @var list<array{compte_code: string, raison_sociale: ?string, nom: ?string, prenom: ?string, email: ?string, total: string, nb_factures: int|string, retard_max: int|string, niveau_max_envoye: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): array => [
            'compte_code' => (string) $r['compte_code'],
            'raison_sociale' => self::nullableString($r['raison_sociale'] ?? null),
            'nom' => self::nullableString($r['nom'] ?? null),
            'prenom' => self::nullableString($r['prenom'] ?? null),
            'email' => self::nullableString($r['email'] ?? null),
            'total' => self::montantNumerique($r['total']),
            'nb_factures' => (int) $r['nb_factures'],
            'retard_max' => (int) $r['retard_max'],
            'niveau_max_envoye' => null !== ($r['niveau_max_envoye'] ?? null) ? (int) $r['niveau_max_envoye'] : null,
        ], $rows);
    }

    /**
     * Nombre de comptes relancables (pour la pagination de la vue manuelle).
     *
     * @param list<array<string, mixed>>|null $filtresSupp
     */
    public function compterComptesRelancables(?string $recherche = null, ?array $filtresSupp = null): int
    {
        [$where, $params, $types] = $this->filtreComptes($recherche);
        [$inClause, $paramsIn] = $this->contrainteRelancable($filtresSupp);
        $params = array_merge($params, $paramsIn);

        $sql = <<<SQL
            SELECT count(*) FROM (
                SELECT v.compte
                FROM recouvrement.v_impayes v
                LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte
                WHERE (e.etat IS NULL OR e.etat <> 'ecarte')
                  AND ((v.jours_retard > 0 AND v.montant_solde > 0) OR v.montant_solde < 0)
                  AND {$inClause}
                  {$where}
                GROUP BY v.compte
                HAVING sum(v.montant_solde) > 0
                   AND count(*) FILTER (WHERE v.jours_retard > 0 AND v.montant_solde > 0) > 0
            ) x
            SQL;

        return (int) $this->connection->fetchOne($sql, $params, $types);
    }

    /**
     * Valeurs distinctes disponibles pour filtrer les listes (collectifs, types de
     * piece, etablissements presents dans v_impayes), triees.
     *
     * @return array{collectifs: list<string>, types_piece: list<string>, etablissements: list<string>}
     */
    public function optionsFiltres(): array
    {
        // Trois DISTINCT sur la vue materialisee complete (~100k lignes) : couteux et
        // quasi-statique (ne bouge qu'au REFRESH nocturne de v_impayes). Cache 10 min.
        return $this->cache->get('recouvrement_options_filtres', function (ItemInterface $item): array {
            $item->expiresAfter(600);

            /** @var list<string> $collectifs */
            $collectifs = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT collectif FROM recouvrement.v_impayes WHERE collectif IS NOT NULL AND collectif <> '' ORDER BY collectif",
            );
            /** @var list<string> $types */
            $types = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT type_piece FROM recouvrement.v_impayes WHERE type_piece IS NOT NULL AND type_piece <> '' ORDER BY type_piece",
            );
            /** @var list<string> $etabs */
            $etabs = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT codeetab FROM recouvrement.v_impayes WHERE codeetab IS NOT NULL AND codeetab <> '' ORDER BY codeetab",
            );

            return [
                'collectifs' => array_map(static fn (mixed $v): string => (string) $v, $collectifs),
                'types_piece' => array_map(static fn (mixed $v): string => (string) $v, $types),
                'etablissements' => array_map(static fn (mixed $v): string => (string) $v, $etabs),
            ];
        });
    }

    /**
     * Options (code + libellé) pour chaque champ CATÉGORIEL du constructeur de filtres :
     * les valeurs réellement présentes dans v_impayes, enrichies du libellé métier quand
     * il est connu (MoteurFiltre::LIBELLES_VALEURS). Sert à peupler les listes déroulantes
     * de valeurs. DISTINCT sur la vue matérialisée -> caché 10 min (comme optionsFiltres).
     *
     * @return array<string, list<array{code: string, libelle: string}>>
     */
    public function optionsValeursChamps(): array
    {
        return $this->cache->get('recouvrement_options_valeurs_champs', function (ItemInterface $item): array {
            $item->expiresAfter(600);

            $resultat = [];
            foreach (MoteurFiltre::CHAMPS_CATEGORIELS as $champ) {
                // Colonne issue de la liste blanche CHAMPS -> interpolation sûre.
                $colonne = MoteurFiltre::CHAMPS[$champ]['colonne'];
                /** @var list<string> $valeurs */
                $valeurs = $this->connection->fetchFirstColumn(sprintf(
                    "SELECT DISTINCT %1\$s FROM recouvrement.v_impayes WHERE %1\$s IS NOT NULL AND btrim(%1\$s::text) <> '' ORDER BY 1",
                    $colonne,
                ));

                $libelles = MoteurFiltre::LIBELLES_VALEURS[$champ] ?? [];
                $resultat[$champ] = array_map(static function (mixed $v) use ($libelles): array {
                    $code = (string) $v;

                    return [
                        'code' => $code,
                        'libelle' => $libelles[$code] ?? ('?' === $code ? 'Non renseigné' : $code),
                    ];
                }, $valeurs);
            }

            return $resultat;
        });
    }

    /**
     * Groupe d'envoi pour UN compte (relance manuelle). Niveau AUTO = palier suivant
     * a envoyer ; si $forcerMed, on impose le niveau de mise en demeure de la regle.
     * Renvoie null si le compte n'a plus de facture echue eligible ou ne matche
     * aucune regle active.
     *
     * @return GroupeARelancer|null
     */
    public function groupePourCompte(string $compteCode, bool $forcerMed = false): ?array
    {
        $lignes = $this->lignesEchuesDuCompte($compteCode);
        if ([] === $lignes) {
            return null;
        }

        $lignesPositives = array_values(array_filter(
            $lignes,
            static fn (array $l): bool => 1 === bccomp(self::montantNumerique($l['montant_solde'] ?? null), '0', 2),
        ));
        $applicable = $this->regleApplicable($lignesPositives, $this->reglesActives());
        if (null === $applicable) {
            return null;
        }
        [$regle, $cadence] = $applicable;

        $niveau = $this->niveauManuel($compteCode, null, $cadence, $forcerMed);
        $groupe = $this->construireGroupe($lignes, $regle, $niveau);

        if (0 === $groupe['nb_factures'] || bccomp($groupe['total'], '0', 2) <= 0) {
            return null;
        }

        return $groupe;
    }

    /**
     * Groupe d'envoi pour UNE facture (relance ciblee, manuelle). Niveau AUTO ou MED
     * forcee. Renvoie null si la facture n'est plus eligible ou compte non couvert.
     *
     * @return GroupeARelancer|null
     */
    public function groupePourFacture(string $ecritureId, bool $forcerMed = false): ?array
    {
        $ligne = $this->ligneEchueParEcriture($ecritureId);
        if (null === $ligne) {
            return null;
        }

        if (bccomp($this->netCompte((string) $ligne['compte']), '0', 2) <= 0) {
            return null;
        }

        $applicable = $this->regleApplicable([$ligne], $this->reglesActives());
        if (null === $applicable) {
            return null;
        }
        [$regle, $cadence] = $applicable;

        $niveau = $this->niveauManuel((string) $ligne['compte'], $ecritureId, $cadence, $forcerMed);

        return $this->construireGroupe(
            [$ligne],
            $regle,
            $niveau,
            (string) $ligne['ecriture_id'],
            self::nullableString($ligne['reference_facture'] ?? null),
        );
    }

    /**
     * Groupe d'envoi MANUEL avec le niveau CHOISI par le comptable (1, 2 ou mise en
     * demeure), INDEPENDANT des strategies : si aucune ne matche, on relance quand
     * meme avec un rendu par defaut (releve + PDF des factures). Cible = compte entier
     * (releve) ou une facture. Renvoie null s'il n'y a plus rien de du (soldé, ecarte,
     * net <= 0, ou piece non facturable type OD).
     *
     * @return GroupeARelancer|null
     */
    public function groupeManuel(?string $compteCode, ?string $ecritureId, int $niveau, bool $med): ?array
    {
        if (null !== $ecritureId) {
            $ligne = $this->ligneEchueParEcriture($ecritureId);
            if (null === $ligne || bccomp($this->netCompte((string) $ligne['compte']), '0', 2) <= 0) {
                return null;
            }
            $groupe = $this->construireGroupe(
                [$ligne],
                self::regleManuelleParDefaut(),
                $niveau,
                (string) $ligne['ecriture_id'],
                self::nullableString($ligne['reference_facture'] ?? null),
                $med,
            );
        } else {
            $lignes = $this->lignesEchuesDuCompte((string) $compteCode);
            if ([] === $lignes) {
                return null;
            }
            $groupe = $this->construireGroupe($lignes, self::regleManuelleParDefaut(), $niveau, null, null, $med);
        }

        if (0 === $groupe['nb_factures'] || bccomp($groupe['total'], '0', 2) <= 0) {
            return null;
        }

        return $groupe;
    }

    /**
     * Regle "par defaut" d'une relance manuelle sans strategie : releve + PDF des
     * factures. Jamais persistee (id null) ; sert uniquement a alimenter le rendu.
     */
    private static function regleManuelleParDefaut(): RegleRelance
    {
        return new RegleRelance('Relance manuelle');
    }

    /**
     * Niveau d'une relance MANUELLE : mise en demeure forcee, ou palier suivant
     * (max deja envoye + 1, minimum 1). Cible = compte (releve) ou facture (ecriture).
     */
    private function niveauManuel(string $compteCode, ?string $ecritureId, CadenceRegle $cadence, bool $forcerMed): int
    {
        if ($forcerMed) {
            return $cadence->niveauMiseEnDemeure();
        }

        if (null !== $ecritureId) {
            $max = $this->connection->fetchOne(
                "SELECT max(niveau) FROM recouvrement.relance_envoi WHERE ecriture_id = :e AND statut = 'envoye' AND cycle_clos = false",
                ['e' => $ecritureId],
            );
        } else {
            $max = $this->connection->fetchOne(
                "SELECT max(niveau) FROM recouvrement.relance_envoi WHERE compte_code = :c AND ecriture_id IS NULL AND statut = 'envoye' AND cycle_clos = false",
                ['c' => $compteCode],
            );
        }

        return (false === $max || null === $max ? 0 : (int) $max) + 1;
    }

    /**
     * Regles actives (memo) avec leur cadence, dans l'ordre de priorite.
     *
     * @return list<array{0: RegleRelance, 1: CadenceRegle}>
     */
    private function reglesActives(): array
    {
        if (null === $this->reglesActives) {
            $this->reglesActives = [];
            foreach ($this->regles->findActivesOrdonnees() as $regle) {
                $this->reglesActives[] = [$regle, CadenceRegle::depuisRegle($regle)];
            }
        }

        return $this->reglesActives;
    }

    /**
     * Codes etablissement exclus des relances = valeurs des filtres `codeetab notin`
     * des regles actives (etablissements CEDES : societes vendues avec leurs dettes,
     * les creances ne sont plus a nous). Gere depuis la vue Strategies. Applique au
     * niveau LIGNE (pas seulement au gating du compte) pour qu'une dette cedee ne
     * figure JAMAIS dans le releve, meme sur un compte mixte (cede + autre).
     *
     * @return array<string, true>
     */
    private function etablissementsExclus(): array
    {
        $codes = [];
        foreach ($this->reglesActives() as $couple) {
            foreach ($couple[0]->getFiltres() as $filtre) {
                if ('codeetab' !== $filtre['champ'] || 'notin' !== $filtre['operateur']) {
                    continue;
                }
                $valeurs = $filtre['valeur'] ?? [];
                foreach (\is_array($valeurs) ? $valeurs : [$valeurs] as $v) {
                    $code = trim((string) $v);
                    if ('' !== $code) {
                        $codes[$code] = true;
                    }
                }
            }
        }

        return $codes;
    }

    /**
     * Retire les lignes des etablissements cedes.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array<string, mixed>>
     */
    private function sansEtablissementsExclus(array $lignes): array
    {
        $exclus = $this->etablissementsExclus();
        if ([] === $exclus) {
            return $lignes;
        }

        return array_values(array_filter(
            $lignes,
            static fn (array $l): bool => !isset($exclus[trim((string) ($l['codeetab'] ?? ''))]),
        ));
    }

    /**
     * 1re regle du jeu fourni dont les filtres matchent une des factures positives
     * du compte (ordre de priorite).
     *
     * @param list<array<string, mixed>>                    $lignesPositives
     * @param list<array{0: RegleRelance, 1: CadenceRegle}> $regles
     *
     * @return array{0: RegleRelance, 1: CadenceRegle}|null
     */
    private function regleApplicable(array $lignesPositives, array $regles): ?array
    {
        foreach ($regles as $couple) {
            $filtres = $couple[0]->getFiltres();
            foreach ($lignesPositives as $ligne) {
                if ($this->moteurFiltre->correspondLigne($ligne, $filtres)) {
                    return $couple;
                }
            }
        }

        return null;
    }

    /**
     * Jeu de regles a evaluer pour une selection : les regles actives, plus la
     * regle ciblee par un lancement manuel si elle n'est pas deja active (elle peut
     * etre EN PAUSE). L'ordre de priorite est preserve. Renvoie une COPIE locale :
     * le cache partage reglesActives() n'est pas modifie (pas de fuite entre runs).
     *
     * @return list<array{0: RegleRelance, 1: CadenceRegle}>
     */
    private function reglesPourSelection(?int $regleId): array
    {
        if (null === $regleId) {
            return $this->reglesActives();
        }

        // Lancement cible ("Lancer maintenant") : la strategie est evaluee SEULE, sur
        // son propre perimetre, meme si elle est en pause -> pas de mise en concurrence
        // avec une regle active plus prioritaire qui lui volerait ses comptes.
        foreach ($this->reglesActives() as $couple) {
            if ($couple[0]->getId() === $regleId) {
                return [$couple]; // reutilise la cadence deja memoisee
            }
        }

        $regle = $this->regles->find($regleId);
        if (null === $regle) {
            return []; // regle introuvable -> aucun compte a relancer
        }

        return [[$regle, CadenceRegle::depuisRegle($regle)]];
    }

    /**
     * Contrainte SQL "compte relancable" : le compte a au moins une facture echue
     * positive matchant une regle active (+ filtres d'affichage optionnels). Renvoie
     * la clause `v.compte IN (...)` + ses parametres.
     *
     * @param list<array<string, mixed>>|null $filtresSupp
     *
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function contrainteRelancable(?array $filtresSupp): array
    {
        $filtresActifs = [];
        foreach ($this->reglesActives() as $couple) {
            $filtresActifs[] = $couple[0]->getFiltres();
        }
        [$union, $params] = $this->moteurFiltre->versSqlUnion($filtresActifs);

        $extra = '';
        if (null !== $filtresSupp && [] !== $filtresSupp) {
            [$whereSupp, $paramsSupp] = $this->moteurFiltre->versSql($filtresSupp);
            if ('TRUE' !== $whereSupp) {
                $extra = ' AND ('.$whereSupp.')';
                $params = array_merge($params, $paramsSupp);
            }
        }

        $inClause = 'v.compte IN (SELECT v.compte FROM recouvrement.v_impayes v '
            ."WHERE v.jours_retard > 0 AND v.montant_solde > 0 AND ({$union}){$extra})";

        return [$inClause, $params];
    }

    /**
     * Contrainte SQL "compte dans le PERIMETRE de la strategie {regleId}", pour le
     * filtre "Strategie" de la liste Clients : compte ayant au moins une facture
     * echue positive matchant les filtres de cette regle (active OU en pause).
     * Renvoie [clause `mv_annuaire_clients.compte IN (...)`, params] ou null si la
     * regle est introuvable.
     *
     * @return array{0: string, 1: array<string, scalar>}|null
     */
    public function contrainteStrategie(int $regleId): ?array
    {
        $regle = $this->regles->find($regleId);
        if (null === $regle) {
            return null;
        }

        [$where, $params] = $this->moteurFiltre->versSql($regle->getFiltres());
        $clause = 'mv_annuaire_clients.compte IN (SELECT v.compte FROM recouvrement.v_impayes v '
            ."WHERE v.jours_retard > 0 AND v.montant_solde > 0 AND ({$where}))";

        return [$clause, $params];
    }

    /**
     * Strategies (id => nom) dans l'ordre d'evaluation, pour alimenter le filtre
     * "Strategie" de la liste Clients. Inclut les strategies en pause.
     *
     * @return array<int, string>
     */
    public function listerStrategies(): array
    {
        $out = [];
        foreach ($this->regles->findBy([], ['priorite' => 'ASC']) as $regle) {
            $id = $regle->getId();
            if (null !== $id) {
                $out[$id] = $regle->getNom();
            }
        }

        return $out;
    }

    /**
     * Vrai si la derniere relance envoyee au compte est trop recente (< intervalle
     * jours) -> on ne relance pas maintenant (anti-spam).
     */
    private function relanceTropRecente(mixed $derniereRelance, int $intervalle, DateTimeImmutable $aujourdhui): bool
    {
        if (null === $derniereRelance || '' === (string) $derniereRelance || $intervalle <= 0) {
            return false;
        }

        $date = date_create_immutable((string) $derniereRelance);
        if (false === $date) {
            return false;
        }

        // Comparaison en jours CALENDAIRES (minuit) : indépendante de l'heure d'envoi,
        // sinon un envoi à 15h décalerait toute la cadence d'un jour.
        return $aujourdhui->getTimestamp() - $date->setTime(0, 0, 0)->getTimestamp() < $intervalle * 86400;
    }

    /**
     * Fabrique un GroupeARelancer a partir des lignes d'un compte et d'une regle.
     *
     * @param non-empty-list<array<string, mixed>> $lignesCompte
     *
     * @return GroupeARelancer
     */
    private function construireGroupe(
        array $lignesCompte,
        RegleRelance $regle,
        int $niveau,
        ?string $ecritureId = null,
        ?string $reference = null,
        ?bool $medForce = null,
    ): array {
        $premiere = $lignesCompte[0];
        $cadence = CadenceRegle::depuisRegle($regle);

        $factures = [];
        $avoirs = [];
        foreach ($lignesCompte as $ligne) {
            $montant = self::montantNumerique($ligne['montant_solde'] ?? null);
            $item = [
                'numpiece' => self::nullableString($ligne['numpiece'] ?? null),
                'reference' => self::nullableString($ligne['reference_facture'] ?? null),
                'date_echeance' => self::nullableString($ligne['date_echeance'] ?? null),
                'jours_retard' => (int) $ligne['jours_retard'],
                'retard' => self::nullableString($ligne['retard'] ?? null),
                'montant' => $montant,
                'montant_ht' => self::montantNumerique($ligne['montant_ht'] ?? null),
                'codeetab' => self::nullableString($ligne['codeetab'] ?? null),
                'marque' => self::nullableString($ligne['marque'] ?? null),
                'numimmat' => self::nullableString($ligne['numimmat'] ?? null),
                'numor' => self::nullableString($ligne['numor'] ?? null),
                'chemin_pdf' => self::nullableString($ligne['chemin_pdf'] ?? null),
                'ecriture_id' => self::nullableString($ligne['ecriture_id'] ?? null),
            ];
            if (-1 === bccomp($montant, '0', 2)) {
                $avoirs[] = $item;
            } elseif (1 === bccomp(self::montantNumerique($ligne['montant_initial_facturation'] ?? null), '0', 2)) {
                // Facture réellement due (montant initial facturation > 0). Les lignes
                // positives techniques (OD/RDE à montant facturation nul) sont exclues
                // du relevé ET du total réclamé (garde-fou "tous types de pièce").
                $factures[] = $item;
            }
        }

        // Regroupement par etablissement (compte bancaire SYNTHAUTO) en NET, puis mise a
        // l'ecart des etablissements a solde NEGATIF (client net crediteur pour cet
        // etablissement) : ils ne doivent apparaitre NULLE PART — ni releve, ni piece
        // jointe, ni lien de telechargement, ni courrier. On recalcule donc factures,
        // avoirs, releve et totaux a partir des seuls etablissements retenus.
        [$releve, $factures, $avoirs] = $this->construireReleve($factures, $avoirs);

        $totalBrut = '0';
        $totalHt = '0';
        foreach ($factures as $f) {
            $totalBrut = bcadd($totalBrut, $f['montant'], 2);
            $totalHt = bcadd($totalHt, $f['montant_ht'], 2);
        }
        $totalAvoirs = '0';
        foreach ($avoirs as $a) {
            $totalAvoirs = bcadd($totalAvoirs, bcmul($a['montant'], '-1', 2), 2);
            $totalHt = bcadd($totalHt, $a['montant_ht'], 2);
        }
        $total = bcsub($totalBrut, $totalAvoirs, 2);

        $email = self::nullableString($premiere['email'] ?? null);

        return [
            'compte_code' => (string) $premiere['compte'],
            'regle_id' => $regle->getId(),
            'regle_nom' => $regle->getNom(),
            'releve_seul' => $regle->isReleveSeul(),
            'niveau' => $niveau,
            'mise_en_demeure' => $medForce ?? $cadence->estMiseEnDemeure($niveau),
            'seuil_med' => $regle->getSeuilMed(),
            'email' => $email,
            'adresse' => self::nullableString($premiere['adresse'] ?? null),
            'vecteur' => null !== $email ? RelanceVecteur::EMAIL : RelanceVecteur::COURRIER,
            'destinataire_nom' => $this->composerNom($premiere),
            'formule_appel' => $this->composerFormuleAppel($premiere),
            'total' => $total,
            'total_brut' => $totalBrut,
            'total_avoirs' => $totalAvoirs,
            'total_ht' => $totalHt,
            'nb_factures' => \count($factures),
            'factures' => $factures,
            'avoirs' => $avoirs,
            'ecriture_id' => $ecritureId,
            'reference_facture' => $reference,
            'releve' => $releve,
        ];
    }

    /**
     * Regroupe factures + avoirs par compte bancaire SYNTHAUTO (IBAN), calcule le NET de
     * chaque groupe, puis ECARTE les etablissements a solde NET negatif (client net
     * crediteur : rien a reclamer pour cet etablissement -> on zappe). Plusieurs
     * etablissements partagent souvent le meme compte (regroupement par IBAN). Les
     * factures sans RIB connu forment un groupe sans coordonnees (iban vide), place
     * en dernier ; les groupes avec RIB sont tries par montant decroissant.
     *
     * Retourne un triplet [releve affichable, factures retenues, avoirs retenus] :
     * les factures/avoirs des etablissements ecartes sont exclus PARTOUT (releve,
     * piece jointe, fusion, lien de telechargement, courrier).
     *
     * @param list<FactureARelancer> $factures
     * @param list<FactureARelancer> $avoirs
     *
     * @return array{0: list<array{titulaire: string, banque: string, iban: string, bic: string, montant: numeric-string, factures: list<FactureARelancer>}>, 1: list<FactureARelancer>, 2: list<FactureARelancer>}
     */
    private function construireReleve(array $factures, array $avoirs): array
    {
        $sansRibCle = '__sans_rib__';
        /** @var array<string, array{titulaire: string, banque: string, iban: string, bic: string, montant: numeric-string, factures: list<FactureARelancer>}> $groupes */
        $groupes = [];
        /** @var array<string, list<FactureARelancer>> $facturesParCle */
        $facturesParCle = [];
        /** @var array<string, list<FactureARelancer>> $avoirsParCle */
        $avoirsParCle = [];

        // Factures puis avoirs : chaque avoir tombe dans le groupe de son
        // etablissement, dont le sous-total (montant) devient donc le NET.
        foreach ($factures as $facture) {
            [$cle, $entete] = $this->cleGroupe($facture['codeetab'] ?? null, $sansRibCle);
            if (!isset($groupes[$cle])) {
                $groupes[$cle] = ['titulaire' => $entete['titulaire'], 'banque' => $entete['banque'], 'iban' => $entete['iban'], 'bic' => $entete['bic'], 'montant' => '0', 'factures' => []];
                $facturesParCle[$cle] = [];
                $avoirsParCle[$cle] = [];
            }
            $groupes[$cle]['factures'][] = $facture;
            $groupes[$cle]['montant'] = bcadd($groupes[$cle]['montant'], $facture['montant'], 2);
            $facturesParCle[$cle][] = $facture;
        }
        foreach ($avoirs as $avoir) {
            [$cle, $entete] = $this->cleGroupe($avoir['codeetab'] ?? null, $sansRibCle);
            if (!isset($groupes[$cle])) {
                $groupes[$cle] = ['titulaire' => $entete['titulaire'], 'banque' => $entete['banque'], 'iban' => $entete['iban'], 'bic' => $entete['bic'], 'montant' => '0', 'factures' => []];
                $facturesParCle[$cle] = [];
                $avoirsParCle[$cle] = [];
            }
            $groupes[$cle]['factures'][] = $avoir;
            $groupes[$cle]['montant'] = bcadd($groupes[$cle]['montant'], $avoir['montant'], 2);
            $avoirsParCle[$cle][] = $avoir;
        }

        $avecRib = [];
        $groupeSansRib = null;
        $facturesRetenues = [];
        $avoirsRetenus = [];
        foreach ($groupes as $cle => $groupe) {
            // Etablissement a solde NET negatif -> ecarte partout. Un etablissement
            // dont le net reste >= 0 (meme avec des avoirs) est conserve integralement.
            if (-1 === bccomp($groupe['montant'], '0', 2)) {
                continue;
            }
            foreach ($facturesParCle[$cle] as $f) {
                $facturesRetenues[] = $f;
            }
            foreach ($avoirsParCle[$cle] as $a) {
                $avoirsRetenus[] = $a;
            }
            if ($sansRibCle === $cle) {
                $groupeSansRib = $groupe;
            } else {
                $avecRib[] = $groupe;
            }
        }

        usort($avecRib, static fn (array $a, array $b): int => bccomp($b['montant'], $a['montant'], 2));
        if (null !== $groupeSansRib) {
            $avecRib[] = $groupeSansRib;
        }

        return [$avecRib, $facturesRetenues, $avoirsRetenus];
    }

    /**
     * Clé de regroupement bancaire (IBAN) et en-tête (titulaire/banque/IBAN/BIC) pour
     * un code établissement. Sans RIB connu -> clé "sans RIB" et en-tête vide.
     *
     * @return array{0: string, 1: array{titulaire: string, banque: string, iban: string, bic: string}}
     */
    private function cleGroupe(?string $codeetab, string $sansRibCle): array
    {
        $rib = $this->ribProvider->pour($codeetab);
        if (null !== $rib && '' !== $rib['iban']) {
            return [$rib['iban'], ['titulaire' => $rib['titulaire'], 'banque' => $rib['banque'], 'iban' => $rib['iban'], 'bic' => $rib['bic']]];
        }

        return [$sansRibCle, ['titulaire' => '', 'banque' => '', 'iban' => '', 'bic' => '']];
    }

    /**
     * Clause WHERE de recherche pour la liste des comptes relancables.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function filtreComptes(?string $recherche): array
    {
        $recherche = null !== $recherche ? trim($recherche) : '';
        if ('' === $recherche) {
            return ['', [], []];
        }

        return [
            'AND (lower(v.compte) LIKE :q OR lower(v.email) LIKE :q '
                .'OR lower(v.raison_sociale) LIKE :q OR lower(v.nom) LIKE :q)',
            ['q' => '%'.strtolower($recherche).'%'],
            [],
        ];
    }

    /**
     * Lignes (factures) echues d'un seul compte, hors compte ecarte.
     *
     * @return list<array<string, mixed>>
     */
    private function lignesEchuesDuCompte(string $compte): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.ecriture_id, v.compte, v.reference_facture, v.numpiece, v.montant_solde, '
            .'v.date_echeance, v.jours_retard, v.retard, v.email, v.civilite, v.nom, v.prenom, '
            .'v.raison_sociale, v.adresse, v.codeetab, v.marque, v.numimmat, v.numor, v.chemin_pdf, '
            .'v.collectif, v.type_piece, v.montant_initial, v.montant_initial_facturation, v.montant_ht, '
            .'v.statut_juridique, v.code_statut, v.code_conditions_reglement, v.code_mode_paiement, v.type_compte '
            .'FROM recouvrement.v_impayes v '
            .'LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte '
            .'WHERE v.compte = :compte '
            .'AND ((v.jours_retard > 0 AND v.montant_solde > 0) OR v.montant_solde < 0) '
            ."AND (e.etat IS NULL OR e.etat <> 'ecarte') "
            // Gel niveau facture : on exclut toute facture transferee au site / mise en pause.
            .'AND NOT EXISTS (SELECT 1 FROM recouvrement.facture_site fs WHERE fs.ecriture_id = v.ecriture_id AND fs.actif = true) '
            .'ORDER BY (v.montant_solde > 0) DESC, v.jours_retard DESC, v.montant_solde DESC',
            ['compte' => $compte],
        );

        return $this->sansEtablissementsExclus($rows);
    }

    /**
     * Net du compte pour le garde-fou avoirs.
     *
     * @return numeric-string
     */
    private function netCompte(string $compte): string
    {
        // Net calcule sur les lignes deja filtrees (hors comptes ecartes ET
        // etablissements cedes), pour rester coherent avec ce qui est relance.
        $net = '0';
        foreach ($this->lignesEchuesDuCompte($compte) as $ligne) {
            $net = bcadd($net, self::montantNumerique($ligne['montant_solde'] ?? null), 2);
        }

        return $net;
    }

    /**
     * Une facture echue par sa cle ecriture (relance ciblee), hors compte ecarte.
     *
     * @return array<string, mixed>|null
     */
    private function ligneEchueParEcriture(string $ecritureId): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT v.ecriture_id, v.compte, v.reference_facture, v.numpiece, v.montant_solde, '
            .'v.date_echeance, v.jours_retard, v.retard, v.email, v.civilite, v.nom, v.prenom, '
            .'v.raison_sociale, v.adresse, v.codeetab, v.marque, v.numimmat, v.numor, v.chemin_pdf, '
            .'v.collectif, v.type_piece, v.montant_initial, v.montant_initial_facturation, v.montant_ht, '
            .'v.statut_juridique, v.code_statut, v.code_conditions_reglement, v.code_mode_paiement, v.type_compte '
            .'FROM recouvrement.v_impayes v '
            .'LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte '
            .'WHERE v.ecriture_id = :ecriture AND v.montant_solde > 0 AND v.jours_retard > 0 '
            ."AND (e.etat IS NULL OR e.etat <> 'ecarte') "
            // Gel niveau facture : une facture gelee (site / pause) n'est pas relancable, meme en cible.
            .'AND NOT EXISTS (SELECT 1 FROM recouvrement.facture_site fs WHERE fs.ecriture_id = v.ecriture_id AND fs.actif = true) '
            .'LIMIT 1',
            ['ecriture' => $ecritureId],
        );

        if (false === $row || isset($this->etablissementsExclus()[trim((string) ($row['codeetab'] ?? ''))])) {
            return null;
        }

        return $row;
    }

    /**
     * Factures echues regroupees par compte, avec niveaux deja relances et date de
     * la derniere relance (pour le garde-fou anti-spam), ordonnees par compte.
     *
     * @return list<non-empty-list<array<string, mixed>>>
     */
    private function lignesEchuesParCompte(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                v.ecriture_id, v.compte, v.reference_facture, v.numpiece, v.montant_solde,
                v.date_echeance, v.jours_retard, v.retard, v.email, v.civilite, v.nom, v.prenom,
                v.raison_sociale, v.adresse, v.codeetab, v.marque, v.numimmat, v.numor, v.chemin_pdf,
                v.collectif, v.type_piece, v.montant_initial, v.montant_initial_facturation, v.montant_ht,
                v.statut_juridique, v.code_statut, v.code_conditions_reglement, v.code_mode_paiement, v.type_compte,
                r.niveaux_envoyes, r.derniere_relance
            FROM recouvrement.v_impayes v
            LEFT JOIN (
                SELECT compte_code, array_agg(niveau) AS niveaux_envoyes, max(envoye_le) AS derniere_relance
                FROM recouvrement.relance_envoi
                WHERE statut = 'envoye' AND cycle_clos = false AND ecriture_id IS NULL
                GROUP BY compte_code
            ) r ON r.compte_code = v.compte
            LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte
            WHERE v.ecriture_id IS NOT NULL
              AND ((v.jours_retard > 0 AND v.montant_solde > 0) OR v.montant_solde < 0)
              AND (e.etat IS NULL OR e.etat <> 'ecarte')
              AND NOT EXISTS (SELECT 1 FROM recouvrement.facture_site fs WHERE fs.ecriture_id = v.ecriture_id AND fs.actif = true)
            ORDER BY v.compte ASC, (v.montant_solde > 0) DESC, v.jours_retard DESC, v.montant_solde DESC
        SQL);

        $groupes = [];
        foreach ($this->sansEtablissementsExclus($rows) as $row) {
            $compte = (string) $row['compte'];
            $groupes[$compte][] = $row;
        }

        return array_values($groupes);
    }

    /**
     * Convertit l'agregat Postgres array_agg (ex. "{1,2}") en liste d'entiers.
     *
     * @return list<int>
     */
    private function parseNiveauxEnvoyes(mixed $brut): array
    {
        if (null === $brut) {
            return [];
        }

        if (\is_array($brut)) {
            $valeurs = [];
            foreach ($brut as $v) {
                $valeurs[] = (int) $v;
            }

            return $valeurs;
        }

        $texte = trim((string) $brut, '{}');
        if ('' === $texte) {
            return [];
        }

        $valeurs = [];
        foreach (explode(',', $texte) as $v) {
            $valeurs[] = (int) trim($v);
        }

        return $valeurs;
    }

    /**
     * Nom d'affichage du destinataire.
     *
     * @param array<string, mixed> $ligne
     */
    private function composerNom(array $ligne): string
    {
        $raisonSociale = trim((string) ($ligne['raison_sociale'] ?? ''));
        if ('' !== $raisonSociale) {
            return $raisonSociale;
        }

        $prenom = trim((string) ($ligne['prenom'] ?? ''));
        $nom = trim((string) ($ligne['nom'] ?? ''));

        return trim($prenom.' '.$nom);
    }

    /**
     * Formule d'appel de l'email (repli "Madame, Monsieur").
     *
     * @param array<string, mixed> $ligne
     */
    private function composerFormuleAppel(array $ligne): string
    {
        $code = strtoupper(trim((string) ($ligne['civilite'] ?? '')));

        return match ($code) {
            'M', 'MR', 'MONSIEUR', '1' => 'Monsieur',
            'MME', 'MADAME', '2' => 'Madame',
            'MLLE', 'MADEMOISELLE' => 'Madame',
            default => 'Madame, Monsieur',
        };
    }

    private static function nullableString(mixed $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = (string) $valeur;

        return '' === $texte ? null : $texte;
    }

    /**
     * @return numeric-string
     */
    private static function montantNumerique(mixed $valeur): string
    {
        $texte = trim((string) $valeur);

        return is_numeric($texte) ? $texte : '0';
    }
}
