<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Repository\ActiviteJourRepository;
use App\Shared\Repository\ActivityLogRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge RGPD du suivi d'activite au-dela de la duree de conservation (6 mois
 * par defaut) : journal de connexions (ActivityLog) et temps actif quotidien
 * (ActiviteJour). A planifier en cron sur Render. Voir docs/SECURITY.md.
 */
#[AsCommand(
    name: 'app:activite:purge',
    description: 'Purge les connexions et le temps actif au-dela de N mois (RGPD)',
)]
final class PurgeActiviteCommand extends Command
{
    private const MOIS_DEFAUT = 6;

    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly ActiviteJourRepository $activiteJour,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'mois',
            null,
            InputOption::VALUE_REQUIRED,
            'Anciennete maximale conservee, en mois',
            (string) self::MOIS_DEFAUT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mois = max(1, (int) $input->getOption('mois'));
        $avant = (new DateTimeImmutable('today'))->modify(sprintf('-%d months', $mois));

        $connexions = $this->logs->purgerAvant($avant);
        $jours = $this->activiteJour->purgerAvant($avant);

        $io->success(sprintf(
            'Purge a %d mois (avant le %s) : %d connexions supprimees, %d jours d\'activite supprimes.',
            $mois,
            $avant->format('d/m/Y'),
            $connexions,
            $jours,
        ));

        return Command::SUCCESS;
    }
}
