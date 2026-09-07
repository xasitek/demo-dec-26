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
 * Ecarte manuellement un compte de la relance automatique (decision humaine).
 *
 * Pose une decision MANUEL/ECARTE qui prime sur le semis auto (non ecrasee par
 * app:recouvrement:seed-exclusions). Tracage : qui (--par) et quand.
 */
#[AsCommand(
    name: 'app:recouvrement:ecarter',
    description: 'Écarte manuellement un compte de la relance automatique.',
)]
final class EcarterCompteCommand extends Command
{
    public function __construct(private readonly CompteExclusionRepository $exclusions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('compte', InputArgument::REQUIRED, 'Code du compte à écarter')
            ->addOption('motif', null, InputOption::VALUE_REQUIRED, 'Raison de l\'écartement', 'Écarté manuellement')
            ->addOption('par', null, InputOption::VALUE_REQUIRED, 'Opérateur (nom)', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $compte = (string) $input->getArgument('compte');
        $motif = (string) $input->getOption('motif');
        $par = (string) $input->getOption('par');

        $this->exclusions->decisionManuelle($compte, CompteExclusionEtat::ECARTE, $motif, $par);

        $io->success(sprintf('Compte %s écarté de la relance auto (par %s).', $compte, $par));

        return Command::SUCCESS;
    }
}
