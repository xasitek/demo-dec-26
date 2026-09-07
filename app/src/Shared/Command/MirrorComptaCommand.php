<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Service\SageMirrorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * ETL Progiciel -> schema mirror : synchronise les tables compta marquees comme
 * "quotidien" dans SageMirrorService::TABLES (par defaut : bal_eloficash,
 * balance_agee, tiers). La table reporting_ecritures (~12 M lignes, non
 * utilisee par les modules) est marquee non-quotidien : elle ne s'execute
 * que via `--complet`.
 *
 * Lancee quotidiennement par le cron (~06h45, apres le rechargement de Progiciel
 * a 06h30). Voir docs/ARCHITECTURE.md.
 *
 * Exemples :
 *   app:etl:mirror-compta                          (les tables quotidiennes)
 *   app:etl:mirror-compta --complet                (toutes les tables, y compris reporting_ecritures)
 *   app:etl:mirror-compta --table=t_ari_tiers_eloficash
 */
#[AsCommand(
    name: 'app:etl:mirror-compta',
    description: 'Synchronise les tables Progiciel compta vers le schéma mirror',
)]
final class MirrorComptaCommand extends Command
{
    public function __construct(
        private readonly SageMirrorService $mirror,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Limite la synchro à une seule table source Progiciel (ex: t_ari_tiers_eloficash)',
            )
            ->addOption(
                'complet',
                null,
                InputOption::VALUE_NONE,
                'Inclut aussi les tables non-quotidiennes (ex: reporting_ecritures)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tables = SageMirrorService::TABLES;
        $filtre = $input->getOption('table');
        $complet = (bool) $input->getOption('complet');

        if (\is_string($filtre)) {
            if (!isset($tables[$filtre])) {
                $io->error(sprintf(
                    'Table source "%s" inconnue. Tables valides : %s',
                    $filtre,
                    implode(', ', array_keys($tables)),
                ));

                return Command::FAILURE;
            }
            $tables = [$filtre => $tables[$filtre]];
        } elseif (!$complet) {
            // Mode quotidien (defaut) : on saute les tables marquees non-quotidien.
            $tables = array_filter($tables, static fn (array $config): bool => $config['quotidien']);
        }

        $io->title('ETL Progiciel → mirror');

        $debut = microtime(true);
        $lignes = [];
        $echecs = 0;

        foreach ($tables as $source => $config) {
            try {
                $stats = $this->mirror->synchroniser($source, $config['cible'], $config['cle']);
                $lignes[] = [
                    $config['cible'],
                    number_format($stats['traites'], 0, ',', ' '),
                    number_format($stats['disparus'], 0, ',', ' '),
                    'OK',
                ];
            } catch (Throwable $e) {
                ++$echecs;
                $lignes[] = [$config['cible'], '—', '—', 'ÉCHEC'];
                $io->warning(sprintf('%s : %s', $source, $e->getMessage()));
            }
        }

        $io->table(['Table mirror', 'Traitées', 'Disparues', 'Statut'], $lignes);

        $duree = round(microtime(true) - $debut, 1);

        if ($echecs > 0) {
            $io->error(sprintf(
                '%d %s en échec sur %d (%ss).',
                $echecs,
                1 === $echecs ? 'table' : 'tables',
                \count($tables),
                $duree,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('Synchronisation terminée en %ss.', $duree));

        return Command::SUCCESS;
    }
}
