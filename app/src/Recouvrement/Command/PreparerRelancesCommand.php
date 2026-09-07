<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Message\EnvoyerRelance;
use App\Recouvrement\Repository\RecouvrementRepository;
use App\Recouvrement\Repository\RelanceEnvoiRepository;
use App\Recouvrement\Service\EnvoiRelanceService;
use App\Recouvrement\Service\SelectionRelanceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Prepare les relances d'impayes a envoyer maintenant et les dispatch en
 * asynchrone (transport Redis via Messenger).
 *
 * Une relance = un COMPTE client (envoi groupe : un seul email/releve listant
 * toutes ses factures echues impayees, cf. SelectionRelanceService).
 *
 * Flux normal, par lot, pour chaque compte a relancer :
 *   1. pre-creation du RelanceEnvoi en statut A_ENVOYER (trace en base AVANT
 *      l'envoi ; un garde-fou refuse de re-preparer un palier deja pris en charge,
 *      et le worker re-verifie l'idempotence juste avant l'envoi reel) ;
 *   2. dispatch d'un message EnvoyerRelance portant l'id + le groupe fige ;
 *   3. le worker (consume) realisera l'envoi reel et passera a ENVOYE / ECHEC.
 *
 * En --dry-run : aucune ecriture ni dispatch ; seulement un recapitulatif du plan
 * (nb comptes/relances, factures, montant) par profil et par niveau.
 *
 * @phpstan-import-type GroupeARelancer from SelectionRelanceService
 */
#[AsCommand(
    name: 'app:recouvrement:preparer',
    description: 'Prepare les relances d\'impayes a envoyer et les dispatch en asynchrone.',
)]
final class PreparerRelancesCommand extends Command
{
    /** Taille des lots : flush + clear de l'UnitOfWork pour borner la memoire. */
    private const TAILLE_LOT = 50;

    /**
     * Plafond de securite par run quand ni --limit ni --tout ne sont fournis :
     * evite de preparer des milliers de relances d'un coup (1er run sur backlog).
     */
    private const PLAFOND_DEFAUT = 500;

    public function __construct(
        private readonly SelectionRelanceService $selectionRelanceService,
        private readonly EnvoiRelanceService $envoiRelanceService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RelanceEnvoiRepository $relances,
        private readonly RecouvrementRepository $recouvrement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Affiche le plan des relances sans rien preparer ni envoyer.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre maximal de comptes (clients) a relancer sur ce run.',
            )
            ->addOption(
                'tout',
                null,
                InputOption::VALUE_NONE,
                'Leve le plafond de securite et prepare TOUS les comptes eligibles (a utiliser en connaissance de cause).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $tout = (bool) $input->getOption('tout');
        $limit = $this->lireLimit($input, $io);
        if (false === $limit) {
            return Command::INVALID;
        }

        // Garde-fou : sans --limit ni --tout, on applique un plafond de securite
        // pour ne jamais preparer des milliers de relances d'un coup (1er run).
        $plafondAuto = false;
        if (null === $limit && !$tout) {
            $limit = self::PLAFOND_DEFAUT;
            $plafondAuto = true;
        }

        $io->title('Recouvrement : preparation des relances'.($dryRun ? ' (simulation)' : ''));

        if ($plafondAuto) {
            $io->note(sprintf(
                'Plafond de securite : %d comptes maximum ce run. Utilisez --limit=N pour ajuster, --tout pour tout preparer.',
                self::PLAFOND_DEFAUT,
            ));
        }

        // Vue materialisee des impayes : on la rafraichit AVANT toute selection pour
        // travailler sur le retard du JOUR (jours_retard = CURRENT_DATE au refresh).
        // La chaine cron la rafraichit deja apres l'ETL ; ce refresh de securite
        // couvre un cron mal ordonne. Best-effort : en cas d'echec on poursuit sur
        // le dernier snapshot plutot que de bloquer les relances.
        try {
            $this->recouvrement->rafraichirImpayes();
        } catch (Throwable $e) {
            $io->warning('Rafraichissement des impayes impossible, poursuite sur le dernier etat.');
            $this->logger->warning('Refresh v_impayes echoue : {message}', ['message' => $e->getMessage()]);
        }

        // Remise a zero du cycle : archive les relances des comptes desormais
        // soldes (plus aucune facture echue) AVANT de selectionner -> une dette
        // re-facturee repart au niveau 1. Ecriture, donc hors simulation.
        if (!$dryRun) {
            $closes = $this->relances->cloreCyclesSoldes();
            if ($closes > 0) {
                $io->writeln(sprintf('<comment>%d</comment> relance(s) de comptes soldes archivee(s) (cycle clos).', $closes));
            }
        }

        $debut = microtime(true);
        $groupes = $this->selectionRelanceService->aRelancer($limit);

        if ([] === $groupes) {
            $io->success('Aucun client a relancer.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            '<info>%d</info> %s a relancer.',
            \count($groupes),
            $this->pluriel(\count($groupes), 'client', 'clients'),
        ));
        $io->newLine();

        if ($dryRun) {
            $this->afficherRepartitionVecteur($io, $groupes);
            $this->afficherPlan($io, $groupes);
            $io->note('Mode simulation : aucune relance preparee ni dispatchee.');

            return Command::SUCCESS;
        }

        $resultat = $this->preparerEtDispatcher($io, $groupes);

        $io->newLine();
        $io->success(sprintf(
            '%d email%s dispatche%s, %d courrier%s en attente, %d ignore%s, en %ss.',
            $resultat['emails'],
            $resultat['emails'] > 1 ? 's' : '',
            $resultat['emails'] > 1 ? 's' : '',
            $resultat['courriers'],
            $resultat['courriers'] > 1 ? 's' : '',
            $resultat['ignorees'],
            $resultat['ignorees'] > 1 ? 's' : '',
            round(microtime(true) - $debut, 1),
        ));

        return Command::SUCCESS;
    }

    /**
     * Pre-cree les RelanceEnvoi (A_ENVOYER) par lot. Pour un vecteur EMAIL, on
     * dispatch ensuite un message d'envoi asynchrone ; pour un vecteur COURRIER
     * (client sans email), on NE dispatch PAS : la lettre reste en attente
     * (statut A_ENVOYER) dans la vue "Courriers a envoyer", ou le comptable la
     * telecharge, la poste, puis la marque envoyee a la main.
     *
     * Anti-doublon : un palier deja prepare (a_envoyer) ou envoye leve une
     * exception dans preparer() ; elle est traitee ici comme un "ignore", sans
     * interrompre le run. La garantie stricte contre le double-envoi reste cote
     * worker (EnvoiRelanceService verifie (compte, niveau) juste avant d'envoyer).
     *
     * @param list<GroupeARelancer> $groupes
     *
     * @return array{emails: int, courriers: int, ignorees: int}
     */
    private function preparerEtDispatcher(SymfonyStyle $io, array $groupes): array
    {
        $total = \count($groupes);
        $emails = 0;
        $courriers = 0;
        $ignorees = 0;

        $io->progressStart($total);

        foreach ($groupes as $index => $groupe) {
            try {
                $relance = $this->envoiRelanceService->preparer($groupe);
                $relanceId = $relance->getId();
                if (null === $relanceId) {
                    ++$ignorees;
                    $io->progressAdvance();

                    continue;
                }

                if (RelanceVecteur::COURRIER === $groupe['vecteur']) {
                    // Courrier papier : prepare mais non dispatche (attente manuelle).
                    ++$courriers;
                } else {
                    $this->messageBus->dispatch(new EnvoyerRelance($relanceId, $groupe));
                    ++$emails;
                }
            } catch (Throwable $e) {
                // Cas nominal : le palier (compte_code, niveau) est deja prepare ou
                // envoye (garde-fou de preparer()) -> on l'ignore sans casser le run.
                ++$ignorees;
                $this->logger->warning('Relance non preparee (probable doublon)', [
                    'compte_code' => $groupe['compte_code'],
                    'niveau' => $groupe['niveau'],
                    'erreur' => $e->getMessage(),
                ]);
            }

            // Borne la memoire et les transactions ouvertes sur les gros volumes.
            if (0 === ($index + 1) % self::TAILLE_LOT) {
                $this->entityManager->clear();
                $this->logger->info('Preparation relances : progression', [
                    'traitees' => $index + 1,
                    'total' => $total,
                ]);
            }

            $io->progressAdvance();
        }

        $this->entityManager->clear();
        $io->progressFinish();

        return ['emails' => $emails, 'courriers' => $courriers, 'ignorees' => $ignorees];
    }

    /**
     * Repartition des relances par vecteur : combien partiront par email
     * (envoi automatique) et combien en courrier papier (clients sans email,
     * en attente d'impression/postage manuel).
     *
     * @param list<GroupeARelancer> $groupes
     */
    private function afficherRepartitionVecteur(SymfonyStyle $io, array $groupes): void
    {
        $emails = 0;
        $courriers = 0;
        foreach ($groupes as $groupe) {
            if (RelanceVecteur::COURRIER === $groupe['vecteur']) {
                ++$courriers;
            } else {
                ++$emails;
            }
        }

        $io->table(
            ['Vecteur', 'Clients'],
            [
                ['E-mail (envoi auto)', (string) $emails],
                ['Courrier (en attente)', (string) $courriers],
            ],
        );
    }

    /**
     * Recapitulatif du plan par profil puis par niveau : nb de comptes
     * (relances), nb de factures listees, montant total.
     *
     * @param list<GroupeARelancer> $groupes
     */
    private function afficherPlan(SymfonyStyle $io, array $groupes): void
    {
        /** @var array<string, array<int, array{relances: int, factures: int, montant: float}>> $parProfil */
        $parProfil = [];

        foreach ($groupes as $groupe) {
            $profil = $groupe['regle_nom'];
            $niveau = $groupe['niveau'];

            if (!isset($parProfil[$profil][$niveau])) {
                $parProfil[$profil][$niveau] = ['relances' => 0, 'factures' => 0, 'montant' => 0.0];
            }

            ++$parProfil[$profil][$niveau]['relances'];
            $parProfil[$profil][$niveau]['factures'] += $groupe['nb_factures'];
            $parProfil[$profil][$niveau]['montant'] += (float) $groupe['total'];
        }

        ksort($parProfil);

        $rows = [];
        $totalRelances = 0;
        $totalFactures = 0;
        $totalMontant = 0.0;

        foreach ($parProfil as $profil => $niveaux) {
            ksort($niveaux);
            foreach ($niveaux as $niveau => $agg) {
                $rows[] = [
                    $profil,
                    (string) $niveau,
                    (string) $agg['relances'],
                    (string) $agg['factures'],
                    number_format($agg['montant'], 2, ',', ' ').' EUR',
                ];
                $totalRelances += $agg['relances'];
                $totalFactures += $agg['factures'];
                $totalMontant += $agg['montant'];
            }
        }

        $io->table(
            ['Règle', 'Niveau', 'Clients', 'Factures', 'Montant'],
            $rows,
        );
        $io->writeln(sprintf(
            'Total : <info>%d</info> %s, <info>%d</info> %s, <info>%s EUR</info>.',
            $totalRelances,
            $this->pluriel($totalRelances, 'client', 'clients'),
            $totalFactures,
            $this->pluriel($totalFactures, 'facture', 'factures'),
            number_format($totalMontant, 2, ',', ' '),
        ));
    }

    /**
     * Lit et valide l'option --limit.
     *
     * @return int|false|null int = limite, null = pas de limite, false = invalide
     */
    private function lireLimit(InputInterface $input, SymfonyStyle $io): int|false|null
    {
        $brut = $input->getOption('limit');
        if (null === $brut) {
            return null;
        }

        if (!is_numeric($brut) || (int) $brut < 1) {
            $io->error('L\'option --limit doit etre un entier strictement positif.');

            return false;
        }

        return (int) $brut;
    }

    private function pluriel(int $nombre, string $singulier, string $pluriel): string
    {
        return abs($nombre) > 1 ? $pluriel : $singulier;
    }
}
