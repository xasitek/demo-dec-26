<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\GenerationFichiersComptables;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * (Re)genere manuellement les fichiers comptables (OD + SEPA) d'un dossier « Dossier
 * valide » et le passe en « En cours de paiement ». Utile pour l'ops (relance apres
 * ERREUR_GENERATION) ou un test. En prod, la generation est automatique (async) apres
 * validation directeur ; cette commande fait la meme chose en synchrone.
 *
 *   php bin/console app:remboursement:generer-fichiers REMB-XXXXXXX
 */
#[AsCommand(
    name: 'app:remboursement:generer-fichiers',
    description: 'Genere les fichiers comptables (OD + SEPA) d\'un dossier valide directeur.',
)]
final class GenererFichiersCommand extends Command
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly GenerationFichiersComptables $generation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('reference', InputArgument::REQUIRED, 'Reference du dossier (ex. REMB-XXXXXXX).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reference = trim((string) $input->getArgument('reference'));

        $dossier = $this->dossiers->findOneBy(['reference' => $reference]);
        if (!$dossier instanceof Dossier) {
            $io->error(sprintf('Dossier "%s" introuvable.', $reference));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Statut avant : <info>%s</info>', $dossier->getStatut()->value));
        $this->generation->generer($dossier);
        $io->writeln(sprintf('Statut apres : <info>%s</info>', $dossier->getStatut()->value));

        $io->success('Terminee.');

        return Command::SUCCESS;
    }
}
