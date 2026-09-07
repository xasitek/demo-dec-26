<?php

declare(strict_types=1);

namespace App\Garanties\Command;

use App\Garanties\Repository\ReconciliationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rafraichit la vue materialisee de reconciliation (garanties.mv_reconciliation).
 *
 * A lancer apres l'ETL Progiciel (qui modifie mirror.bal_eloficash). Decouple du
 * module Shared (l'ETL) : la chaine cron execute `app:etl:mirror-compta` puis
 * cette commande. La sync des DG (`app:garanties:sync-sheets`) rafraichit deja
 * la vue elle-meme.
 */
#[AsCommand(
    name: 'app:garanties:refresh-vue',
    description: 'Rafraichit la vue materialisee de reconciliation des garanties.',
)]
final class RefreshVueCommand extends Command
{
    public function __construct(private readonly ReconciliationRepository $reconciliation)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $debut = microtime(true);
        $this->reconciliation->rafraichirVue();
        $io->success(sprintf('Vue de reconciliation rafraichie en %ss.', round(microtime(true) - $debut, 1)));

        return Command::SUCCESS;
    }
}
