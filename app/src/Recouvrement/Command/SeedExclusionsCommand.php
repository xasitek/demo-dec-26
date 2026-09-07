<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * OBSOLETE (2026-07-22). Depuis le moteur de regles de relance, la curation
 * AUTOMATIQUE par statut juridique / mode de paiement / garantie n'a plus lieu
 * d'etre : le perimetre relancable est defini par les REGLES (collectifs + filtres),
 * et on relance desormais tous les statuts. Cette commande ecartait a tort des
 * comptes (statut F, code 9, volume anormal...) -> neutralisee.
 *
 * Seule la curation MANUELLE subsiste (app:recouvrement:ecarter / :reactiver, ou
 * la vue Curation). Commande conservee en no-op pour ne pas casser un eventuel cron
 * residuel : elle n'ecrit plus rien.
 */
#[AsCommand(
    name: 'app:recouvrement:seed-exclusions',
    description: 'OBSOLETE : curation auto remplacee par le moteur de regles (ne fait rien).',
)]
final class SeedExclusionsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->warning(
            'Commande obsolete : la selection est desormais pilotee par les regles de relance '
            .'(vue Strategies). Aucune exclusion automatique n\'est calculee. '
            .'Utilisez la curation manuelle (app:recouvrement:ecarter / :reactiver) si besoin.',
        );

        return Command::SUCCESS;
    }
}
