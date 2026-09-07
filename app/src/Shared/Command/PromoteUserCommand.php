<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Attribue un role a un utilisateur (identifie par email).
 * Sert notamment a promouvoir le premier administrateur.
 * Exemple : app:user:promote copilote@demonstration.invalid ROLE_SUPER_ADMIN.
 */
#[AsCommand(
    name: 'app:user:promote',
    description: 'Attribue un rôle à un utilisateur (par email)',
)]
final class PromoteUserCommand extends Command
{
    /**
     * @var list<string>
     */
    private const VALID_ROLES = [
        'ROLE_USER',
        'ROLE_SECRETAIRE',
        'ROLE_COMPTABLE',
        'ROLE_AUDITEUR',
        'ROLE_MANAGER',
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
    ];

    public function __construct(
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, "Email de l'utilisateur")
            ->addArgument('role', InputArgument::REQUIRED, 'Rôle à attribuer (ex: ROLE_SUPER_ADMIN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $role = strtoupper((string) $input->getArgument('role'));

        if (!\in_array($role, self::VALID_ROLES, true)) {
            $io->error(sprintf(
                'Rôle invalide "%s". Rôles valides : %s',
                $role,
                implode(', ', self::VALID_ROLES),
            ));

            return Command::FAILURE;
        }

        $user = $this->users->findOneByEmail($email);

        if (null === $user) {
            $io->error(sprintf('Aucun utilisateur avec l\'email "%s".', $email));

            return Command::FAILURE;
        }

        if (\in_array($role, $user->getRoles(), true)) {
            $io->warning(sprintf('%s possède déjà le rôle %s.', $email, $role));

            return Command::SUCCESS;
        }

        // ROLE_USER est ajoute automatiquement par User::getRoles() : on ne le
        // stocke pas en base pour eviter la redondance.
        $stored = array_values(array_filter(
            array_unique([...$user->getRoles(), $role]),
            static fn (string $r): bool => 'ROLE_USER' !== $r,
        ));

        $user->setRoles($stored);
        $this->users->save($user);

        $io->success(sprintf('%s a désormais le rôle %s.', $email, $role));

        return Command::SUCCESS;
    }
}
