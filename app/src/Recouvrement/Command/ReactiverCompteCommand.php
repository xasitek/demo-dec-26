<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Enum\CompteExclusionEtat;
use App\Recouvrement\Repository\CompteExclusionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reactive manuellement un compte (le remet relancable), decision humaine.
 *
 * Sert a rattraper un faux positif du semis auto (ex. un vrai client pro ecarte
 * par le garde-fou volume). Pose une decision MANUEL/RELANCABLE qui prime sur le
 * semis auto.
 */
#[AsCommand(
    name: 'app:recouvrement:reactiver',
    description: 'Réactive manuellement un compte (le rend relançable).',
)]
final class ReactiverCompteCommand extends Command
{
    public function __construct(private readonly CompteExclusionRepository $exclusions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('compte', InputArgument::REQUIRED, 'Code du compte à réactiver')
            ->addOption('par', null, InputOption::VALUE_REQUIRED, 'Opérateur (nom)', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $compte = (string) $input->getArgument('compte');
        $par = (string) $input->getOption('par');

        $this->exclusions->decisionManuelle($compte, CompteExclusionEtat::RELANCABLE, 'Réactivé manuellement', $par);

        $io->success(sprintf('Compte %s réactivé (relançable, par %s).', $compte, $par));

        return Command::SUCCESS;
    }
}
