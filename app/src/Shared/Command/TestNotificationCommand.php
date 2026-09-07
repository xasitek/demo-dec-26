<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Repository\UserRepository;
use App\Shared\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie une notification de test a un utilisateur (pour verifier le socle).
 * Ex : php bin/console app:notif:test email@demonstration.invalid.
 */
#[AsCommand(
    name: 'app:notif:test',
    description: 'Envoie une notification de test a un utilisateur',
)]
final class TestNotificationCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email du destinataire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $user = $this->users->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('Aucun utilisateur avec l\'email "%s".', $email));

            return Command::FAILURE;
        }

        $this->notifications->notifier(
            $user,
            'Notification de test',
            'Si vous voyez ceci, le socle de notifications fonctionne.',
            '/',
            'info',
        );

        $io->success(sprintf('Notification de test envoyee a %s.', $user->getFullName()));

        return Command::SUCCESS;
    }
}
