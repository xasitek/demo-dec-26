<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Entity\User;
use App\Shared\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Genere (ou supprime) des utilisateurs de demonstration pour tester l'interface
 * (scroll infini, filtres, stats). Les comptes de demo ont un email "demo.N@...".
 */
#[AsCommand(
    name: 'app:user:demo',
    description: 'Genere des utilisateurs de demonstration (ou les supprime avec --purge)',
)]
final class DemoUsersCommand extends Command
{
    private const PRENOMS = [
        'Velnau', 'Kirsol', 'Trikur', 'Nemvor', 'Dolbry', 'Fexlim', 'Plutal', 'Sabkal',
        'Kirdan', 'Fargil', 'Maevor', 'Sabfex', 'Osklim', 'Talgil', 'Pelnau',
        'Zorlim', 'Wensol', 'Ryndan', 'Brytal', 'Danfex', 'Kaltri', 'Vorwen', 'Solkir',
    ];

    private const NOMS = [
        'VELKUR', 'NAUTEG', 'DULCEWEN', 'KRESSNAU', 'TRIKAL', 'VOLTNEM', 'BRIXDAN',
        'CAVVOR', 'VOROSK', 'TEGOSK', 'GILNAU', 'KALVAL', 'SABRIN', 'NEMFERD',
        'PELGIL', 'CAVPEL', 'TALKUR', 'WENZOR', 'MURBRIX', 'ZORTAL',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Nombre d utilisateurs a generer', '30')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Supprime les utilisateurs de demonstration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('purge')) {
            $deleted = $this->em->createQuery(
                'DELETE FROM App\Shared\Entity\User u WHERE u.email LIKE :p'
            )->setParameter('p', 'demo.%@demonstration.invalid')->execute();

            $io->success(sprintf(
                '%d %s de demonstration %s.',
                $deleted,
                $deleted > 1 ? 'utilisateurs' : 'utilisateur',
                $deleted > 1 ? 'supprimes' : 'supprime',
            ));

            return Command::SUCCESS;
        }

        $count = max(1, (int) $input->getOption('count'));
        $rolesMetier = array_keys(Roles::METIER);

        for ($i = 1; $i <= $count; ++$i) {
            $prenom = self::PRENOMS[array_rand(self::PRENOMS)];
            $nom = self::NOMS[array_rand(self::NOMS)];

            $user = new User();
            $user->setEmail(sprintf('demo.%d@demonstration.invalid', $i))
                ->setFirstName($prenom)
                ->setLastName($nom);

            // Repartition : ~30% non habilites, le reste avec 1 ou 2 roles metier.
            if (random_int(1, 10) > 3) {
                shuffle($rolesMetier);
                $user->setRoles(\array_slice($rolesMetier, 0, random_int(1, 2)));
            }

            // ~15% desactives.
            $user->setIsActive(random_int(1, 100) > 15);

            // Derniere connexion aleatoire pour la plupart.
            if (random_int(1, 10) > 2) {
                $user->setLastLoginAt(new DateTimeImmutable(sprintf('-%d hours', random_int(1, 1440))));
            }

            $this->em->persist($user);
        }

        $this->em->flush();

        $io->success(sprintf(
            '%d %s de demonstration %s. Supprimez-les avec --purge.',
            $count,
            $count > 1 ? 'utilisateurs' : 'utilisateur',
            $count > 1 ? 'generes' : 'genere',
        ));

        return Command::SUCCESS;
    }
}
