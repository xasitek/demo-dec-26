<?php

declare(strict_types=1);

namespace App\Shared\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * ETL Progiciel -> schema mirror : synchronisation idempotente.
 *
 * Strategie (voir docs/ARCHITECTURE.md) : copie fidele en JSONB, upsert par cle,
 * jamais de suppression (present_dans_sage), detection de changement (content_hash),
 * horodatage (cree_le / vu_le / modifie_le).
 */
final class SageMirrorService
{
    private const LOT = 1000;

    /**
     * Separateur des parties d'une cle composite (ex. bal_eloficash :
     * "clé écriture" + "oidech"). Caractere de controle ASCII "unit separator"
     * (0x1F) : invisible, jamais present dans une valeur Progiciel, donc pas de
     * collision possible entre deux cles composites distinctes.
     */
    private const SEPARATEUR_CLE = "\x1f";

    /**
     * Tables synchronisees : source Progiciel => [cible mirror, colonne cle, quotidien].
     *
     * Le flag `quotidien` indique si la table fait partie du run nocturne :
     * - true : synchronisee chaque nuit (creances, tiers, balance agee).
     * - false : volumineuse et non utilisee par les modules actuels (ex.
     *   reporting_ecritures = 12 M lignes de gestion). On la synchronise a la
     *   demande via `--complet`. Voir docs/ARCHITECTURE.md.
     *
     * La cle peut etre une colonne unique (string) ou composite (list<string>,
     * concatenee avec SEPARATEUR_CLE). bal_eloficash exige le couple
     * ("clé écriture", "oidech") : "clé écriture" seule n'est PAS unique cote
     * Progiciel (~610 doublons sur 88 783 lignes au 2026-06-19), la mono-cle ecrasait
     * donc silencieusement des lignes d'ecriture via ON CONFLICT.
     *
     * @var array<string, array{cible: string, cle: string|list<string>, quotidien: bool}>
     */
    public const TABLES = [
        't_ari_bal_eloficash' => ['cible' => 'bal_eloficash', 'cle' => ['clé écriture', 'oidech'], 'quotidien' => true],
        't_ari_balance_agee_bonuseco' => ['cible' => 'balance_agee', 'cle' => 'numero', 'quotidien' => true],
        't_ari_tiers_eloficash' => ['cible' => 'tiers', 'cle' => 'code', 'quotidien' => true],
        't_ra_groupe_avec_detail_ecritures_022019_objectifs' => ['cible' => 'reporting_ecritures', 'cle' => 'identifiantecranalytique', 'quotidien' => false],
    ];

    public function __construct(
        private readonly Connection $defaultConnection,
        #[Autowire(service: 'doctrine.dbal.sage_connection')]
        private readonly Connection $sageConnection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Synchronise une table Progiciel vers sa table mirror.
     *
     * @param string|list<string> $cleColonne colonne(s) Progiciel formant la cle mirror
     *
     * @return array{traites: int, disparus: int}
     */
    public function synchroniser(string $source, string $cible, string|array $cleColonne): array
    {
        $debut = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $traites = 0;

        // Curseur serveur : un seul balayage sequentiel de la table source,
        // streame par paquets. Cout lineaire et memoire constante quelle que
        // soit la taille, et independant des index Progiciel (la cle n'est pas
        // forcement indexee, ex. reporting_ecritures ~2M lignes) -- la ou OFFSET
        // degenere en O(n^2) et le keyset s'effondre sans index. Voir docs/PERFORMANCE.md.
        $this->sageConnection->beginTransaction();
        try {
            $this->sageConnection->executeStatement(
                sprintf('DECLARE etl_cursor NO SCROLL CURSOR FOR SELECT * FROM %s', $source),
            );
            do {
                $lignes = $this->sageConnection->fetchAllAssociative(
                    sprintf('FETCH FORWARD %d FROM etl_cursor', self::LOT),
                );
                if ([] !== $lignes) {
                    $traites += $this->upsertLot($cible, $cleColonne, $lignes, $debut);
                }
            } while (self::LOT === \count($lignes));
            $this->sageConnection->executeStatement('CLOSE etl_cursor');
            $this->sageConnection->commit();
        } catch (Throwable $e) {
            $this->sageConnection->rollBack();
            throw $e;
        }

        // Conservation : les lignes non revues dans ce run ont disparu de Progiciel.
        $disparus = (int) $this->defaultConnection->executeStatement(
            sprintf('UPDATE mirror.%s SET present_dans_sage = false WHERE vu_le < :debut AND present_dans_sage = true', $cible),
            ['debut' => $debut],
        );

        $this->logger->info('ETL mirror {cible} : {traites} lignes, {disparus} disparues', [
            'cible' => $cible,
            'traites' => $traites,
            'disparus' => $disparus,
        ]);

        return ['traites' => $traites, 'disparus' => $disparus];
    }

    /**
     * Upsert d'un lot de lignes en une seule requete multi-lignes.
     *
     * Un INSERT ... VALUES (...), (...) ... par lot plutot qu'une requete par
     * ligne : indispensable pour la perf (un seul aller-retour reseau) et pour
     * tenir en memoire (le profiler dev conserve chaque requete executee).
     *
     * @param string|list<string>        $cleColonne colonne(s) Progiciel formant la cle mirror
     * @param list<array<string, mixed>> $lignes
     */
    private function upsertLot(string $cible, string|array $cleColonne, array $lignes, string $debut): int
    {
        $colonnes = \is_array($cleColonne) ? $cleColonne : [$cleColonne];

        // Dedoublonnage par cle : ON CONFLICT interdit de toucher deux fois la
        // meme ligne dans un seul INSERT. La derniere occurrence l'emporte.
        $parCle = [];
        foreach ($lignes as $ligne) {
            $cle = $this->composerCle($ligne, $colonnes);
            if ('' === $cle) {
                continue;
            }
            $parCle[$cle] = $ligne;
        }

        if ([] === $parCle) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($parCle as $cle => $ligne) {
            $json = json_encode($ligne, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $placeholders[] = '(?, ?, ?, true, ?, ?, ?)';
            // (string) cle : les cles numeriques sont coercees en int par PHP
            // comme cles de tableau ; la colonne cle est un VARCHAR.
            $params[] = (string) $cle;
            $params[] = $json;
            $params[] = md5($json);
            $params[] = $debut;
            $params[] = $debut;
            $params[] = $debut;
        }

        $sql = sprintf(
            'INSERT INTO mirror.%1$s (cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le) '
            .'VALUES %2$s '
            .'ON CONFLICT (cle) DO UPDATE SET '
            .'donnees = EXCLUDED.donnees, '
            .'present_dans_sage = true, '
            .'vu_le = EXCLUDED.vu_le, '
            .'modifie_le = CASE WHEN mirror.%1$s.content_hash <> EXCLUDED.content_hash THEN EXCLUDED.modifie_le ELSE mirror.%1$s.modifie_le END, '
            .'content_hash = EXCLUDED.content_hash',
            $cible,
            implode(', ', $placeholders),
        );

        $this->defaultConnection->beginTransaction();
        try {
            $this->defaultConnection->executeStatement($sql, $params);
            $this->defaultConnection->commit();
        } catch (Throwable $e) {
            $this->defaultConnection->rollBack();
            throw $e;
        }

        return \count($parCle);
    }

    /**
     * Construit la cle mirror d'une ligne a partir de ses colonnes-cles.
     * Mono-colonne : la valeur telle quelle. Multi-colonnes : concatenation
     * avec SEPARATEUR_CLE. Retourne '' si toutes les parties sont vides (ligne
     * sans cle exploitable -> ignoree, comme avant).
     *
     * @param array<string, mixed> $ligne
     * @param list<string>         $colonnes
     */
    private function composerCle(array $ligne, array $colonnes): string
    {
        $valeurs = [];
        foreach ($colonnes as $colonne) {
            $valeurs[] = (string) ($ligne[$colonne] ?? '');
        }
        $cle = implode(self::SEPARATEUR_CLE, $valeurs);

        return '' === trim($cle, self::SEPARATEUR_CLE) ? '' : $cle;
    }
}
