<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\RemboursementMailer;
use App\Shared\Repository\EtablissementContactRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Previsualisation : envoie les TROIS e-mails REELS du module (refus / correction /
 * validation directeur) via RemboursementMailer, donc avec les VRAIS liens signes
 * (les boutons directeur fonctionnent) et la file directeur reelle. N'ecrit rien en
 * base, ne declenche aucune transition. Livre a l'adresse de test (RECOUVREMENT_FORCE_TO).
 *
 *   php bin/console app:remboursement:preview-emails
 */
#[AsCommand(
    name: 'app:remboursement:preview-emails',
    description: 'Envoie les 3 e-mails Remboursement (liens signes reels) pour previsualisation / test.',
)]
final class PreviewEmailsCommand extends Command
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly RemboursementMailer $mailer,
        private readonly EtablissementContactRepository $contacts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dossier = $this->dossiers->findOneBy([], ['id' => 'DESC']);
        if (!$dossier instanceof Dossier) {
            $io->error('Aucun dossier en base — lance d\'abord app:remboursement:seed-test.');

            return Command::FAILURE;
        }

        $message = "Bonjour,\n\nLe RIB fourni ne correspond pas au nom du client indiqué sur le dossier. "
            ."Merci de joindre un RIB au nom du titulaire du remboursement.\n\nCordialement,\nService Remboursement client";

        $io->title('Envoi des e-mails Remboursement (liens signes reels)');

        $this->mailer->refus($dossier, $message);
        $io->writeln(' - refus envoye');

        $this->mailer->correction($dossier, $message, ['RIB', 'Relevé ICAR']);
        $io->writeln(' - correction envoyee');

        // Directeur : un dossier A_VALIDER_DIRECTEUR dont l'etablissement A un directeur.
        $map = $this->contacts->directeursParEtablissement();
        $pourDirecteur = null;
        foreach ($this->dossiers->parStatut(DossierStatut::A_VALIDER_DIRECTEUR, 50) as $d) {
            if (isset($map[(string) $d->getEtablissementCode()])) {
                $pourDirecteur = $d;
                break;
            }
        }
        if ($pourDirecteur instanceof Dossier) {
            $this->mailer->directeur($pourDirecteur);
            $io->writeln(sprintf(' - validation directeur envoyee (dossier %s, liens signes actifs)', $pourDirecteur->getReference()));
        } else {
            $io->warning('Aucun dossier "a valider directeur" avec un directeur renseigne : e-mail directeur non envoye.');
        }

        $io->success('E-mails envoyes (livres a l\'adresse de test).');

        return Command::SUCCESS;
    }
}
