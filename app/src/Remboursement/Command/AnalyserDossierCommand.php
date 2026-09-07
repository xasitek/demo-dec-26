<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Message\AnalyserDossier;
use App\Remboursement\Repository\DossierRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * (Re)dispatch l'analyse IA de dossiers DEPOSE : traite le backlog (dossiers deposes
 * avant le branchement de l'IA) ou relance l'analyse d'un dossier precis. Le worker
 * (messenger:consume remboursement) fait le travail.
 */
#[AsCommand(
    name: 'app:remboursement:analyser',
    description: 'Dispatch l\'analyse IA des dossiers deposes (backlog ou reference precise).',
)]
final class AnalyserDossierCommand extends Command
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('reference', InputArgument::OPTIONAL, 'Reference d\'un dossier (defaut : tous les dossiers "Depose").');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reference = (string) ($input->getArgument('reference') ?? '');

        if ('' !== $reference) {
            $dossier = $this->dossiers->findOneBy(['reference' => $reference]);
            if (null === $dossier || DossierStatut::DEPOSE !== $dossier->getStatut()) {
                $io->error(sprintf('Dossier "%s" introuvable ou pas au statut Depose.', $reference));

                return Command::FAILURE;
            }
            $dossiers = [$dossier];
        } else {
            $dossiers = $this->dossiers->findBy(['statut' => DossierStatut::DEPOSE]);
        }

        foreach ($dossiers as $dossier) {
            $this->bus->dispatch(new AnalyserDossier((int) $dossier->getId()));
        }

        $io->success(sprintf('%d dossier(s) envoye(s) en analyse IA (file "remboursement").', \count($dossiers)));

        return Command::SUCCESS;
    }
}
