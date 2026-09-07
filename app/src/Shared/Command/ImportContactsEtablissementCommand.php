<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Entity\EtablissementContact;
use App\Shared\Repository\EtablissementContactRepository;
use App\Shared\Repository\EtablissementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe en masse des contacts e-mail d'etablissements depuis un CSV (une ligne
 * par contact : `code_etab,email`). Le role s'applique a tout le fichier
 * (--role=directeur par defaut ; relancer avec --role=secretaire pour les
 * secretaires). Idempotente : un e-mail deja present pour l'etablissement est
 * ignore. Les codes inconnus du referentiel sont signales, pas crees.
 */
#[AsCommand(
    name: 'app:shared:import-contacts-etablissements',
    description: 'Importe des contacts e-mail d\'etablissements depuis un CSV (code_etab,email).',
)]
final class ImportContactsEtablissementCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EtablissementRepository $etablissements,
        private readonly EtablissementContactRepository $contacts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('fichier', InputArgument::REQUIRED, 'Chemin du CSV (une ligne : code_etab,email)');
        $this->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role applique a tous les contacts (directeur|secretaire|autre)', 'directeur');
        $this->addOption('par', null, InputOption::VALUE_REQUIRED, 'Auteur pour l\'audit', 'Import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fichier = (string) $input->getArgument('fichier');
        $role = (string) $input->getOption('role');
        $par = (string) $input->getOption('par');

        if (!is_file($fichier)) {
            $io->error(sprintf('Fichier introuvable : %s', $fichier));

            return Command::FAILURE;
        }
        $handle = fopen($fichier, 'r');
        if (false === $handle) {
            $io->error(sprintf('Lecture impossible : %s', $fichier));

            return Command::FAILURE;
        }

        $crees = 0;
        $existants = 0;
        $introuvables = [];
        $invalides = [];

        while (false !== ($ligne = fgetcsv($handle, 0, ',', '"', '\\'))) {
            if (\count($ligne) < 2) {
                continue;
            }
            $codeBrut = trim((string) ($ligne[0] ?? ''));
            $email = EtablissementContact::normaliserEmail((string) ($ligne[1] ?? ''));

            if ('' === $codeBrut || !is_numeric($codeBrut)) {
                continue; // saute un eventuel en-tete ou une ligne vide
            }
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $invalides[] = $codeBrut.' / '.$email;
                continue;
            }

            $code = sprintf('%03d', (int) $codeBrut);
            $etab = $this->etablissements->find($code);
            if (null === $etab) {
                $introuvables[] = $code;
                continue;
            }

            $existeDeja = false;
            foreach ($this->contacts->pourEtablissement($code) as $contact) {
                if ($contact->getEmail() === $email) {
                    $existeDeja = true;
                    break;
                }
            }
            if ($existeDeja) {
                ++$existants;
                continue;
            }

            $this->em->persist(new EtablissementContact($etab, $email, $role, $par));
            ++$crees;
        }
        fclose($handle);
        $this->em->flush();

        $io->success(sprintf('%d contact(s) "%s" cree(s), %d deja present(s).', $crees, $role, $existants));
        if ([] !== $introuvables) {
            $io->warning(sprintf('%d code(s) introuvable(s) dans le referentiel : %s', \count($introuvables), implode(', ', array_unique($introuvables))));
        }
        if ([] !== $invalides) {
            $io->warning(sprintf('%d e-mail(s) invalide(s) ignore(s) : %s', \count($invalides), implode(', ', $invalides)));
        }

        return Command::SUCCESS;
    }
}
