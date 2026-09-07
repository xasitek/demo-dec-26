<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Service\RibEtablissementProvider;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;

/**
 * Importe/rafraichit la table recouvrement.rib_etablissement depuis le fichier
 * Excel des RIB SYNTHAUTO (hors-repo, IBAN sensibles). On lit la feuille indiquee et
 * on mappe par NOM de colonne (CodeEtab, nom_etab, Banque, iban_etab, bic_etab),
 * pas par position, pour resister a un reordonnancement des colonnes.
 *
 * Le xlsx est lu nativement (ZipArchive + XML), sans dependance : un .xlsx est un
 * zip contenant xl/sharedStrings.xml et xl/worksheets/sheetN.xml.
 */
#[AsCommand(
    name: 'app:recouvrement:importer-rib',
    description: 'Importe les RIB par etablissement depuis le fichier Excel (hors-repo) vers la base.',
)]
final class ImporterRibCommand extends Command
{
    private const COLONNES = ['CodeEtab', 'nom_etab', 'Banque', 'iban_etab', 'bic_etab'];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::OPTIONAL, 'Chemin du .xlsx', 'DEMANDE DE REMBOURSEMENT.xlsx')
            ->addOption('feuille', null, InputOption::VALUE_REQUIRED, 'Numero de feuille (1-based) contenant les RIB etablissement', '2')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait importe sans ecrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fichier = (string) $input->getArgument('fichier');
        $feuille = (int) $input->getOption('feuille');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($fichier)) {
            $io->error(sprintf('Fichier introuvable : %s', $fichier));

            return Command::FAILURE;
        }

        try {
            $lignes = $this->lireFeuille($fichier, $feuille);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $lignes) {
            $io->warning('Aucune ligne exploitable (verifiez la feuille et les colonnes).');

            return Command::SUCCESS;
        }

        // Entete -> index de colonne, par nom.
        $entete = array_shift($lignes);
        $idx = [];
        foreach ($entete as $i => $nom) {
            $idx[trim((string) $nom)] = $i;
        }
        $manquantes = array_filter(self::COLONNES, static fn (string $c): bool => !isset($idx[$c]));
        if ([] !== $manquantes) {
            $io->error(sprintf('Colonnes absentes de la feuille %d : %s', $feuille, implode(', ', $manquantes)));

            return Command::FAILURE;
        }

        // Dedup par code etablissement (1re occurrence avec IBAN gagne).
        $ribs = [];
        foreach ($lignes as $ligne) {
            $code = RibEtablissementProvider::normaliserCode((string) ($ligne[$idx['CodeEtab']] ?? ''));
            $iban = self::nettoyerIban((string) ($ligne[$idx['iban_etab']] ?? ''));
            if (null === $code || '' === $iban || isset($ribs[$code])) {
                continue;
            }
            $ribs[$code] = [
                'code_etab' => $code,
                'titulaire' => trim((string) ($ligne[$idx['nom_etab']] ?? '')),
                'banque' => trim((string) ($ligne[$idx['Banque']] ?? '')),
                'iban' => $iban,
                'bic' => strtoupper(preg_replace('/\s+/', '', (string) ($ligne[$idx['bic_etab']] ?? '')) ?? ''),
            ];
        }

        $io->title(sprintf('Import RIB etablissement - %d etablissement(s) distinct(s)', \count($ribs)));

        if ($dryRun) {
            $apercu = \array_slice($ribs, 0, 10, true);
            $io->table(
                ['Code', 'Titulaire', 'Banque', 'IBAN (debut)', 'BIC'],
                array_map(
                    static fn (array $r): array => [(string) $r['code_etab'], $r['titulaire'], $r['banque'], substr($r['iban'], 0, 8).'...', $r['bic']],
                    $apercu,
                ),
            );
            $io->note('Mode simulation : rien ecrit.');

            return Command::SUCCESS;
        }

        $sql = <<<'SQL'
            INSERT INTO recouvrement.rib_etablissement (code_etab, titulaire, banque, iban, bic, maj_le)
            VALUES (:code, :titulaire, :banque, :iban, :bic, now())
            ON CONFLICT (code_etab) DO UPDATE SET
                titulaire = EXCLUDED.titulaire, banque = EXCLUDED.banque,
                iban = EXCLUDED.iban, bic = EXCLUDED.bic, maj_le = now()
            SQL;

        foreach ($ribs as $rib) {
            $this->connection->executeStatement($sql, [
                'code' => $rib['code_etab'],
                'titulaire' => $rib['titulaire'],
                'banque' => $rib['banque'],
                'iban' => $rib['iban'],
                'bic' => $rib['bic'],
            ]);
        }

        $io->success(sprintf('%d RIB etablissement importe(s)/mis a jour.', \count($ribs)));

        return Command::SUCCESS;
    }

    /**
     * Lit une feuille d'un .xlsx en tableau de lignes (chaque ligne = liste de
     * cellules indexee par numero de colonne, 0-based).
     *
     * @return list<array<int, string>>
     */
    private function lireFeuille(string $fichier, int $feuille): array
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($fichier)) {
            throw new RuntimeException('Impossible d\'ouvrir le .xlsx (zip illisible).');
        }

        try {
            $strings = $this->chainesPartagees($zip);
            $xml = $zip->getFromName(sprintf('xl/worksheets/sheet%d.xml', $feuille));
            if (false === $xml) {
                throw new RuntimeException(sprintf('Feuille %d absente du classeur.', $feuille));
            }
            $sheet = simplexml_load_string($xml);
            if (false === $sheet) {
                throw new RuntimeException('XML de la feuille illisible.');
            }

            $lignes = [];
            foreach ($sheet->xpath('//*[local-name()="row"]') ?: [] as $row) {
                $cellules = [];
                foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
                    $ref = (string) $c['r'];
                    $type = (string) $c['t'];
                    $valeur = '';
                    if ('inlineStr' === $type) {
                        // Chaine en ligne (<is><t>...) : ecrite par certains outils (openpyxl...).
                        $tNodes = $c->xpath('.//*[local-name()="t"]') ?: [];
                        $valeur = [] !== $tNodes ? (string) $tNodes[0] : '';
                    } else {
                        $vNodes = $c->xpath('./*[local-name()="v"]') ?: [];
                        if ([] !== $vNodes) {
                            $brut = (string) $vNodes[0];
                            $valeur = 's' === $type ? ($strings[(int) $brut] ?? '') : $brut;
                        }
                    }
                    $cellules[self::indexColonne($ref)] = $valeur;
                }
                $lignes[] = $cellules;
            }

            return $lignes;
        } finally {
            $zip->close();
        }
    }

    /**
     * Table des chaines partagees (xl/sharedStrings.xml).
     *
     * @return list<string>
     */
    private function chainesPartagees(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (false === $xml) {
            return [];
        }
        $ss = simplexml_load_string($xml);
        if (false === $ss) {
            return [];
        }
        $strings = [];
        foreach ($ss->xpath('//*[local-name()="si"]') ?: [] as $si) {
            $texte = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texte .= (string) $t;
            }
            $strings[] = $texte;
        }

        return $strings;
    }

    /**
     * Numero de colonne 0-based depuis une reference de cellule ('B7' -> 1).
     */
    private static function indexColonne(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $lettres = $m[1] ?? 'A';
        $n = 0;
        foreach (str_split($lettres) as $lettre) {
            $n = $n * 26 + (\ord($lettre) - 64);
        }

        return $n - 1;
    }

    /**
     * Nettoie un IBAN (retire espaces, majuscules). Vide si visiblement invalide.
     */
    private static function nettoyerIban(string $brut): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $brut) ?? '');

        return preg_match('/^[A-Z]{2}[0-9A-Z]{10,32}$/', $iban) ? $iban : '';
    }
}
