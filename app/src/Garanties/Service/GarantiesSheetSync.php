<?php

declare(strict_types=1);

namespace App\Garanties\Service;

use App\Shared\Service\GoogleSheetsClient;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Synchronise toutes les marques de garantie depuis le Google Sheet central
 * vers garanties.dossier. Config par marque dans config/packages/garanties.yaml.
 *
 * Format sheet (1 onglet par marque) :
 *   date_scrap | concession | marque | vin | n_dossier | montant_inc | statut | document
 *
 * Colonne « document » optionnelle (OPEL) : une ou plusieurs references de PDF
 * (separees par « ; ») uploades via l'API. On cree les liens dossier_document
 * par egalite de reference, sans supprimer les liens existants.
 *
 * Pattern :
 *   1. Si scrape_complet=true : reset present_dans_scrap=FALSE pour tous les
 *      dossiers de la marque (le robot reprend tout le portail, on peut donc
 *      detecter les DG disparues). Si scrape_complet=false (robot incremental
 *      qui n'envoie que les dernieres), on saute le reset pour ne pas marquer
 *      a tort comme disparues des DG encore valides.
 *   2. Upsert chaque ligne du sheet (clé naturelle emetteur+mvs+numero_or :
 *      une seule ligne par vehicule + ordre de reparation, le num_dg le plus
 *      recent gagne -> jamais de doublon, quel que soit le mode de scrape).
 *   3. Les dossiers conservent present_dans_scrap=FALSE s'ils ne sont plus
 *      dans le sheet (= disparus du portail constructeur). Aucune ligne n'est
 *      jamais supprimee : l'historique est conserve.
 *
 * @phpstan-type MarqueConfig array{
 *     onglet: string,
 *     emetteur: string,
 *     marque_libelle: string,
 *     wmi: list<string>,
 *     statuts: array<string, string>,
 *     concessions: array<string, string>,
 *     scrape_complet?: bool,
 * }
 */
final class GarantiesSheetSync
{
    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $sheetId,
        /** @var array<string, MarqueConfig> */
        private readonly array $marques,
    ) {
    }

    /**
     * Synchronise toutes les marques configurees (ou un sous-ensemble).
     *
     * @param list<string>|null $marquesFiltre ex. ['opel','toyota'] (null = toutes)
     *
     * @return array<string, array{vues: int, inserts: int, updates: int, ignorees: int, erreurs: int}>
     */
    public function syncToutesMarques(?array $marquesFiltre = null): array
    {
        $resultats = [];
        foreach ($this->marques as $cle => $config) {
            if (null !== $marquesFiltre && !\in_array($cle, $marquesFiltre, true)) {
                continue;
            }
            try {
                $resultats[$cle] = $this->syncMarque($cle, $config);
            } catch (Throwable $e) {
                $this->logger->error("Echec sync marque $cle", ['erreur' => $e->getMessage()]);
                $resultats[$cle] = ['vues' => 0, 'inserts' => 0, 'updates' => 0, 'ignorees' => 0, 'erreurs' => 1];
            }
        }

        return $resultats;
    }

    /**
     * @param MarqueConfig $config
     *
     * @return array{vues: int, inserts: int, updates: int, ignorees: int, erreurs: int}
     */
    private function syncMarque(string $cle, array $config): array
    {
        $stats = ['vues' => 0, 'inserts' => 0, 'updates' => 0, 'ignorees' => 0, 'erreurs' => 0];

        $rows = $this->sheets->readRange($this->sheetId, "'".$config['onglet']."'!A:Z");
        if (\count($rows) < 2) {
            return $stats;
        }

        $header = array_map(static fn (string $h): string => strtolower(trim($h)), $rows[0]);
        $idx = $this->mapColonnes($header);
        $manquants = array_diff(['vin', 'n_dossier', 'montant_inc', 'statut', 'concession'], array_keys($idx));
        if ([] !== $manquants) {
            $this->logger->error("Colonnes manquantes pour $cle", ['manquants' => $manquants]);
            ++$stats['erreurs'];

            return $stats;
        }

        // Reset present_dans_scrap UNIQUEMENT si le robot reprend tout le
        // portail (scrape_complet). Sinon (robot incremental), on ne touche pas
        // aux dossiers hors batch pour ne pas les marquer a tort « disparus ».
        if ($config['scrape_complet'] ?? true) {
            $this->connection->executeStatement(
                'UPDATE garanties.dossier SET present_dans_scrap = FALSE WHERE emetteur = ?',
                [$config['emetteur']],
            );
        }

        for ($i = 1; $i < \count($rows); ++$i) {
            $row = $rows[$i];
            ++$stats['vues'];

            try {
                $data = $this->normaliser($row, $idx, $config);
                if (null === $data) {
                    ++$stats['ignorees'];
                    continue;
                }
                /** @var list<string> $references */
                $references = $data['references'];
                unset($data['references']);
                $this->connection->beginTransaction();
                try {
                    $res = $this->upsert($data);
                    if ([] !== $references) {
                        $this->lierDocuments($res['id'], $references);
                    }
                    $this->connection->commit();
                    $res['inserted'] ? ++$stats['inserts'] : ++$stats['updates'];
                } catch (Throwable $e) {
                    $this->connection->rollBack();
                    throw $e;
                }
            } catch (Throwable $e) {
                ++$stats['erreurs'];
                if ($stats['erreurs'] <= 3) {
                    $this->logger->warning("$cle ligne $i", ['erreur' => $e->getMessage()]);
                }
            }
        }

        return $stats;
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int>
     */
    private function mapColonnes(array $header): array
    {
        $idx = [];
        foreach ($header as $i => $col) {
            if ('' !== $col) {
                $idx[$col] = $i;
            }
        }

        return $idx;
    }

    /**
     * @param list<string>       $row
     * @param array<string, int> $idx
     * @param MarqueConfig       $config
     *
     * @return array<string, mixed>|null
     */
    private function normaliser(array $row, array $idx, array $config): ?array
    {
        $get = static fn (string $col): string => trim($row[$idx[$col] ?? -1] ?? '');

        $vin = $get('vin');
        $numeroOr = $get('numero_or'); // colonne optionnelle (Fiat l'a, Toyota non)
        $nDossier = $get('n_dossier');
        if ('' === $vin) {
            return null;
        }
        // Identifiant unique de la DG cote constructeur. Si numero_or present
        // et non vide, c'est lui qui sert d'ID (cas Fiat ou n_dossier est un
        // simple compteur sequentiel). Sinon, on retombe sur n_dossier (cas
        // Toyota/Opel ou n_dossier = NMSC ID).
        $numDg = '' !== $numeroOr ? $numeroOr : $nDossier;
        if ('' === $numDg) {
            return null;
        }

        // Statut : mapping via config, defaut '23' (En traitement)
        $statutBrut = $get('statut');
        $statutCode = $config['statuts'][$statutBrut] ?? $config['statuts'][strtoupper($statutBrut)] ?? '23';

        // Concession : mapping via config (match exact OU contient sur les codes
        // entre tirets). Si Fiat envoie "0001959 - BELFORT", on essaie d'extraire
        // soit le code (0001959), soit le nom (BELFORT) et on cherche dans la map.
        $concessionBrute = $get('concession');
        $concession = $config['concessions'][$concessionBrute] ?? null;
        if (null === $concession) {
            // Tente d'extraire le nom apres " - " (pour format "0001959 - BELFORT")
            if (preg_match('/\s-\s(.+)$/', $concessionBrute, $m)) {
                $nomVille = ucfirst(strtolower(trim($m[1])));
                $concession = $config['marque_libelle'].' '.$nomVille;
            } else {
                $concession = $config['marque_libelle'].' '.$concessionBrute;
            }
        }

        // Marque : on prend la valeur brute du sheet (FIAT / JEEP / ALFAROMEO...)
        // capitalisee, et on retombe sur marque_libelle si non lisible.
        $marqueBrute = trim($row[$idx['marque'] ?? -1] ?? '');
        $marque = '' !== $marqueBrute && preg_match('/^[A-Za-z]/', $marqueBrute)
            ? ucfirst(strtolower($marqueBrute))
            : $config['marque_libelle'];

        // Montant : nettoyage (retire EUR, €, espaces, normalise virgule->point)
        $montantBrut = $get('montant_inc');
        $montant = self::parseMontant($montantBrut);

        // Cas Fiat : le portail ne fournit que le chassis 8 chars (pas le VIN
        // complet). Pour les autres marques, on a le VIN 17 chars et on extrait
        // les 8 derniers. Dans tous les cas, chassis = 8 derniers chars de la
        // valeur fournie.
        $chassis = \strlen($vin) >= 8 ? substr($vin, -8) : $vin;

        // Colonne optionnelle « document » : une ou plusieurs references de PDF
        // separees par « ; ». Vide si la colonne est absente (Toyota/Fiat).
        $references = array_values(array_filter(
            array_map('trim', explode(';', $get('document'))),
            static fn (string $r): bool => '' !== $r,
        ));

        return [
            'num_dg' => $numDg,
            'mvs' => $vin,
            'emetteur' => $config['emetteur'],
            'chassis' => $chassis,
            'marque' => $marque,
            'concession' => $concession,
            'statut_code' => $statutCode,
            // numero_or : si colonne dediee presente, on la prend telle quelle ;
            // sinon on retombe sur num_dg (pour compatibilite avec ancien format).
            'numero_or' => '' !== $numeroOr ? $numeroOr : $numDg,
            'montant_dg' => $montant,
            'date_intervention' => self::parseDate($get('date_scrap')),
            // Hors colonnes SQL : extrait par syncMarque avant l'upsert.
            'references' => $references,
        ];
    }

    private static function parseMontant(string $v): ?float
    {
        if ('' === $v) {
            return null;
        }
        // Retire suffixes (EUR, €) et espaces
        $clean = (string) preg_replace('/[^0-9,.\-]/', '', $v);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private static function parseDate(string $v): ?string
    {
        if ('' === $v) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v)
            ?: DateTimeImmutable::createFromFormat('Y-m-d', $v)
            ?: DateTimeImmutable::createFromFormat('d/m/Y', $v);

        return false === $d ? null : $d->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: int, inserted: bool}
     */
    private function upsert(array $data): array
    {
        $data['content_hash'] = md5((string) json_encode($data, JSON_THROW_ON_ERROR));

        $sql = 'INSERT INTO garanties.dossier '
            .'(num_dg, mvs, emetteur, chassis, marque, concession, statut_code, numero_or, montant_dg, date_intervention, present_dans_scrap, donnees, content_hash, importe_le, cree_le, modifie_le) '
            .'VALUES (:num_dg, :mvs, :emetteur, :chassis, :marque, :concession, :statut_code, :numero_or, :montant_dg, :date_intervention, TRUE, \'{}\'::jsonb, :content_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            .'ON CONFLICT (emetteur, mvs, numero_or) DO UPDATE SET '
            .'num_dg = EXCLUDED.num_dg, chassis = EXCLUDED.chassis, marque = EXCLUDED.marque, concession = EXCLUDED.concession, '
            .'statut_code = EXCLUDED.statut_code, '
            .'montant_dg = EXCLUDED.montant_dg, date_intervention = EXCLUDED.date_intervention, '
            .'present_dans_scrap = TRUE, content_hash = EXCLUDED.content_hash, modifie_le = CURRENT_TIMESTAMP '
            .'RETURNING id, (xmax = 0) AS inserted';

        /** @var array{id: int|string, inserted: bool}|false $r */
        $r = $this->connection->fetchAssociative($sql, $data);
        if (false === $r) {
            throw new RuntimeException('Echec de l upsert du dossier (mvs '.(string) ($data['mvs'] ?? '').').');
        }

        return ['id' => (int) $r['id'], 'inserted' => true === $r['inserted']];
    }

    /**
     * Cree les liens dossier <-> document par reference (idempotent, additif).
     * On ne supprime jamais un lien existant : un PDF retire d'une cellule reste
     * rattache (principe d'audit, aucune donnee perdue).
     *
     * @param list<string> $references
     */
    private function lierDocuments(int $dossierId, array $references): void
    {
        foreach ($references as $reference) {
            $this->connection->executeStatement(
                'INSERT INTO garanties.dossier_document (dossier_id, reference, cree_le) '
                .'VALUES (:dossier_id, :reference, CURRENT_TIMESTAMP) '
                .'ON CONFLICT (dossier_id, reference) DO NOTHING',
                ['dossier_id' => $dossierId, 'reference' => $reference],
            );
        }
    }
}
