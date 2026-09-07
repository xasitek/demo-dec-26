<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Service\CleDoublon;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Copie la base externe "Buy Back" (engagements de reprise) dans le MIRROR local
 * `remboursement.buyback_vehicule`. La DSN source est passee en ARGUMENT a l'execution
 * (jamais commitee ni stockee) :
 *
 *   php bin/console app:remboursement:import-buyback "postgresql://user:pass@host/fc_buyback"
 *
 * Remplace integralement le contenu du mirror (TRUNCATE + insert atomique). Recalcule
 * l'immat en forme CANONIQUE (alignee sur le depot) pour un match fiable au controle.
 */
#[AsCommand(
    name: 'app:remboursement:import-buyback',
    description: 'Copie la base Buy Back externe dans le mirror local (controle anti-surpaiement).',
)]
final class ImportBuyBackCommand extends Command
{
    private const COLONNES = 'immat_norm, immatriculation, vin, marque, modele, contrat, financeur, type_fi, client, er_ht, er_ttc, date_echeance, km_contrat, statut';

    public function __construct(private readonly Connection $connexion)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'DSN Postgres de la base Buy Back (postgresql://user:pass@host/db).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $source = $this->connexionSource((string) $input->getArgument('source'));
        } catch (Throwable $e) {
            $io->error('Connexion a la base source impossible : '.$e->getMessage());

            return Command::FAILURE;
        }

        $total = (int) $this->requete($source, 'SELECT count(*) FROM vehicles')->fetchColumn();
        $io->title(sprintf('Import Buy Back : %d vehicules a copier', $total));

        $insert = $this->connexion->prepare(
            'INSERT INTO remboursement.buyback_vehicule '
            .'(immat, immatriculation, vin, marque, modele, contrat, financeur, type_fi, client, er_ht, er_ttc, date_echeance, km_contrat, statut, importe_le) '
            .'VALUES (:immat, :immatriculation, :vin, :marque, :modele, :contrat, :financeur, :type_fi, :client, :er_ht, :er_ttc, :date_echeance, :km_contrat, :statut, :importe_le)'
        );
        $importeLe = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connexion->beginTransaction();
        try {
            $this->connexion->executeStatement('TRUNCATE remboursement.buyback_vehicule RESTART IDENTITY');

            $lus = 0;
            $ecrits = 0;
            $sansPlaque = 0;
            $stmt = $this->requete($source, 'SELECT '.self::COLONNES.' FROM vehicles');
            foreach ($stmt as $r) {
                ++$lus;
                $immat = CleDoublon::normaliserImmatriculation((string) ($r['immat_norm'] ?? '') ?: (string) ($r['immatriculation'] ?? ''));
                if ('' === $immat) {
                    ++$sansPlaque;
                    continue;
                }
                $params = [
                    'immat' => $immat,
                    'immatriculation' => self::vide($r['immatriculation'] ?? null),
                    'vin' => self::vide($r['vin'] ?? null),
                    'marque' => self::vide($r['marque'] ?? null),
                    'modele' => self::vide($r['modele'] ?? null),
                    'contrat' => self::vide($r['contrat'] ?? null),
                    'financeur' => self::vide($r['financeur'] ?? null),
                    'type_fi' => self::vide($r['type_fi'] ?? null),
                    'client' => self::vide($r['client'] ?? null),
                    'er_ht' => self::vide($r['er_ht'] ?? null),
                    'er_ttc' => self::vide($r['er_ttc'] ?? null),
                    'date_echeance' => self::vide($r['date_echeance'] ?? null),
                    'km_contrat' => self::vide($r['km_contrat'] ?? null),
                    'statut' => self::vide($r['statut'] ?? null),
                    'importe_le' => $importeLe,
                ];
                foreach ($params as $nom => $valeur) {
                    $insert->bindValue($nom, $valeur);
                }
                $insert->executeStatement();
                ++$ecrits;
                if (0 === $ecrits % 10000) {
                    $io->writeln(sprintf('  %d / %d...', $ecrits, $total));
                }
            }

            $this->connexion->commit();
            $io->success(sprintf('%d vehicules importes (%d lus, %d sans plaque ignores).', $ecrits, $lus, $sansPlaque));
        } catch (Throwable $e) {
            $this->connexion->rollBack();
            $io->error('Import annule (rollback) : '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function connexionSource(string $dsn): PDO
    {
        $url = parse_url($dsn);
        if (false === $url || !isset($url['host'], $url['path'])) {
            throw new InvalidArgumentException('DSN invalide.');
        }
        $pdoDsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $url['host'],
            $url['port'] ?? 5432,
            ltrim($url['path'], '/'),
        );

        return new PDO($pdoDsn, $url['user'] ?? null, $url['pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30,
        ]);
    }

    private function requete(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        if (false === $stmt) {
            throw new RuntimeException('Requete source echouee : '.$sql);
        }

        return $stmt;
    }

    private static function vide(mixed $valeur): ?string
    {
        $texte = trim((string) ($valeur ?? ''));

        return '' === $texte ? null : $texte;
    }
}
