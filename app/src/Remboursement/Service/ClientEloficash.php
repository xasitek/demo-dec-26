<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Lecture SEULE du miroir Gestion commerciale (schema `mirror`) pour PREREMPLIR un dépôt trop-perçu :
 *   - recherche floue d'un client (projection `recouvrement.mv_annuaire_clients`, trigramme) ;
 *   - coordonnees bancaires du client (IBAN/BIC depuis `mirror.tiers`, comme la fiche client) ;
 *   - lignes en CREDIT du client (les trop-percus a rembourser) lues EN DIRECT dans
 *     `mirror.bal_eloficash` (source de verite, pas la vue materialisee de nuit).
 *
 * Aucune ecriture. Si le miroir ne renvoie rien d'exploitable, la secretaire saisit a la
 * main : ce service n'est qu'une AIDE au dépôt. Le lien fiable est le CODE CLIENT (indexe),
 * pas le code ICAR (non indexe) — d'ou la recherche par client.
 */
final class ClientEloficash
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Recherche floue de clients (nom / raison sociale / compte / e-mail…).
     *
     * @return list<array{compte: string, client: string, ville: string}>
     */
    public function chercherClients(string $q, int $limite = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT compte, client, ville
             FROM recouvrement.mv_annuaire_clients
             WHERE recherche ILIKE '%' || unaccent(lower(:q)) || '%'
             ORDER BY client
             LIMIT :limit",
            ['q' => $q, 'limit' => max(1, min(50, $limite))],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'compte' => trim((string) $r['compte']),
            'client' => trim((string) $r['client']),
            'ville' => trim((string) ($r['ville'] ?? '')),
        ], $rows);
    }

    /**
     * Coordonnees bancaires + raison sociale d'un client (compte 411), depuis `mirror.tiers`.
     * Meme lecture que la fiche client Recouvrement. null si compte inconnu.
     *
     * @return array{iban: string, bic: string, raisonSociale: string}|null
     */
    public function coordonnees(string $compte): ?array
    {
        $compte = trim($compte);
        if ('' === $compte) {
            return null;
        }

        $json = $this->connection->fetchOne(
            "SELECT donnees FROM mirror.tiers WHERE donnees->>'code' = :c LIMIT 1",
            ['c' => $compte],
        );
        if (!\is_string($json)) {
            return null;
        }

        /** @var array<string, mixed> $d */
        $d = json_decode($json, true) ?: [];

        return [
            'iban' => self::v($d, 'iban'),
            'bic' => self::v($d, 'bic'),
            'raisonSociale' => self::v($d, 'Raison Sociale') ?: trim(self::v($d, 'prenom').' '.self::v($d, 'nom')),
        ];
    }

    /**
     * Trop-percu d'un client = son SOLDE NET CREDITEUR : la SOMME de TOUTES ses lignes
     * (avoirs moins factures), lue en direct dans `mirror.bal_eloficash`. Un net negatif =
     * le compte est crediteur = trop-percu a rembourser (montant renvoye POSITIF). Le code
     * ICAR metier = le NUMERO du compte client (ex. COMPTANT-805887 -> 805887), pas le
     * champ `reficar` (reference interne par ligne).
     *
     * NB perf : filtre sur le code client (non indexe sur bal_eloficash) -> seq scan. Acceptable
     * pour un dépôt ponctuel ; a indexer si le volume grossit (accord de la responsable technique requis sur mirror).
     *
     * @return array{montant: string, icar: string, credit: bool, net: string}
     */
    public function tropPercu(string $compte): array
    {
        $compte = trim($compte);
        if ('' === $compte) {
            return ['montant' => '0.00', 'icar' => '', 'credit' => false, 'net' => '0.00'];
        }

        $sql = <<<'SQL'
            SELECT ROUND(SUM(COALESCE(NULLIF(REGEXP_REPLACE(REPLACE(REPLACE(donnees->>'Montant solde en devise entité', ',', '.'), '€', ''), '[^0-9.\-]', '', 'g'), '')::NUMERIC, 0)), 2)
            FROM mirror.bal_eloficash
            WHERE present_dans_sage
              AND (donnees->>'Code relancé' = :c OR donnees->>'Code facturé' = :c OR donnees->>'Code payeur' = :c)
            SQL;

        $net = (float) $this->connection->fetchOne($sql, ['c' => $compte]);
        $credit = $net < 0.0;

        return [
            'montant' => number_format($credit ? -$net : 0.0, 2, '.', ''),
            'icar' => (string) preg_replace('/\D+/', '', $compte),
            'credit' => $credit,
            'net' => number_format($net, 2, '.', ''),
        ];
    }

    /**
     * Valeur texte d'une cle JSONB (chaine vide si absente).
     *
     * @param array<string, mixed> $donnees
     */
    private static function v(array $donnees, string $cle): string
    {
        $valeur = $donnees[$cle] ?? null;

        return \is_scalar($valeur) ? trim((string) $valeur) : '';
    }
}
