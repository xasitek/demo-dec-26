<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Recouvrement\Referentiel\Etablissements;
use App\Shared\Entity\Etablissement;
use App\Shared\Repository\EtablissementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Alimente le referentiel partage `shared.etablissement` a partir du dictionnaire
 * metier `Etablissements::LIBELLES` (code -> libelle). Le `code_societe` est repris
 * du miroir Progiciel (`code_entite` par `codeetab` dans recouvrement.v_impayes). Les
 * e-mails de contact NE sont PAS touches (saisis via la vue d'admin).
 *
 * Idempotente : cree les etablissements absents, met a jour libelle + societe des
 * existants, ne supprime jamais (un site retire de Progiciel garde ses contacts).
 */
#[AsCommand(
    name: 'app:shared:seed-etablissements',
    description: 'Seed/maj du referentiel partage shared.etablissement (libelles + societe depuis Progiciel).',
)]
final class SeedEtablissementsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly EtablissementRepository $etablissements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $societeParCode = $this->societesDepuisSage();

        $crees = 0;
        $majs = 0;
        foreach (Etablissements::LIBELLES as $code => $libelle) {
            $codeEtab = \sprintf('%03d', $code);
            $codeSociete = $societeParCode[$code] ?? null;

            $etab = $this->etablissements->find($codeEtab);
            if (null === $etab) {
                $etab = new Etablissement($codeEtab, $libelle);
                $etab->setCodeSociete($codeSociete);
                $this->em->persist($etab);
                ++$crees;
                continue;
            }

            $etab->setLibelle($libelle);
            if (null !== $codeSociete) {
                $etab->setCodeSociete($codeSociete);
            }
            ++$majs;
        }

        $this->em->flush();

        $io->success(\sprintf(
            '%d etablissement(s) cree(s), %d mis a jour. Societe renseignee pour %d code(s) depuis Progiciel.',
            $crees,
            $majs,
            \count($societeParCode),
        ));

        return Command::SUCCESS;
    }

    /**
     * Mapping code etablissement (entier) -> code societe (`code_entite`), depuis le
     * miroir Progiciel. Seuls les etablissements presents dans v_impayes sont couverts ;
     * les autres auront une societe a completer via la vue d'admin.
     *
     * @return array<int, string>
     */
    private function societesDepuisSage(): array
    {
        try {
            /** @var list<array{codeetab: ?string, code_entite: ?string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT codeetab, code_entite FROM recouvrement.v_impayes '
                ."WHERE COALESCE(codeetab, '') <> '' AND COALESCE(code_entite, '') <> ''",
            );
        } catch (Throwable) {
            // v_impayes indisponible (ex. hors serveur interne) : on seed sans societe.
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $code = Etablissements::normaliserCode($row['codeetab'] ?? null);
            if (null !== $code && null !== ($row['code_entite'] ?? null)) {
                $map[$code] = (string) $row['code_entite'];
            }
        }

        return $map;
    }
}
