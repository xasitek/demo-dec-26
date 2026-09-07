<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Recherche/consultation de l'ANNUAIRE CLIENTS (vue "Clients"), servie par la
 * projection materialisee recouvrement.mv_annuaire_clients (une ligne par client).
 *
 * Recherche plein-texte tolerante (index trigramme sur `recherche` : compte, raison
 * sociale, nom/prenom, e-mail, SIREN, numeros de facture). Tri par retard puis encours
 * (les comptes a traiter remontent ; les clients a jour restent en bas). Filtres
 * optionnels collectif / etablissement / statut de curation.
 */
final class AnnuaireClientService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string>                                    $collectifs
     * @param list<string>                                    $etabs
     * @param array{0: string, 1: array<string, scalar>}|null $contrainteStrategie
     *
     * @return list<array{
     *     compte: string, client: string, email: ?string, telephone: ?string,
     *     siren: ?string, ville: ?string, encours: string, echu: string,
     *     retard_max: int, nb_factures: int, codeetab: ?string, collectif: ?string,
     *     niveau_max_envoye: ?int, ecarte: bool, nb_gelees: int
     * }>
     */
    public function rechercher(?string $q, array $collectifs, array $etabs, ?string $statut, int $page, int $parPage, bool $horsRelance = false, ?array $contrainteStrategie = null): array
    {
        [$where, $params, $types] = $this->filtres($q, $collectifs, $etabs, $statut, $horsRelance, $contrainteStrategie);
        $params['limit'] = max(1, $parPage);
        $params['offset'] = (max(1, $page) - 1) * max(1, $parPage);
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $sql = 'SELECT compte, client, email, telephone, siren, ville, encours, echu, '
            .'retard_max, nb_factures, codeetab, collectif, niveau_max_envoye, ecarte, '
            .'(SELECT count(*) FROM recouvrement.facture_site fs WHERE fs.compte_code = mv_annuaire_clients.compte AND fs.actif = true) AS nb_gelees '
            .'FROM recouvrement.mv_annuaire_clients '
            .'WHERE '.$where.' '
            .'ORDER BY retard_max DESC, encours DESC, compte '
            .'LIMIT :limit OFFSET :offset';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): array => [
            'compte' => (string) $r['compte'],
            'client' => (string) $r['client'],
            'email' => self::txt($r['email'] ?? null),
            'telephone' => self::txt($r['telephone'] ?? null),
            'siren' => self::txt($r['siren'] ?? null),
            'ville' => self::txt($r['ville'] ?? null),
            'encours' => (string) ($r['encours'] ?? '0'),
            'echu' => (string) ($r['echu'] ?? '0'),
            'retard_max' => (int) ($r['retard_max'] ?? 0),
            'nb_factures' => (int) ($r['nb_factures'] ?? 0),
            'codeetab' => self::txt($r['codeetab'] ?? null),
            'collectif' => self::txt($r['collectif'] ?? null),
            'niveau_max_envoye' => null !== ($r['niveau_max_envoye'] ?? null) ? (int) $r['niveau_max_envoye'] : null,
            'ecarte' => (bool) ($r['ecarte'] ?? false),
            'nb_gelees' => (int) ($r['nb_gelees'] ?? 0),
        ], $rows);
    }

    /**
     * @param list<string>                                    $collectifs
     * @param list<string>                                    $etabs
     * @param array{0: string, 1: array<string, scalar>}|null $contrainteStrategie
     */
    public function compter(?string $q, array $collectifs, array $etabs, ?string $statut, bool $horsRelance = false, ?array $contrainteStrategie = null): int
    {
        [$where, $params, $types] = $this->filtres($q, $collectifs, $etabs, $statut, $horsRelance, $contrainteStrategie);

        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.mv_annuaire_clients WHERE '.$where,
            $params,
            $types,
        );
    }

    /**
     * Clause WHERE + parametres communs (recherche trigramme + filtres). Collectifs et
     * etablissements sont multi-valeurs (IN), une liste vide ne filtre rien.
     *
     * @param list<string>                                    $collectifs
     * @param list<string>                                    $etabs
     * @param array{0: string, 1: array<string, scalar>}|null $contrainteStrategie
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType|ArrayParameterType>}
     */
    private function filtres(?string $q, array $collectifs, array $etabs, ?string $statut, bool $horsRelance = false, ?array $contrainteStrategie = null): array
    {
        $clauses = ['TRUE'];
        $params = [];
        $types = [];

        $q = null !== $q ? trim($q) : '';
        if ('' !== $q) {
            // recherche = deja lower(unaccent(...)) : on normalise l'entree pareil.
            $clauses[] = "recherche ILIKE '%' || unaccent(lower(:q)) || '%'";
            $params['q'] = $q;
        }
        $collectifs = self::codes($collectifs);
        if ([] !== $collectifs) {
            $clauses[] = 'collectif IN (:collectifs)';
            $params['collectifs'] = $collectifs;
            $types['collectifs'] = ArrayParameterType::STRING;
        }
        $etabs = self::codes($etabs);
        if ([] !== $etabs) {
            $clauses[] = 'codeetab IN (:etabs)';
            $params['etabs'] = $etabs;
            $types['etabs'] = ArrayParameterType::STRING;
        }
        if ('ecarte' === $statut) {
            $clauses[] = 'ecarte = true';
        } elseif ('relancable' === $statut) {
            $clauses[] = 'ecarte = false';
        }
        if ($horsRelance) {
            // Uniquement les comptes ayant au moins une facture gelee (relance site / pause).
            $clauses[] = 'EXISTS (SELECT 1 FROM recouvrement.facture_site fs WHERE fs.compte_code = mv_annuaire_clients.compte AND fs.actif = true)';
        }
        if (null !== $contrainteStrategie) {
            // Filtre "Strategie" : ne garder que les comptes du perimetre de la regle choisie.
            [$clauseStrat, $paramsStrat] = $contrainteStrategie;
            $clauses[] = $clauseStrat;
            $params = array_merge($params, $paramsStrat);
        }

        return [implode(' AND ', $clauses), $params, $types];
    }

    /**
     * Normalise une liste de codes venant de la requete : chaines non vides, sans
     * doublon, reindexee.
     *
     * @param list<string> $valeurs
     *
     * @return list<string>
     */
    private static function codes(array $valeurs): array
    {
        $codes = array_filter(array_map('trim', $valeurs), static fn (string $v): bool => '' !== $v);

        return array_values(array_unique($codes));
    }

    private static function txt(mixed $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }
}
