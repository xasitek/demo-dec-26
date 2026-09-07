<?php

declare(strict_types=1);

namespace App\Livraison\Command;

use App\Livraison\Repository\VehiculeALivrerRepository;
use App\Shared\Service\GoogleSheetsClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 1 de la migration Livraison : prouver que `livraison.v_a_livrer`
 * reproduit l'onglet `ESPACE LIVREUR` du classeur Google.
 *
 * Strictement en lecture, des deux cotes. Rien n'est ecrit nulle part.
 *
 * La comparaison se fait sur la **cle d'ecriture** : la colonne A du Sheet
 * (`numero`) correspond a la cle JSONB `clé écriture` de `mirror.bal_eloficash`,
 * correspondance verifiee a 465/465 le 2026-09-01. L'immatriculation sert de
 * second axe, parce que c'est l'unite metier (un vehicule, plusieurs factures).
 *
 * Tant que cette commande ne rend pas un ecart explicable, on ne construit rien
 * au-dessus. Voir docs/MODULE_LIVRAISON.md sections 3 et 8.
 */
#[AsCommand(
    name: 'app:livraison:parite',
    description: 'Compare livraison.v_a_livrer a l\'onglet ESPACE LIVREUR du classeur Google',
)]
final class ControlePariteCommand extends Command
{
    /** Colonnes de l'onglet : A numero, C loueur, D client-immat, I statut. */
    private const PLAGE = "'ESPACE LIVREUR'!A2:I";

    public function __construct(
        private readonly VehiculeALivrerRepository $repository,
        private readonly GoogleSheetsClient $sheets,
        private readonly string $spreadsheetId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'details',
            null,
            InputOption::VALUE_NONE,
            'Liste les ecritures en ecart au lieu de n\'en donner que le compte',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Controle de parite — Espace Livraison');

        if ('' === $this->spreadsheetId) {
            $io->error('LIVRAISON_ESPACE_LIVREUR_SHEET_ID n\'est pas configure.');

            return Command::FAILURE;
        }

        // --- Cote Sheet -----------------------------------------------------
        $io->section('Lecture du classeur Google');
        $lignesSheet = $this->sheets->readRange($this->spreadsheetId, self::PLAGE);

        $sheet = [];
        foreach ($lignesSheet as $ligne) {
            $numero = trim($ligne[0] ?? '');
            if ('' === $numero || !ctype_digit($numero)) {
                continue;
            }
            $sheet[$numero] = [
                'loueur' => trim($ligne[2] ?? ''),
                'immat' => $this->extraireImmatriculation($ligne[3] ?? ''),
                'statut' => trim($ligne[8] ?? ''),
            ];
        }

        $immatsSheet = array_filter(array_column($sheet, 'immat'));
        $io->writeln(sprintf(
            '  %d ecritures, %d immatriculations distinctes',
            \count($sheet),
            \count(array_unique($immatsSheet)),
        ));

        $parStatut = array_count_values(array_column($sheet, 'statut'));
        foreach ($parStatut as $statut => $n) {
            $io->writeln(sprintf('    %-12s %d', '' === $statut ? '(vide)' : $statut, $n));
        }

        // --- Cote base ------------------------------------------------------
        $io->section('Lecture de livraison.v_a_livrer');
        $vue = $this->repository->clesEcriture();
        $io->writeln(sprintf(
            '  %d ecritures, %d immatriculations distinctes',
            \count($vue),
            $this->repository->nombreImmatriculations(),
        ));

        // --- Comparaison ----------------------------------------------------
        $io->section('Ecarts');

        $manquantes = array_diff_key($sheet, $vue);   // dans le Sheet, absentes de la vue
        $enTrop = array_diff_key($vue, $sheet);       // dans la vue, absentes du Sheet
        $communes = \count($sheet) - \count($manquantes);

        $io->definitionList(
            ['Communes aux deux' => (string) $communes],
            ['Sheet seulement' => (string) \count($manquantes)],
            ['Vue seulement' => (string) \count($enTrop)],
            ['Taux de couverture' => 0 === \count($sheet)
                ? 'n/a'
                : sprintf('%.1f %%', 100 * $communes / \count($sheet))],
        );

        if ($manquantes && $input->getOption('details')) {
            $io->writeln('<comment>Presentes dans le Sheet, absentes de la vue :</comment>');
            $io->table(
                ['Cle ecriture', 'Loueur', 'Immatriculation', 'Statut'],
                array_map(
                    // PHP convertit les cles numeriques en int : d'ou int|string.
                    static fn (int|string $cle, array $l): array => [(string) $cle, $l['loueur'], $l['immat'], $l['statut']],
                    array_keys($manquantes),
                    $manquantes,
                ),
            );
        }

        if ($enTrop && $input->getOption('details')) {
            $io->writeln('<comment>Presentes dans la vue, absentes du Sheet :</comment>');
            $io->listing(array_slice(array_keys($enTrop), 0, 50));
        }

        // --- Controles complementaires --------------------------------------
        $io->section('Repartition par loueur (vue)');
        $io->table(
            ['Loueur', 'Immatriculations', 'Factures'],
            array_map(
                static fn (array $l): array => [$l['loueur'], (string) $l['immats'], (string) $l['factures']],
                $this->repository->repartitionParLoueur(),
            ),
        );

        $auDela = $this->repository->immatriculationsAuDelaDe(6);
        if ($auDela) {
            $io->warning(sprintf(
                '%d immatriculations portent plus de 6 factures — le tableur les tronquait.',
                \count($auDela),
            ));
        }

        if (0 === \count($manquantes)) {
            $io->success('Parite totale : la vue couvre l\'integralite du Sheet.');

            return Command::SUCCESS;
        }

        $io->note(sprintf(
            'Ecart de %d ecriture(s). Relancer avec --details pour les lister. '
            .'Un ecart residuel est attendu : le Sheet est une photo, la vue est vivante.',
            \count($manquantes),
        ));

        return Command::SUCCESS;
    }

    /**
     * La colonne D vaut « CODECLIENT | IMMAT », parfois prefixee d'un indicateur.
     * On ne garde que la plaque.
     */
    private function extraireImmatriculation(string $brut): string
    {
        $parties = explode('|', $brut);

        return strtoupper(trim(end($parties)));
    }
}
