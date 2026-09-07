<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Enum\RelanceVecteur;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fabrique 2-3 courriers de démonstration (relances papier, statut A_ENVOYER) à
 * partir de clients réels SANS email, pour alimenter la vue « Courriers ».
 *
 * Idempotent : purge d'abord les courriers de test encore en attente (A_ENVOYER)
 * avant d'en recréer. Usage dev/démo uniquement, aucune dépendance réseau.
 */
#[AsCommand(
    name: 'app:recouvrement:courrier-demo',
    description: 'Crée 2-3 courriers de démonstration (clients sans email, niveaux 1/2/3).',
)]
final class CourrierDemoCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recouvrement : courriers de démonstration');

        // Purge des courriers de démo encore en attente (re-jouable proprement).
        $this->connection->executeStatement(
            "DELETE FROM recouvrement.relance_envoi WHERE vecteur = 'courrier' AND statut = 'a_envoyer'",
        );

        /** @var list<array{compte: ?string, nb: int|string, total: int|string|float}> $comptes */
        $comptes = $this->connection->fetchAllAssociative(
            'SELECT v.compte, count(*) AS nb, COALESCE(sum(v.montant_solde), 0) AS total '
            .'FROM recouvrement.v_impayes v '
            .'LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte '
            .'WHERE v.jours_retard > 0 AND v.montant_solde > 0 AND v.compte IS NOT NULL '
            ."AND (v.email IS NULL OR v.email = '') "
            ."AND (e.etat IS NULL OR e.etat <> 'ecarte') "
            .'GROUP BY v.compte ORDER BY total DESC LIMIT 3',
        );

        if ([] === $comptes) {
            $io->warning('Aucun client sans email trouvé : impossible de fabriquer des courriers de démo.');

            return Command::SUCCESS;
        }

        $niveaux = [1, 2, 3];
        $rows = [];
        foreach ($comptes as $i => $c) {
            $compte = (string) $c['compte'];
            $niveau = $niveaux[$i] ?? 1;

            $relance = new RelanceEnvoi(
                $compte,
                $niveau,
                RelanceVecteur::COURRIER,
                RelanceStatut::A_ENVOYER,
                bin2hex(random_bytes(16)),
            );
            $relance->setNbFactures((int) $c['nb']);
            $relance->setMontantSolde((string) $c['total']);
            $relance->setPrepareLe(new DateTimeImmutable());

            $this->entityManager->persist($relance);
            $rows[] = [$compte, $niveau, (int) $c['nb'], number_format((float) $c['total'], 2, ',', ' ').' EUR'];
        }
        $this->entityManager->flush();

        $io->table(['Compte', 'Niveau', 'Nb factures', 'Montant'], $rows);
        $io->success(sprintf('%d courriers de démonstration créés (statut À envoyer).', \count($rows)));

        return Command::SUCCESS;
    }
}
