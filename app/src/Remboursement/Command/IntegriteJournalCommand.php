<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\ChaineIntegrite;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifie la chaine d'integrite du journal, et sait se faire attaquer.
 *
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION : ni la chaine, ni cette
 * verification n'existent dans le module historique.
 *
 * L'epreuve adversariale ne simule rien. Elle ALTERE vraiment une ligne du
 * journal du module, en base, par une requete directe -- exactement le geste
 * qu'on veut pouvoir detecter --, verifie que la chaine le voit et nomme le
 * rang fautif, puis remet la valeur d'origine et verifie que tout redevient
 * coherent. Une detection qu'on n'a pas mise a l'epreuve ne prouve rien.
 */
#[AsCommand(
    name: 'app:demo:integrite-journal',
    description: 'RENFORCEMENT : verifie la chaine d\'integrite du journal des transitions.',
)]
final class IntegriteJournalCommand extends Command
{
    public function __construct(
        private readonly ChaineIntegrite $chaine,
        private readonly Connection $cnx,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('installer', null, InputOption::VALUE_NONE,
                'Creer la table de la chaine si elle manque.')
            ->addOption('reconstruire', null, InputOption::VALUE_NONE,
                'Rebatir la chaine depuis le journal existant (premiere pose).')
            ->addOption('epreuve', null, InputOption::VALUE_NONE,
                'Epreuve adversariale : alterer une ligne, la detecter, la remettre.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Chaine d\'integrite du journal — renforcement de la copie');
        $io->text('Ce mecanisme est un AJOUT de la copie de demonstration.');
        $io->text('Le module historique journalise ses transitions ; il ne les enchaine pas.');

        if (true === $entree->getOption('installer') || true === $entree->getOption('reconstruire')) {
            $this->chaine->installer();
            $io->text('Table de la chaine en place.');
        }

        if (true === $entree->getOption('reconstruire')) {
            $this->reconstruire($io);
        }

        $bilan = $this->chaine->verifier();
        $this->afficher($io, $bilan);

        if (true === $entree->getOption('epreuve')) {
            return $this->epreuve($io);
        }

        if ([] !== $bilan['ecarts']) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Premiere pose, ou remise en place apres un reset de demonstration.
     *
     * Cela n'authentifie evidemment pas le passe -- on ne peut pas prouver
     * apres coup qu'une ligne d'hier n'a pas bouge. La chaine ne vaut que pour
     * ce qui vient APRES sa pose, et le rapport le dit.
     */
    private function reconstruire(SymfonyStyle $io): void
    {
        $poses = $this->chaine->reconstruire();
        $io->text(sprintf('%s maillons poses sur le journal existant.',
            number_format($poses, 0, ',', ' ')));
        $io->text('La chaine ne garantit que ce qui vient APRES sa pose : elle ne peut pas');
        $io->text('authentifier retrospectivement des lignes ecrites avant elle.');
    }

    /**
     * @param array{maillons: int, ecarts: list<array{rang: int, reference: string, quoi: string}>, dernier: ?string} $bilan
     */
    private function afficher(SymfonyStyle $io, array $bilan): void
    {
        $io->section('Verification');
        $io->definitionList(
            ['Maillons verifies' => number_format($bilan['maillons'], 0, ',', ' ')],
            ['Ecarts detectes' => (string) \count($bilan['ecarts'])],
            ['Dernier condensat' => null === $bilan['dernier'] ? '—' : substr($bilan['dernier'], 0, 32).'…'],
        );

        if ([] === $bilan['ecarts']) {
            $io->text('Chaine continue : chaque maillon pointe le condensat du precedent, et chaque');
            $io->text('maillon dit encore exactement ce que dit la transition du module.');

            return;
        }

        $io->table(['Rang', 'Dossier', 'Ce qui ne va pas'], array_map(
            static fn (array $e): array => [
                0 === $e['rang'] ? '—' : (string) $e['rang'], $e['reference'], $e['quoi'],
            ], \array_slice($bilan['ecarts'], 0, 15)));
    }

    /** Alteration reelle, detection, remise en etat, re-verification. */
    private function epreuve(SymfonyStyle $io): int
    {
        $io->section('Epreuve adversariale — on altere vraiment une ligne');

        $cible = $this->cnx->fetchAssociative(
            'SELECT t.id, t.commentaire, t.par, d.reference
               FROM remboursement.dossier_transition t
               JOIN remboursement.dossier d ON d.id = t.dossier_id
              ORDER BY t.id DESC LIMIT 1');
        if (false === $cible) {
            $io->error('Aucune transition a alterer.');

            return Command::FAILURE;
        }

        $io->text(sprintf('Cible : transition %d du dossier %s, auteur « %s ».',
            (int) $cible['id'], (string) $cible['reference'], (string) $cible['par']));
        $io->text('Geste simule : quelqu\'un reecrit l\'auteur de la decision en base.');

        $this->cnx->executeStatement(
            'UPDATE remboursement.dossier_transition SET par = ? WHERE id = ?',
            ['auteur-substitue@demonstration.invalid', $cible['id']]);

        $apres = $this->chaine->verifier();
        $vu = [] !== $apres['ecarts'];
        $io->text($vu
            ? sprintf('DETECTE : %d ecart(s). Premier : rang %d — %s',
                \count($apres['ecarts']), $apres['ecarts'][0]['rang'], $apres['ecarts'][0]['quoi'])
            : 'NON DETECTE — la chaine n\'a rien vu, et c\'est un echec.');

        $this->cnx->executeStatement(
            'UPDATE remboursement.dossier_transition SET par = ? WHERE id = ?',
            [$cible['par'], $cible['id']]);

        $remis = $this->chaine->verifier();
        $io->text([] === $remis['ecarts']
            ? 'Valeur d\'origine remise : la chaine est de nouveau continue.'
            : 'ATTENTION : la chaine reste en ecart apres remise en etat.');

        if (!$vu || [] !== $remis['ecarts']) {
            $io->error('L\'epreuve adversariale a echoue.');

            return Command::FAILURE;
        }

        $io->success('Une ligne alteree en base est detectee, et nommee par son rang.');

        return Command::SUCCESS;
    }
}
