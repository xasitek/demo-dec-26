<?php

declare(strict_types=1);

namespace App\GrandsComptes\Command;

use App\GrandsComptes\Moteur\Controle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La contre-epreuve du controle de conformite.
 *
 * Un moteur qui obtient 100 % sur une population construite par les memes
 * regles ne demontre rien tout seul : il faut prouver que les pieges existent.
 * Cette commande fait tourner quatre variantes NAIVES du controle sur la MEME
 * population, chacune privee d'une seule regle de doctrine, et compte ce
 * qu'elles cassent.
 *
 * C'est l'equivalent de la comparaison RAW/ENRICHED de l'outil 4 : la mesure
 * ne dit pas « le moteur est bon », elle dit « voila ce que couterait de s'en
 * passer ».
 */
#[AsCommand(
    name: 'app:grands-comptes:contre-epreuve',
    description: 'Fait tourner quatre variantes naives du controle et compte ce qu\'elles cassent.',
)]
final class ContreEpreuveCommand extends Command
{
    public function __construct(
        private readonly Connection $cnx,
        private readonly Controle $controle,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('cohorte', null, InputOption::VALUE_REQUIRED,
            'La cohorte a passer. Par defaut CALIBRATION_O7.', 'CALIBRATION_O7');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Contre-epreuve : ce que couterait de se passer de la doctrine');

        $cohorte = (string) $entree->getOption('cohorte');
        /** @var list<string> $ids */
        $ids = $this->cnx->fetchFirstColumn(
            'SELECT id FROM grands_comptes.dossier WHERE cohorte = ? ORDER BY id', [$cohorte]);
        if ([] === $ids) {
            $io->error('Cohorte vide : '.$cohorte);

            return Command::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $verite */
        $verite = $this->cnx->fetchAllAssociativeIndexed(
            'SELECT objet_id, verdict_attendu, anomalies_attendues
               FROM grands_comptes_verite.dossier WHERE cohorte = ?', [$cohorte]);

        $io->text(sprintf('%s dossiers de la cohorte %s, cinq variantes.',
            number_format(\count($ids), 0, ',', ' '), $cohorte));

        $table = [];
        foreach (array_keys(Controle::VARIANTES) as $variante) {
            $verdictsFaux = 0;
            $nonConformesATort = 0;
            $incompletsPerdus = 0;
            $fauxPositifs = 0;
            $manques = 0;

            foreach ($ids as $id) {
                $r = $this->controle->analyser($id, $variante);
                $attendu = (string) ($verite[$id]['verdict_attendu'] ?? '');
                $attendues = array_filter(explode('|', (string) ($verite[$id]['anomalies_attendues'] ?? '')));
                $trouvees = array_map(static fn (array $a): string => (string) $a['code'], $r['anomalies']);

                if ($r['verdict'] !== $attendu) {
                    ++$verdictsFaux;
                }
                if ('non_conforme' === $r['verdict'] && 'non_conforme' !== $attendu) {
                    ++$nonConformesATort;
                }
                if ('incomplet' === $attendu && 'incomplet' !== $r['verdict']) {
                    ++$incompletsPerdus;
                }
                $fauxPositifs += \count(array_diff($trouvees, $attendues));
                $manques += \count(array_diff($attendues, $trouvees));
            }

            $table[] = [
                $variante,
                number_format($verdictsFaux, 0, ',', ' '),
                number_format($nonConformesATort, 0, ',', ' '),
                number_format($incompletsPerdus, 0, ',', ' '),
                number_format($fauxPositifs, 0, ',', ' '),
                number_format($manques, 0, ',', ' '),
            ];
        }

        $io->table([
            'Variante', 'Verdicts faux', 'Non conformes à tort',
            'Doutes tranchés à tort', 'Anomalies inventées', 'Anomalies manquées',
        ], $table);

        $io->section('Ce que chaque variante abandonne');
        foreach (Controle::VARIANTES as $code => $quoi) {
            $io->text(sprintf('  <info>%-16s</info> %s', $code, $quoi));
        }

        $io->newLine();
        $io->text('La ligne « strict » doit etre a zero partout. Les quatre autres mesurent le');
        $io->text('cout de la regle qu\'elles abandonnent : c\'est cela qui donne un sens au 100 %.');

        return Command::SUCCESS;
    }
}
