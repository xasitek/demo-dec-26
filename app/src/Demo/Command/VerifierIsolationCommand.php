<?php

declare(strict_types=1);

namespace App\Demo\Command;

use App\Demo\Http\SortieReseauInterdite;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le test d'independance, execute plutot qu'affirme.
 *
 * L'auteur a pose la regle ainsi : si les depots, l'entrepot et les
 * applications du groupe devenaient inaccessibles, la suite jury doit continuer
 * a fonctionner integralement. Une note dans un document ne le demontre pas.
 * Cette commande le verifie, et echoue bruyamment si l'un des points cede.
 */
#[AsCommand(name: 'app:demo:verifier-isolation', description: "Verifie que la demonstration ne depend d'aucun systeme exterieur")]
final class VerifierIsolationCommand extends Command
{
    /** Adresses qu'aucun appel ne doit pouvoir atteindre depuis cet environnement. */
    private const CIBLES = [
        'https://sheets.googleapis.com/v4/spreadsheets/x',
        'https://generativelanguage.googleapis.com/v1beta/models/x',
        'https://example.invalid/',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Connection $cnx,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title("Independance de l'environnement de demonstration");

        $echecs = [];

        // 1. Le transport HTTP refuse de sortir, quel que soit l'appelant.
        foreach (self::CIBLES as $url) {
            try {
                $this->http->request('GET', $url);
                $echecs[] = sprintf('Une requete vers %s n\'a pas ete refusee.', $url);
            } catch (SortieReseauInterdite) {
                $io->writeln(sprintf('  <info>refusee</info>  %s', $url));
            }
        }

        // 2. La base est bien la base locale de demonstration, pas celle du groupe.
        $params = $this->cnx->getParams();
        $hote = (string) ($params['host'] ?? '');
        $port = (string) ($params['port'] ?? '');
        $base = (string) ($params['dbname'] ?? '');
        $io->writeln(sprintf('  <info>base</info>     %s:%s/%s', $hote, $port, $base));
        if (!\in_array($hote, ['127.0.0.1', 'localhost'], true)) {
            $echecs[] = sprintf('La base n\'est pas locale : %s.', $hote);
        }
        if ('finance_demo' !== $base) {
            $echecs[] = sprintf('La base n\'est pas celle de demonstration : %s.', $base);
        }

        // 3. Les transports sortants sont inertes.
        foreach (['MAILER_DSN', 'MAILER_COPILOTE_DSN', 'MAILER_RELANCES_DSN'] as $cle) {
            $valeur = (string) ($_ENV[$cle] ?? '');
            $io->writeln(sprintf('  <info>courrier</info> %s = %s', $cle, '' === $valeur ? '(vide)' : $valeur));
            if ('' !== $valeur && !str_starts_with($valeur, 'null://')) {
                $echecs[] = sprintf('%s n\'est pas neutralise : %s.', $cle, $valeur);
            }
        }

        // 4. Le monde synthetique est charge, et la verite reste a part.
        $virements = (int) $this->cnx->fetchOne('SELECT count(*) FROM affectation.virement');
        $verite = (int) $this->cnx->fetchOne('SELECT count(*) FROM affectation_verite.virement');
        $io->writeln(sprintf('  <info>univers</info>  %d virements synthetiques, %d lignes de verite dans un schema separe', $virements, $verite));
        if ($virements < 1 || $verite < 1) {
            $echecs[] = "L'univers synthetique n'est pas charge.";
        }

        $io->newLine();
        if ([] !== $echecs) {
            $io->error($echecs);

            return Command::FAILURE;
        }

        $io->success('Aucune dependance exterieure. La demonstration fonctionne seule.');

        return Command::SUCCESS;
    }
}
