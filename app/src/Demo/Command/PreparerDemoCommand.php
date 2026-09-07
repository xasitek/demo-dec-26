<?php

declare(strict_types=1);

namespace App\Demo\Command;

use App\Demo\Persona;
use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepare l'environnement de demonstration : les profils synthetiques.
 *
 * Aucun de ces utilisateurs n'existe. Aucune adresse n'est deliverable : le
 * domaine .invalid est reserve par la norme et ne peut pas etre enregistre.
 * Ils servent uniquement a incarner les trois postes du circuit, pour que
 * l'examinateur puisse voir l'application avec les yeux de chacun.
 */
#[AsCommand(
    name: 'app:demo:preparer',
    description: 'Cree les profils synthetiques de la demonstration DEC.',
)]
final class PreparerDemoCommand extends Command
{
    /** Prenoms et noms composes a partir du lexique invente de la fabrique. */
    private const PRENOMS = ['Amelie', 'Nerdol', 'Sabmes', 'Ruokur', 'Kirvor', 'Maelim'];
    private const NOMS = ['Barnvor', 'Kressgil', 'Volttal', 'Quintdan', 'Sarnobry', 'Thanepel'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Profils synthetiques de la demonstration');

        $tousModules = array_map(static fn (Module $m): string => $m->value, Module::cases());
        $depot = $this->em->getRepository(User::class);
        $lignes = [];

        foreach (Persona::cases() as $i => $poste) {
            $u = $depot->findOneBy(['email' => $poste->email()]) ?? new User();
            $u->setEmail($poste->email());
            $u->setFirstName(self::PRENOMS[$i % \count(self::PRENOMS)]);
            $u->setLastName(self::NOMS[$i % \count(self::NOMS)]);
            $u->setRoles($poste->roles());
            $u->setModules($tousModules);
            $u->setIsActive(true);
            $this->em->persist($u);

            // Le PÉRIMÈTRE est la différence : un directeur de concession ne voit
            // qu'un établissement, et c'est ce qui fait mordre le contrôle de
            // périmètre du circuit de validation.
            $lignes[] = [
                $poste->libelle(),
                $u->getFirstName().' '.$u->getLastName(),
                implode(', ', $poste->roles()),
                0 === $poste->nbEtablissements() ? 'tous' : $poste->nbEtablissements().' établissement',
            ];
        }

        $this->em->flush();
        $io->table(['Poste', 'Profil', 'Roles', 'Ce qu\'il fait'], $lignes);
        $io->success(\count(Persona::cases()).' profils synthetiques prets. Aucune adresse n\'est deliverable.');

        return Command::SUCCESS;
    }
}
