<?php

declare(strict_types=1);

namespace App\Demo\Command;

use App\Demo\Visites\JournalVisites;
use App\Remboursement\Demo\Navigateur;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Le compteur de visites, en console.
 *
 * `--installer` cree les deux tables. A lancer une fois apres un deploiement,
 * et sans risque autant de fois qu'on veut : la DDL est idempotente.
 *
 * Sans option, la commande lit le compteur. C'est le meme contenu que l'ecran
 * a clef, pour les cas ou l'on a un terminal mais pas l'URL sous la main.
 */
#[AsCommand(
    name: 'app:demo:visites',
    description: 'Compteur de visites de la demonstration : installe les tables, ou les lit.',
)]
final class VisitesCommand extends Command
{
    public function __construct(
        private readonly JournalVisites $journal,
        private readonly HttpKernelInterface $noyau,
        #[Autowire('%env(default::DEMO_VISITES_CLE)%')]
        private readonly ?string $cleVisites,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('installer', null, InputOption::VALUE_NONE,
                'Cree les tables du compteur si elles manquent.')
            ->addOption('lignes', null, InputOption::VALUE_REQUIRED,
                'Nombre de visites detaillees a afficher.', '25')
            ->addOption('epreuve', null, InputOption::VALUE_NONE,
                'Verifie que l\'ecran a clef repond, et qu\'une mauvaise clef ne repond pas.')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED,
                'Mot de passe de la porte d\'acces (pour l\'epreuve).');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Visites de la demonstration');

        if (true === $entree->getOption('installer')) {
            $this->journal->installer();
            $io->success('Tables du compteur en place : shared.visite_session et shared.visite_evenement.');
        }

        if (true === $entree->getOption('epreuve')) {
            return $this->epreuve($io, (string) $entree->getOption('mot-de-passe'));
        }

        $resume = $this->journal->resume();
        $io->table(['Grandeur', 'Valeur'], [
            ['Visites au total', number_format($resume['visites'], 0, ',', ' ')],
            ['Visites sur 7 jours', number_format($resume['visites_7j'], 0, ',', ' ')],
            ['Pages consultees', number_format($resume['pages'], 0, ',', ' ')],
            ['Journees distinctes', (string) $resume['jours']],
            ['Premiere visite', $resume['premiere'] ?? '—'],
            ['Derniere page vue', $resume['derniere'] ?? '—'],
        ]);

        if (0 === $resume['visites']) {
            $io->text('Aucune visite enregistree. Le compteur part de zero tant que personne');
            $io->text('n\'a franchi la porte d\'acces.');

            return Command::SUCCESS;
        }

        $lignes = max(1, (int) $entree->getOption('lignes'));
        $io->section(sprintf('Les %d dernieres visites', $lignes));
        $io->table(['Arrivee', 'Derniere page', 'Pages', 'Navigateur', 'Postes pris'],
            array_map(static fn (array $v): array => [
                (string) $v['premiere_vue'],
                (string) $v['derniere_vue'],
                (string) $v['pages'],
                (string) $v['navigateur'],
                '' !== (string) $v['postes'] ? (string) $v['postes'] : '—',
            ], $this->journal->visites($lignes)));

        $io->text('Aucune adresse IP, aucun nom, aucun identifiant de session n\'est conserve.');

        return Command::SUCCESS;
    }

    /**
     * L'ecran a clef repond-il vraiment, et se tait-il sur une mauvaise clef ?
     *
     * Une URL a clef qui tombe en erreur le jour ou l'on en a besoin ne sert a
     * rien. On la parcourt donc pour de vrai, par le noyau HTTP.
     */
    private function epreuve(SymfonyStyle $io, string $motDePasse): int
    {
        $cle = (string) $this->cleVisites;
        if ('' === $cle) {
            $io->error('DEMO_VISITES_CLE est vide : l\'ecran est volontairement inaccessible.');

            return Command::FAILURE;
        }
        if ('' === $motDePasse) {
            $io->error('Le mot de passe de la porte est requis (--mot-de-passe).');

            return Command::FAILURE;
        }

        $navigateur = new Navigateur($this->noyau, 'jury', $motDePasse);
        if (!$navigateur->ouvrir()) {
            $io->error('La porte d\'acces a refuse le mot de passe fourni.');

            return Command::FAILURE;
        }

        $navigateur->aller('/demo/visites/'.$cle);
        $bonneCle = $navigateur->code();
        $contenu = $navigateur->texteVisible();

        $navigateur->aller('/demo/visites/'.str_repeat('z', \strlen($cle)));
        $mauvaiseCle = $navigateur->code();

        $controles = [
            ['La bonne clef ouvre l\'ecran', 'HTTP '.$bonneCle, 200 === $bonneCle],
            ['L\'ecran s\'affiche vraiment', 'le titre est la',
                str_contains($contenu, 'Visites de la démonstration')],
            ['Il dit ce qu\'il ne stocke pas', 'aucune adresse IP',
                str_contains($contenu, 'aucune adresse IP')],
            ['Une mauvaise clef ne repond pas', 'HTTP '.$mauvaiseCle, 404 === $mauvaiseCle],
        ];

        $fautes = 0;
        $lignes = [];
        foreach ($controles as [$quoi, $vu, $ok]) {
            $lignes[] = [$quoi, $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->table(['Controle', 'Constate', 'Verdict'], $lignes);

        if ($fautes > 0) {
            $io->error(sprintf('%d controle(s) en echec.', $fautes));

            return Command::FAILURE;
        }
        $io->success('L\'ecran a clef repond, et il se tait pour qui n\'a pas la clef.');

        return Command::SUCCESS;
    }
}
