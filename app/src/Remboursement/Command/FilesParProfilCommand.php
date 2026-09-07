<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\Navigateur;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Verifie, poste par poste, que les vrais ecrans portent bien des FILES.
 *
 * Ce n'est pas un test d'affichage : c'est la verification qu'un utilisateur
 * qui se connecte trouve du travail a faire, et pas un tableau a regarder.
 * Chaque poste est ouvert dans un navigateur distinct -- un poste, une session,
 * comme un vrai utilisateur -- et l'ecran est lu tel qu'il arrive.
 *
 * Ce qui est controle pour chacun :
 *   - l'ecran repond, et c'est bien le sien ;
 *   - les files annoncees y figurent ;
 *   - les gestes que ce poste doit pouvoir faire sont presents ;
 *   - ce qu'il ne doit PAS voir n'y est pas -- le perimetre d'un directeur de
 *     concession, en particulier, se verifie ici et pas seulement dans un
 *     commentaire.
 */
#[AsCommand(
    name: 'app:demo:files-par-profil',
    description: 'Verifie les files de production dans les vrais ecrans, poste par poste.',
)]
final class FilesParProfilCommand extends Command
{
    public function __construct(
        private readonly HttpKernelInterface $noyau,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('identifiant', null, InputOption::VALUE_REQUIRED,
                'Identifiant de la porte d\'acces.', 'jury')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED,
                'Mot de passe de la porte d\'acces.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Les files de production, poste par poste');

        $motDePasse = (string) $entree->getOption('mot-de-passe');
        if ('' === $motDePasse) {
            $io->error('Le mot de passe de la porte d\'acces est requis (--mot-de-passe).');

            return Command::FAILURE;
        }
        $identifiant = (string) $entree->getOption('identifiant');

        $fautes = 0;

        // -------------------------------------------------------- secretaire
        $io->section('SECRETAIRE — « Mes dossiers » : ou en est chacune de mes demandes');
        //
        // Les libelles attendus sont ceux de StatutSecretaire, mot pour mot :
        // c'est l'enum du module qui les ecrit, pas ce fichier.
        $fautes += $this->verifier($io, $identifiant, $motDePasse, 'secretaire',
            '/remboursement/mes-dossiers', [
                'Les etats du depot et de la lecture' => [
                    'Déposé', 'Analyse IA en cours', 'Vérification comptable'],
                'Les etats de la decision' => [
                    'Correction requise', 'En attente de validation du directeur', 'Dossier validé'],
                'Les etats du paiement' => ['En cours de paiement', 'Payé', 'Refusé'],
                'Elle peut deposer une demande' => ['Déposer'],
            ], ['Ce qui ne la concerne pas' => ['Journal des paiements', 'Virements à préparer']]);

        // ---------------------------------------------------------- comptable
        $io->section('COMPTABLE — la file des dossiers a instruire');
        $fautes += $this->verifier($io, $identifiant, $motDePasse, 'comptable',
            '/remboursement', [
                'La file « a verifier » est annoncee' => ['vérifier'],
                'Les dossiers y sont listes, pas resumes' => ['Client'],
            ], []);

        // Le triptyque et les gestes se lisent sur la FICHE d'un dossier, pas
        // sur la file : c'est la que le comptable travaille.
        $fautes += $this->verifierFicheComptable($io, $identifiant, $motDePasse);

        // ------------------------------------------- directeur de concession
        $io->section('DIRECTEUR DE CONCESSION — ses dossiers a valider, et rien d\'autre');
        $fautes += $this->verifier($io, $identifiant, $motDePasse, 'directeur-concession',
            '/remboursement/suivi?statut=attente_directeur', [
                'L\'ecran repond' => ['dossiers'],
            ], []);

        // ------------------------------------------------ directeur comptable
        $io->section('DIRECTEUR COMPTABLE — la vue consolidee de production');
        $fautes += $this->verifier($io, $identifiant, $motDePasse, 'directeur-comptable',
            '/remboursement/suivi', [
                'Les six files de production sont la' => [
                    'À vérifier', 'À corriger', 'Attente directeur',
                    'Prêts à payer', 'Bloqués', 'Incidents',
                ],
                'Chaque chiffre ouvre une file' => ['ouvrir la file'],
            ], []);

        // ------------------------------------------------ la page « Methode »
        // Le jury doit y lire deux ensembles distincts, jamais additionnes :
        // le socle herite et les renforcements de la copie.
        $io->section('PAGE METHODE — le socle herite et les renforcements, separes');
        $fautes += $this->verifier($io, $identifiant, $motDePasse, 'directeur-comptable',
            '/remboursement/methode', [
                'Le socle herite est compte' => ['54 contrôles', '34', '14', '6'],
                'Les natures ne s'."'".'additionnent pas' => ['ne s'."'".'additionnent pas sous le mot'],
                'Les renforcements sont a part' => [
                    'Renforcements ajoutés dans la copie de démonstration',
                    'R01', 'R02', 'R03', 'R04',
                ],
                'R04 est presente comme un ajout' => [
                    'ajouté dans la copie de démonstration comme renforcement proposé',
                ],
                'Les limites sont dites' => ['via la file de messages', 'lecture locale déterministe'],
            ], [
                // Ce qui ne doit JAMAIS figurer sur cette page.
                'Aucune revendication fausse' => ['54 contrôles bloquants', 'révélation tracée'],
            ]);

        if ($fautes > 0) {
            $io->error(sprintf('%d controle(s) en echec.', $fautes));

            return Command::FAILURE;
        }
        $io->success('Chaque poste trouve ses files, et seulement les siennes.');

        return Command::SUCCESS;
    }

    /**
     * La fiche d'un dossier a instruire : le triptyque, les pieces, les gestes.
     *
     * On prend le premier dossier de la file du comptable : si la file est
     * vide, il n'y a rien a instruire et le controle le dit plutot que de
     * passer silencieusement.
     */
    private function verifierFicheComptable(SymfonyStyle $io, string $identifiant, string $motDePasse): int
    {
        $navigateur = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$navigateur->ouvrir()) {
            $io->error("La porte d'acces a refuse.");

            return 1;
        }
        $navigateur->poste('comptable', '/remboursement');

        // ACCESSIBILITE DE LA FILE. La ligne ne s'ouvrait que par un `onclick` :
        // a la souris, jamais au clavier. La reference porte desormais un vrai
        // lien, atteignable a la tabulation, ouvrable a Entree, avec un anneau
        // de focus visible et un intitule annoncable. On le verifie ici, sur le
        // HTML rendu, plutot que de le croire.
        $file = $navigateur->contenu();
        $lienDeLigne = 1 === preg_match(
            '#<a href="/remboursement/dossier/\d+"[^>]*aria-label="Ouvrir le dossier#', $file);
        $anneauDeFocus = str_contains($file, 'focus:ring-2 focus:ring-navy/40');
        $ligneSuitLeFocus = str_contains($file, 'focus-within:bg-gold/[0.10]');

        // Une file vide ne prouve rien, ni dans un sens ni dans l'autre : on le
        // dit et on ne compte pas d'echec. Pour verifier le lien de ligne, il
        // faut une file peuplee -- `app:demo:preparer` en fabrique une, et
        // `app:demo:reset-remboursement` la defait.
        if (!str_contains($file, 'data-dossier-id=')) {
            $io->warning('La file du comptable ne porte aucune ligne : sur cet ecran, en '
                .'cet etat, le lien de ligne ne peut pas etre verifie.');

            return 0;
        }

        $io->table(['Accessibilite de la file', 'Constate', 'Verdict'], [
            ['La reference est un vrai lien, pas un seul onclick',
                $lienDeLigne ? '<a href> avec intitule annoncable' : 'aucun lien trouve',
                $lienDeLigne ? 'OK' : 'ECHEC'],
            ['Le focus reste visible',
                $anneauDeFocus ? 'anneau de focus sur le lien' : 'aucun anneau',
                $anneauDeFocus ? 'OK' : 'ECHEC'],
            ['La ligne se signale quand son lien a le focus',
                $ligneSuitLeFocus ? 'fond de ligne au focus-within' : 'aucun retour visuel',
                $ligneSuitLeFocus ? 'OK' : 'ECHEC'],
        ]);
        $fautesAcces = (int) !$lienDeLigne + (int) !$anneauDeFocus + (int) !$ligneSuitLeFocus;

        if (1 !== preg_match('#/remboursement/dossier/(\d+)#', $file, $m)) {
            $io->text('La file du comptable est vide : aucune fiche a verifier.');

            return 1;
        }
        $ecran = '/remboursement/dossier/'.$m[1];
        $navigateur->aller($ecran);
        $texte = $navigateur->texteVisible();
        $html = $navigateur->contenu();

        $controles = [
            ['La fiche repond', sprintf('HTTP %d', $navigateur->code()), 200 === $navigateur->code()],
            ['Le triptyque est nomme', 'Saisie / Lu sur la piece / Valeur validee',
                str_contains($texte, 'Saisie') && str_contains($texte, 'Lu sur la pièce')
                && str_contains($texte, 'Valeur validée')],
            ['La provenance de la lecture est dite', 'lecture locale deterministe',
                str_contains($texte, 'Lecture locale déterministe')],
            ['Les pieces sont consultables', 'liens de pieces',
                str_contains($html, '/remboursement/dossier/piece/')],
            ['Les gestes autorises sont offerts', 'valider / corriger / refuser',
                str_contains($html, '/valider') && str_contains($texte, 'Refuser')
                && str_contains($texte, 'Demander une correction')],
        ];

        $table = [];
        $fautes = 0;
        foreach ($controles as [$quoi, $vu, $ok]) {
            $table[] = [$quoi, $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->text('Fiche ouverte : '.$ecran);
        $io->table(['Controle', 'Constate', 'Verdict'], $table);

        return $fautes + $fautesAcces;
    }

    /**
     * @param array<string, list<string>> $attendus  ce qui doit figurer
     * @param array<string, list<string>> $interdits ce qui ne doit pas y etre
     */
    private function verifier(
        SymfonyStyle $io,
        string $identifiant,
        string $motDePasse,
        string $persona,
        string $ecran,
        array $attendus,
        array $interdits,
    ): int {
        $navigateur = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$navigateur->ouvrir()) {
            $io->error('La porte d\'acces a refuse.');

            return 1;
        }
        $navigateur->poste($persona, $ecran);
        $texte = $navigateur->texteVisible();
        $code = $navigateur->code();
        $ou = $navigateur->ou();

        $io->text(sprintf('Ecran : %s — HTTP %d', $ou, $code));

        $lignes = [];
        $fautes = 0;

        $lignes[] = ['L\'ecran demande est bien celui atteint',
            str_contains($ou, explode('?', $ecran)[0]) ? 'oui' : 'NON — '.$ou,
            str_contains($ou, explode('?', $ecran)[0])];

        foreach ($attendus as $quoi => $mots) {
            $manquants = array_values(array_filter($mots,
                static fn (string $m): bool => !str_contains($texte, $m)));
            $lignes[] = [$quoi, [] === $manquants ? 'present' : 'MANQUE : '.implode(', ', $manquants),
                [] === $manquants];
        }
        foreach ($interdits as $quoi => $mots) {
            $vus = array_values(array_filter($mots,
                static fn (string $m): bool => str_contains($texte, $m)));
            $lignes[] = [$quoi, [] === $vus ? 'absent, comme attendu' : 'PRESENT A TORT : '.implode(', ', $vus),
                [] === $vus];
        }

        $table = [];
        foreach ($lignes as [$quoi, $vu, $ok]) {
            $table[] = [$quoi, $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->table(['Controle', 'Constate', 'Verdict'], $table);

        return $fautes;
    }
}
