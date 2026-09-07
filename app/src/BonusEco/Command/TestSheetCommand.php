<?php

declare(strict_types=1);

namespace App\BonusEco\Command;

use App\Shared\Service\GoogleSheetsClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:bonus-eco:test-sheet', description: 'Test de connexion au Google Sheet ASP.')]
final class TestSheetCommand extends Command
{
    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly string $sheetId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->info(sprintf('Lecture des 5 premières lignes du sheet %s...', $this->sheetId));

        try {
            // 'A1:AH5' = 34 colonnes attendues, 5 lignes (header + 4 lignes data).
            $rows = $this->sheets->readRange($this->sheetId, 'A1:AH5');
        } catch (Throwable $e) {
            $io->error('Echec : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('OK : %d ligne(s) recue(s)', \count($rows)));
        foreach ($rows as $i => $row) {
            $io->writeln(sprintf('  Ligne %d (%d cellules) : %s ...', $i + 1, \count($row), substr(implode(' | ', array_slice($row, 0, 6)), 0, 120)));
        }

        return Command::SUCCESS;
    }
}
