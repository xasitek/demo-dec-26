<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Repository\RecouvrementRepository;
use App\Recouvrement\Service\RecouvrementRealtime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Rafraichit la vue materialisee des impayes (recouvrement.v_impayes).
 *
 * A lancer APRES l'ETL Progiciel (qui met a jour mirror.bal_eloficash / mirror.tiers) :
 * la chaine cron execute `app:etl:mirror-compta` puis cette commande. Meme pattern
 * que `app:garanties:refresh-vue`. REFRESH CONCURRENTLY : ne verrouille pas les
 * lectures. Sans ce refresh, la vue resterait figee au dernier snapshot.
 */
#[AsCommand(
    name: 'app:recouvrement:refresh-impayes',
    description: 'Rafraichit la vue materialisee des impayes (recouvrement.v_impayes).',
)]
final class RefreshImpayesCommand extends Command
{
    public function __construct(
        private readonly RecouvrementRepository $recouvrement,
        private readonly RecouvrementRealtime $realtime,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $debut = microtime(true);

        try {
            $this->recouvrement->rafraichirImpayes();
            // Puis l'annuaire clients (projection qui agrege v_impayes).
            $this->recouvrement->rafraichirAnnuaire();
            // De nouvelles factures sans PDF ont pu apparaitre -> invite les pages
            // "factures sans PDF" ouvertes a se recharger en direct (Mercure).
            $this->realtime->signalerRafraichissementFactures();
        } catch (Throwable $e) {
            $io->error('Rafraichissement des impayes impossible : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Vue des impayes rafraichie en %ss.', round(microtime(true) - $debut, 1)));

        return Command::SUCCESS;
    }
}
