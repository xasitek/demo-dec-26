<?php

declare(strict_types=1);

namespace App\Livraison\Repository;

use Doctrine\DBAL\Connection;

/**
 * Les concessions du circuit Livraison, telles que la secretaire les connait.
 *
 * L'unite de depot du circuit remplace n'est PAS l'etablissement mais la societe :
 * il existait un formulaire Google par concession, filtre sur la colonne `codeSoc`
 * de l'onglet `ESPACE LIVREUR`, et ce code est exactement le `code_societe` de
 * `shared.etablissement` (verifie le 2026-09-02 : TAM vaut 37 lignes cote Sheet et
 * 37 vehicules cote base, FAS 22 et 22).
 *
 * Une concession couvre plusieurs etablissements — MILOSSTR en couvre trois, TAM
 * quatre — donc faire choisir un etablissement obligerait la secretaire a repasser
 * deux a quatre fois par l'ecran pour le travail qu'elle faisait en une.
 *
 * La jointure au referentiel est volontairement EXTERNE : deux etablissements de la
 * vue n'y figurent pas (111 et 364 au 2026-09-02, 8 vehicules). Une jointure interne
 * les ferait disparaitre sans bruit, ce que le module s'interdit.
 */
final class ConcessionRepository
{
    /**
     * La vue, enrichie de sa concession et de l'etat de declaration.
     *
     * `code_soc` retombe sur le code etablissement quand le referentiel l'ignore :
     * le vehicule reste atteignable, sous un groupe signale comme hors referentiel.
     *
     *
     * `solde_vehicule` totalise TOUTES les factures du vehicule. Un solde nul signifie
     * que le loueur a paye : le dossier n'a donc plus a partir, la raison d'etre du
     * module etant precisement d'obtenir ce paiement. Ces vehicules sortent de la file
     * — 20 des 182 restants au 2026-09-02, et c'est ce qui explique les vehicules a
     * 0,00 EUR que le circuit Google ne proposait pas. Les soldes NEGATIFS, eux, sont
     * conserves : un trop-paye est une anomalie qui doit rester visible.
     */
    private const BASE = <<<'SQL'
        SELECT COALESCE(NULLIF(e.code_societe, ''), v.code_etab) AS code_soc,
               (e.code_etab IS NULL)::int                        AS hors_referentiel,
               v.code_etab,
               v.identifiant_vehicule,
               (d.identifiant_vehicule IS NOT NULL)              AS deja,
               sum(coalesce(v.solde, 0))
                 OVER (PARTITION BY v.identifiant_vehicule)      AS solde_vehicule
        FROM livraison.v_a_livrer v
        LEFT JOIN shared.etablissement e ON e.code_etab = v.code_etab
        LEFT JOIN livraison.declaration d ON d.identifiant_vehicule = v.identifiant_vehicule
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Les concessions qui ont des vehicules dans la vue, avec ce qu'il leur reste a
     * declarer. `restants` alimente le compteur du menu, comme le libelle
     * « LOUEUR A (7 non livres) » du formulaire.
     *
     * @return list<array{code_societe: string, hors_referentiel: bool, nb_etablissements: int, total: int, restants: int}>
     */
    public function avecRestants(): array
    {
        /** @var list<array{code_societe: string, hors_referentiel: int, nb_etablissements: int|string, total: int|string, restants: int|string}> $lignes */
        $lignes = $this->connection->executeQuery(
            'WITH base AS ('.self::BASE.')
             SELECT code_soc                                                    AS code_societe,
                    max(hors_referentiel)                                       AS hors_referentiel,
                    count(DISTINCT code_etab)                                   AS nb_etablissements,
                    count(DISTINCT identifiant_vehicule)
                      FILTER (WHERE solde_vehicule <> 0)                        AS total,
                    count(DISTINCT identifiant_vehicule)
                      FILTER (WHERE solde_vehicule <> 0 AND NOT deja)           AS restants
             FROM base
             GROUP BY code_soc
             -- Les concessions qui ont du travail en tete, comme au niveau loueur.
             ORDER BY (count(DISTINCT identifiant_vehicule)
                         FILTER (WHERE solde_vehicule <> 0 AND NOT deja) = 0), code_soc'
        )->fetchAllAssociative();

        return array_map(static fn (array $l): array => [
            'code_societe' => (string) $l['code_societe'],
            'hors_referentiel' => 1 === (int) $l['hors_referentiel'],
            'nb_etablissements' => (int) $l['nb_etablissements'],
            'total' => (int) $l['total'],
            'restants' => (int) $l['restants'],
        ], $lignes);
    }

    /**
     * La concession d'un etablissement, pour continuer d'accepter `?etab=` dans
     * l'URL. Un etablissement inconnu du referentiel se represente lui-meme, ce qui
     * correspond a la retombee de `code_soc`.
     */
    public function concessionDe(string $codeEtab): ?string
    {
        if ('' === $codeEtab) {
            return null;
        }

        $trouve = $this->connection->executeQuery(
            "SELECT COALESCE(NULLIF(code_societe, ''), code_etab)
             FROM shared.etablissement
             WHERE code_etab = :etab",
            ['etab' => $codeEtab]
        )->fetchOne();

        return false === $trouve ? $codeEtab : (string) $trouve;
    }
}
