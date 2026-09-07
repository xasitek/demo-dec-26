<?php

declare(strict_types=1);

namespace App\BonusEco\Command;

use App\BonusEco\Service\DossierAspImporter;
use App\Shared\Service\GoogleSheetsClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Sync hebdo des dossiers ASP depuis le Google Sheet vers bonus_eco.dossier_asp.
 *
 * Lit l'onglet (premiere feuille par defaut, plage A:AH = 34 colonnes max) et
 * upserte sur la cle naturelle (num_chassis, num_dossier_mensuel). Jamais de delete.
 *
 * A scheduler en cron Render : tous les lundis 6h.
 */
#[AsCommand(
    name: 'app:bonus-eco:sync-sheet',
    description: 'Synchronise les dossiers ASP depuis le Google Sheet (upsert).',
)]
final class SyncSheetCommand extends Command
{
    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly DossierAspImporter $importer,
        private readonly string $sheetId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->info(sprintf('Sync du sheet %s...', $this->sheetId));

        // Plage : A:AH (34 colonnes), pas de limite de ligne => tout le sheet.
        try {
            $rows = $this->sheets->readRange($this->sheetId, 'A:AH');
        } catch (Throwable $e) {
            $io->error('Lecture sheet impossible : '.$e->getMessage());

            return Command::FAILURE;
        }

        if (\count($rows) < 2) {
            $io->warning('Sheet vide ou sans donnees.');

            return Command::SUCCESS;
        }

        $header = $rows[0];
        $data = \array_slice($rows, 1);

        try {
            $stats = $this->importer->importBatch($header, $data);
        } catch (Throwable $e) {
            $io->error('Import échoué : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d lignes vues, %d nouveaux, %d mises à jour, %d ignorées (clé naturelle vide).',
            $stats['vues'],
            $stats['inserts'],
            $stats['updates'],
            $stats['ignorees'],
        ));

        return Command::SUCCESS;
    }
}
