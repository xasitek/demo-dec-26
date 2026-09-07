<?php

declare(strict_types=1);

namespace App\Garanties\Command;

use App\Garanties\Repository\ReconciliationRepository;
use App\Garanties\Service\GarantiesSheetSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sync hebdo des DG depuis le Google Sheet vers garanties.dossier.
 *
 * Lit toutes les marques configurees dans config/packages/garanties.yaml.
 * Upsert sur (emetteur, mvs, numero_or) : une seule ligne par vehicule + OR,
 * donc jamais de doublon meme si le robot renvoie la meme DG. Trace les
 * dossiers disparus du sheet via present_dans_scrap=FALSE (mode scrape_complet).
 *
 * A scheduler en cron Render : lundi 3h (les robots tournent dimanche).
 */
#[AsCommand(
    name: 'app:garanties:sync-sheets',
    description: 'Synchronise les DG du Google Sheet centralisé vers garanties.dossier.',
)]
final class SyncSheetsCommand extends Command
{
    public function __construct(
        private readonly GarantiesSheetSync $sync,
        private readonly ReconciliationRepository $reconciliation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'marque',
            'm',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Limiter à une ou plusieurs marques (ex. opel,toyota)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $marques */
        $marques = $input->getOption('marque');
        $filtre = [] === $marques ? null : $marques;

        if (null !== $filtre) {
            $io->note('Marques filtrées : '.implode(', ', $filtre));
        } else {
            $io->note('Sync de toutes les marques configurées');
        }

        $resultats = $this->sync->syncToutesMarques($filtre);

        $rows = [];
        $totalInserts = $totalUpdates = $totalErreurs = 0;
        foreach ($resultats as $marque => $stats) {
            $rows[] = [
                $marque,
                $stats['vues'],
                $stats['inserts'],
                $stats['updates'],
                $stats['ignorees'],
                $stats['erreurs'],
            ];
            $totalInserts += $stats['inserts'];
            $totalUpdates += $stats['updates'];
            $totalErreurs += $stats['erreurs'];
        }

        $io->table(
            ['Marque', 'Vues', 'Nouveaux', 'MAJ', 'Ignorées', 'Erreurs'],
            $rows,
        );

        // Les DG ont change : on rafraichit la vue materialisee de reconciliation.
        $this->reconciliation->rafraichirVue();

        $io->success(sprintf(
            'Total : %d nouveaux, %d MAJ, %d erreurs',
            $totalInserts,
            $totalUpdates,
            $totalErreurs,
        ));

        return $totalErreurs > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
