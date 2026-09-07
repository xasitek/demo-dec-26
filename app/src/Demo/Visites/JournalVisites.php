<?php

declare(strict_types=1);

namespace App\Demo\Visites;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * LE COMPTEUR DE VISITES DE LA DEMONSTRATION.
 *
 * A QUOI IL REPOND. A une seule question : est-ce que quelqu'un est venu, et
 * quand ? Pas « qui », pas « d'ou ». Savoir que la plateforme a ete ouverte
 * mardi a 14 h 20, que la visite a dure trente-cinq pages et qu'elle est passee
 * par les postes secretaire puis comptable — cela suffit, et cela se defend.
 *
 * CE QU'IL NE STOCKE PAS, ET C'EST DELIBERE. Aucune adresse IP. Aucun
 * identifiant de session en clair. Aucun nom. L'empreinte de visite est un
 * jeton aleatoire tire au moment ou la porte s'ouvre : il distingue deux
 * visites sans designer personne, et il disparait avec la session du visiteur.
 * Du navigateur, on ne garde qu'une famille — Chrome, Firefox, Safari — parce
 * qu'elle aide a distinguer « c'est moi » de « c'est quelqu'un d'autre », et
 * rien de plus.
 *
 * IL NE DOIT JAMAIS CASSER LA DEMONSTRATION. Un compteur est un confort ; la
 * plateforme est le sujet. Toute erreur d'ecriture est donc avalee : si la
 * table manque, si la base tousse, les pages continuent de s'afficher. Un
 * compteur muet vaut mieux qu'une demonstration en panne devant un jury.
 */
final readonly class JournalVisites
{
    /** La cle de session qui porte l'empreinte de la visite en cours. */
    public const CLE_EMPREINTE = 'demo_visite_empreinte';

    public function __construct(
        private Connection $cnx,
    ) {
    }

    /**
     * Cree les deux tables si elles manquent.
     *
     * En DDL idempotente plutot qu'en migration : la copie de demonstration se
     * recharge souvent, et un compteur ne doit pas dependre d'une migration
     * qu'on aurait oublie de passer.
     */
    public function installer(): void
    {
        $this->cnx->executeStatement('CREATE SCHEMA IF NOT EXISTS shared');
        $this->cnx->executeStatement(
            'CREATE TABLE IF NOT EXISTS shared.visite_session (
                empreinte     char(16)    NOT NULL PRIMARY KEY,
                premiere_vue  timestamptz NOT NULL,
                derniere_vue  timestamptz NOT NULL,
                pages         integer     NOT NULL DEFAULT 0,
                navigateur    varchar(20) NOT NULL DEFAULT \'inconnu\',
                postes        text        NOT NULL DEFAULT \'\'
            )');
        $this->cnx->executeStatement(
            'CREATE TABLE IF NOT EXISTS shared.visite_evenement (
                id        bigserial   NOT NULL PRIMARY KEY,
                le        timestamptz NOT NULL,
                empreinte char(16)    NOT NULL,
                type      varchar(20) NOT NULL,
                detail    varchar(120) NOT NULL DEFAULT \'\'
            )');
        $this->cnx->executeStatement(
            'CREATE INDEX IF NOT EXISTS visite_evenement_le_idx
               ON shared.visite_evenement (le DESC)');
    }

    /** Une empreinte de visite : aleatoire, opaque, sans lien avec personne. */
    public static function empreinteNeuve(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** La porte vient de s'ouvrir : c'est une visite, et on la date. */
    public function entree(string $empreinte, ?string $agent): void
    {
        $this->sansCasser(function () use ($empreinte, $agent): void {
            $this->cnx->executeStatement(
                'INSERT INTO shared.visite_session
                   (empreinte, premiere_vue, derniere_vue, pages, navigateur, postes)
                 VALUES (?, now(), now(), 0, ?, \'\')
                 ON CONFLICT (empreinte) DO NOTHING',
                [$empreinte, self::navigateur($agent)]);
            $this->evenement($empreinte, 'entree', '');
        });
    }

    /** Une page de plus dans cette visite. */
    public function page(string $empreinte, string $chemin): void
    {
        $this->sansCasser(function () use ($empreinte, $chemin): void {
            $touchees = $this->cnx->executeStatement(
                'UPDATE shared.visite_session
                    SET pages = pages + 1, derniere_vue = now()
                  WHERE empreinte = ?', [$empreinte]);
            if (0 === $touchees) {
                // La visite a commence avant l'installation des tables : on la
                // rattrape plutot que de perdre le compte.
                $this->cnx->executeStatement(
                    'INSERT INTO shared.visite_session
                       (empreinte, premiere_vue, derniere_vue, pages, navigateur, postes)
                     VALUES (?, now(), now(), 1, \'inconnu\', \'\')
                     ON CONFLICT (empreinte) DO NOTHING', [$empreinte]);
            }
            unset($chemin);
        });
    }

    /** Le visiteur prend un poste : c'est le fait le plus parlant de tous. */
    public function poste(string $empreinte, string $persona): void
    {
        $this->sansCasser(function () use ($empreinte, $persona): void {
            $this->evenement($empreinte, 'poste', $persona);
            $this->cnx->executeStatement(
                'UPDATE shared.visite_session
                    SET postes = CASE
                          WHEN postes = \'\' THEN ?
                          WHEN position(? in postes) > 0 THEN postes
                          ELSE postes || \', \' || ?
                        END,
                        derniere_vue = now()
                  WHERE empreinte = ?',
                [$persona, $persona, $persona, $empreinte]);
        });
    }

    /**
     * Ce que le compteur sait, en une lecture.
     *
     * @return array{visites: int, visites_7j: int, pages: int, jours: int, premiere: ?string, derniere: ?string}
     */
    public function resume(): array
    {
        try {
            /** @var array<string, mixed>|false $l */
            $l = $this->cnx->fetchAssociative(
                'SELECT count(*) AS visites,
                        coalesce(sum(pages), 0) AS pages,
                        count(DISTINCT date_trunc(\'day\', premiere_vue)) AS jours,
                        min(premiere_vue) AS premiere,
                        max(derniere_vue) AS derniere,
                        count(*) FILTER (WHERE premiere_vue > now() - interval \'7 days\') AS visites_7j
                   FROM shared.visite_session');
        } catch (Throwable) {
            $l = false;
        }
        if (false === $l) {
            return ['visites' => 0, 'visites_7j' => 0, 'pages' => 0, 'jours' => 0,
                'premiere' => null, 'derniere' => null];
        }

        return [
            'visites' => (int) $l['visites'],
            'visites_7j' => (int) $l['visites_7j'],
            'pages' => (int) $l['pages'],
            'jours' => (int) $l['jours'],
            'premiere' => null !== $l['premiere'] ? (string) $l['premiere'] : null,
            'derniere' => null !== $l['derniere'] ? (string) $l['derniere'] : null,
        ];
    }

    /**
     * Les visites, de la plus recente a la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public function visites(int $limite = 100): array
    {
        try {
            return $this->cnx->fetchAllAssociative(
                'SELECT empreinte, premiere_vue, derniere_vue, pages, navigateur, postes
                   FROM shared.visite_session
                  ORDER BY premiere_vue DESC
                  LIMIT '.max(1, min(500, $limite)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Le fil des evenements : entrees a la porte et prises de poste.
     *
     * @return list<array<string, mixed>>
     */
    public function evenements(int $limite = 200): array
    {
        try {
            return $this->cnx->fetchAllAssociative(
                'SELECT le, empreinte, type, detail
                   FROM shared.visite_evenement
                  ORDER BY le DESC
                  LIMIT '.max(1, min(1000, $limite)));
        } catch (Throwable) {
            return [];
        }
    }

    private function evenement(string $empreinte, string $type, string $detail): void
    {
        $this->cnx->executeStatement(
            'INSERT INTO shared.visite_evenement (le, empreinte, type, detail)
             VALUES (now(), ?, ?, ?)',
            [$empreinte, $type, mb_substr($detail, 0, 120)]);
    }

    /**
     * La famille du navigateur, et rien de plus.
     *
     * Pas la version, pas le systeme, pas la resolution : de quoi distinguer
     * deux visiteurs, pas de quoi en dresser le portrait.
     */
    private static function navigateur(?string $agent): string
    {
        $a = (string) $agent;

        return match (true) {
            str_contains($a, 'Edg/') => 'Edge',
            str_contains($a, 'OPR/') => 'Opera',
            str_contains($a, 'Firefox/') => 'Firefox',
            str_contains($a, 'Chrome/') => 'Chrome',
            str_contains($a, 'Safari/') => 'Safari',
            '' === $a => 'inconnu',
            default => 'autre',
        };
    }

    /** Un compteur ne fait jamais tomber une page. */
    private function sansCasser(callable $ecriture): void
    {
        try {
            $ecriture();
        } catch (Throwable) {
            // Volontairement muet : voir le commentaire de tete.
        }
    }
}
