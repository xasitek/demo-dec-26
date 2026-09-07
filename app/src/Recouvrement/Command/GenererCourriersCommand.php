<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Repository\RelanceEnvoiRepository;
use App\Recouvrement\Service\LettreCourrierPdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Génère et stocke le PDF complet (relevé + factures) des courriers papier en
 * attente. À lancer sur la MACHINE INTERNE (mRemote, réseau Synthauto) : elle seule
 * joint Progiciel (serveur interne, nom retire de la copie) pour récupérer les PDF de factures et dispose de
 * Ghostscript pour la fusion. Le web (Render) sert ensuite le PDF stocké tel quel.
 *
 * Idempotent : par défaut ne (re)génère que les courriers sans PDF (courrier_pdf
 * IS NULL). --force régénère tous les courriers en attente.
 */
#[AsCommand(
    name: 'app:recouvrement:generer-courriers',
    description: 'Pré-génère le PDF (relevé + factures) des courriers papier en attente (machine interne).',
)]
final class GenererCourriersCommand extends Command
{
    public function __construct(
        private readonly RelanceEnvoiRepository $relances,
        private readonly LettreCourrierPdfService $lettrePdf,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Régénère tous les courriers en attente (même ceux déjà générés).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recouvrement : génération des PDF de courriers (relevé + factures)');

        $force = (bool) $input->getOption('force');
        $courriers = $this->relances->findCourriersAGenerer($force);

        if ([] === $courriers) {
            $io->success('Aucun courrier à générer.');

            return Command::SUCCESS;
        }

        $ok = 0;
        $erreurs = 0;
        foreach ($courriers as $courrier) {
            try {
                $pdf = $this->lettrePdf->lettre($courrier);
                $courrier->setCourrierPdf($pdf);
                $this->entityManager->flush();
                ++$ok;
                $io->writeln(sprintf('  <info>OK</info> %s (%d o)', $courrier->getCompteCode(), \strlen($pdf)));
            } catch (Throwable $e) {
                ++$erreurs;
                $io->writeln(sprintf('  <error>KO</error> %s : %s', $courrier->getCompteCode(), $e->getMessage()));
            }
        }

        $io->success(sprintf('%d courrier(s) généré(s), %d erreur(s).', $ok, $erreurs));

        return $erreurs > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
