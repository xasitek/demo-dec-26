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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rappel QUOTIDIEN (cron 10h sur Render) :
 *   - 1 e-mail par SECRETAIRE : ses dossiers en correction requise (avec le lien vers
 *     "mes dossiers") + ses dossiers en attente de validation directeur (pour info,
 *     SANS bouton, elle ne peut pas agir dessus) ;
 *   - 1 e-mail par DIRECTEUR : tous ses dossiers a valider (liens signes + "tout valider").
 *
 * Les URL s'adaptent a l'environnement via DEFAULT_URI (dev : 127.0.0.1:8000 ; prod :
 * domaine reel). A programmer en cron : php bin/console app:remboursement:rappels
 */
#[AsCommand(
    name: 'app:remboursement:rappels',
    description: 'Rappels quotidiens : corrections aux secretaires, validations aux directeurs.',
)]
final class RappelsQuotidiensCommand extends Command
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly RemboursementMailer $mailer,
        private readonly EtablissementContactRepository $contacts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sample', null, InputOption::VALUE_NONE, "N'envoie qu'UN e-mail de chaque type (validation des templates).");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sample = (bool) $input->getOption('sample');

        $corrections = $this->dossiers->parStatut(DossierStatut::COMPLEMENT_REQUIS, 1000);
        $attente = $this->dossiers->parStatut(DossierStatut::A_VALIDER_DIRECTEUR, 1000);

        // --- Secretaires : groupees par createur (chaque secretaire recoit SES dossiers) ---
        /** @var array<string, array{corrections: list<Dossier>, attente: list<Dossier>}> $parSecretaire */
        $parSecretaire = [];
        foreach ($corrections as $d) {
            $s = trim((string) $d->getCreePar());
            if ('' === $s) {
                continue;
            }
            $parSecretaire[$s] ??= ['corrections' => [], 'attente' => []];
            $parSecretaire[$s]['corrections'][] = $d;
        }
        foreach ($attente as $d) {
            $s = trim((string) $d->getCreePar());
            if ('' === $s) {
                continue;
            }
            $parSecretaire[$s] ??= ['corrections' => [], 'attente' => []];
            $parSecretaire[$s]['attente'][] = $d;
        }

        // --- Directeurs : groupes par e-mail directeur (via l'etablissement du dossier) ---
        $map = $this->contacts->directeursParEtablissement();
        /** @var array<string, array{email: string, dossiers: list<Dossier>}> $parDirecteur */
        $parDirecteur = [];
        foreach ($attente as $d) {
            $code = (string) $d->getEtablissementCode();
            $email = '' !== $code ? trim((string) ($map[$code] ?? '')) : '';
            if ('' === $email) {
                continue;
            }
            $cle = strtolower($email);
            $parDirecteur[$cle] ??= ['email' => $email, 'dossiers' => []];
            $parDirecteur[$cle]['dossiers'][] = $d;
        }

        if ($sample) {
            $parSecretaire = \array_slice($parSecretaire, 0, 1, true);
            $parDirecteur = \array_slice($parDirecteur, 0, 1, true);
            $io->note('Mode --sample : un seul e-mail de chaque type.');
        }

        $nbSec = 0;
        foreach ($parSecretaire as $email => $g) {
            $this->mailer->rappelSecretaire($email, $g['corrections'], $g['attente']);
            $io->writeln(sprintf(' - secretaire %s : %d correction, %d en attente directeur', $email, \count($g['corrections']), \count($g['attente'])));
            ++$nbSec;
        }

        $nbDir = 0;
        foreach ($parDirecteur as $g) {
            $this->mailer->rappelDirecteur($g['email'], $g['dossiers']);
            $io->writeln(sprintf(' - directeur %s : %d a valider', $g['email'], \count($g['dossiers'])));
            ++$nbDir;
        }

        $io->success(sprintf('Rappels envoyes : %d secretaires, %d directeurs.', $nbSec, $nbDir));

        return Command::SUCCESS;
    }
}
