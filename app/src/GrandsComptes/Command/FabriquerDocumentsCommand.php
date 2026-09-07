<?php

declare(strict_types=1);

namespace App\GrandsComptes\Command;

use App\GrandsComptes\Moteur\Fabricant;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ecrit la bibliotheque de documents de demonstration.
 *
 * De vrais fichiers, sur le disque, un par piece des dossiers vedettes : un par
 * scenario, pour que chaque cas de la doctrine ait son document ouvrable. Le
 * jury clique sur une piece et la voit -- et ce qu'il voit porte exactement ce
 * que la regle a lu.
 *
 * Tous ces documents sont FICTIFS, generes ici, et chacun porte le filigrane
 * qui le dit.
 */
#[AsCommand(
    name: 'app:grands-comptes:documents',
    description: 'Ecrit les documents synthetiques des dossiers vedettes.',
)]
final class FabriquerDocumentsCommand extends Command
{
    public function __construct(
        private readonly Connection $cnx,
        private readonly Fabricant $fabricant,
        private readonly string $racineProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('par-scenario', null, InputOption::VALUE_REQUIRED,
            'Combien de dossiers vedettes par scenario.', '2');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Bibliotheque de documents de demonstration');

        $parScenario = max(1, (int) $entree->getOption('par-scenario'));

        // Les dossiers vedettes : les premiers de chaque scenario, pour que
        // chaque cas de la doctrine ait un document ouvrable.
        /** @var list<array<string, mixed>> $vedettes */
        $vedettes = $this->cnx->fetchAllAssociative(
            'SELECT id, code_scenario FROM (
               SELECT id, code_scenario,
                      row_number() OVER (PARTITION BY code_scenario ORDER BY id) r
                 FROM grands_comptes.dossier
             ) x WHERE r <= ? ORDER BY code_scenario, id', [$parScenario]);

        $racine = $this->racineProjet.'/public/pieces';
        if (!is_dir($racine) && !mkdir($racine, 0o777, true) && !is_dir($racine)) {
            $io->error('Impossible de creer '.$racine);

            return Command::FAILURE;
        }

        $ecrits = 0;
        $parType = [];
        $index = [];

        foreach ($vedettes as $v) {
            $dossierId = (string) $v['id'];
            /** @var list<array<string, mixed>> $pieces */
            $pieces = $this->cnx->fetchAllAssociative(
                'SELECT id, type_piece, presente FROM grands_comptes.piece
                  WHERE dossier_id = ? ORDER BY type_piece', [$dossierId]);

            $repertoire = $racine.'/'.$dossierId;
            if (!is_dir($repertoire) && !mkdir($repertoire, 0o777, true) && !is_dir($repertoire)) {
                continue;
            }

            foreach ($pieces as $p) {
                // Une piece absente n'a pas de document : c'est le fait meme.
                if (!$p['presente']) {
                    continue;
                }
                $doc = $this->fabricant->document((string) $p['id']);
                if (null === $doc) {
                    continue;
                }
                $chemin = $repertoire.'/'.$p['type_piece'].'.html';
                file_put_contents($chemin, $doc['html']);
                ++$ecrits;
                $t = (string) $p['type_piece'];
                $parType[$t] = ($parType[$t] ?? 0) + 1;
                $index[] = ['dossier' => $dossierId, 'scenario' => (string) $v['code_scenario'],
                    'type' => $t, 'fichier' => 'pieces/'.$dossierId.'/'.$t.'.html'];
            }
        }

        file_put_contents($racine.'/index.json',
            json_encode($index, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        ksort($parType);
        $io->table(['Type de piece', 'Documents ecrits'], array_map(
            static fn (string $t, int $n): array => [$t, (string) $n],
            array_keys($parType), array_values($parType)));

        $io->success(sprintf('%d documents ecrits pour %d dossiers vedettes, dans public/pieces/.',
            $ecrits, \count($vedettes)));
        $io->text('Chacun porte le filigrane « DOCUMENT FICTIF — DÉMONSTRATION ».');

        return Command::SUCCESS;
    }
}
