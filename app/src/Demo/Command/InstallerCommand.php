<?php

declare(strict_types=1);

namespace App\Demo\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * INSTALLE LA PLATEFORME EN UNE COMMANDE.
 *
 * POURQUOI ELLE EXISTE. Mettre la plateforme debout demande une douzaine de
 * commandes, dans un ordre qui compte : le lettrage lit des ecritures que
 * l'affectation a posees, le cockpit agrege ce que les deux ont produit, le
 * pont des grands comptes relie des creances qui doivent exister d'abord. Un
 * ordre note dans un document se perd ; un ordre ecrit dans le code se rejoue.
 *
 * ELLE EST IDEMPOTENTE. Chaque etape porte un TEMOIN : une table et un compte
 * attendu. Si le temoin est deja la, l'etape est passee et le dit. On peut donc
 * la relancer sans rien casser -- apres un deploiement interrompu, par exemple,
 * ou pour ajouter une etape nouvelle a une base deja peuplee. `--refaire`
 * force tout, et c'est le seul moyen de tout recharger.
 *
 * ELLE VERIFIE A LA FIN. Les huit reconciliations du cockpit, le controle de
 * conformite des grands comptes et le registre des controles tournent en
 * derniere etape. Une plateforme installee mais fausse n'est pas installee : le
 * verdict le dit, et le code de sortie avec lui.
 *
 * CE QU'ELLE NE FAIT PAS. Elle ne cree pas la base et ne joue pas les
 * migrations : cela regarde l'hebergeur, qui a ses propres facons de le faire
 * avant le premier demarrage. Elle refuse de tourner si le schema n'est pas la,
 * plutot que de laisser croire a une installation reussie.
 */
#[AsCommand(
    name: 'app:demo:installer',
    description: 'Met la plateforme debout : monde synthetique, comptes, compteurs, puis verifications.',
)]
final class InstallerCommand extends Command
{
    /**
     * Les etapes, dans l'ordre, avec le temoin qui dit si c'est deja fait.
     *
     * @var list<array{commande: string, quoi: string, temoin: ?string, attendu: int}>
     */
    private const ETAPES = [
        ['commande' => 'app:demo:preparer',
            'quoi' => 'Les postes de travail synthetiques',
            'temoin' => 'shared.users', 'attendu' => 1],
        // LES TABLES DES RENFORCEMENTS D'ABORD, et ce n'est pas un detail
        // d'ordre : l'ecouteur de la chaine d'integrite pose un maillon a CHAQUE
        // transition, et la premiere transition a lieu des le chargement des
        // dossiers. Table absente, chargement en echec. Trouve en installant sur
        // une base vierge, ce qu'aucune relecture n'aurait montre.
        ['commande' => 'app:demo:integrite-journal --installer',
            'quoi' => 'La chaine d\'integrite du journal (renforcement R03)',
            'temoin' => null, 'attendu' => 0],
        ['commande' => 'app:demo:visites --installer',
            'quoi' => 'Le compteur de visites',
            'temoin' => null, 'attendu' => 0],
        ['commande' => 'app:affectation:charger-univers',
            'quoi' => 'Virements, factures et payeurs (outil 4)',
            'temoin' => 'affectation.virement', 'attendu' => 1],
        ['commande' => 'app:lettrage:charger-univers',
            'quoi' => 'Ecritures, lots et lettrages a relire (outil 5)',
            'temoin' => 'lettrage.ecriture', 'attendu' => 1],
        ['commande' => 'app:pilotage:charger-univers',
            'quoi' => 'Chiffre d\'affaires et causes d\'ouverture (outil 6)',
            'temoin' => 'pilotage.chiffre_affaires', 'attendu' => 1],
        ['commande' => 'app:grands-comptes:charger',
            'quoi' => 'Dossiers grands comptes et grilles des loueurs (outil 7)',
            'temoin' => 'grands_comptes.dossier', 'attendu' => 1],
        ['commande' => 'app:grands-comptes:lier',
            'quoi' => 'Le lien entre creances bloquees et dossiers',
            'temoin' => null, 'attendu' => 0],
        ['commande' => 'app:grands-comptes:documents',
            'quoi' => 'Les documents synthetiques des dossiers vedettes',
            'temoin' => null, 'attendu' => 0],
        ['commande' => 'app:remboursement:charger-univers',
            'quoi' => 'Les 2 500 dossiers de remboursement (outil 8)',
            'temoin' => 'remboursement.dossier', 'attendu' => 1],
    ];

    /**
     * Les verifications de fin. Elles echouent bruyamment, et c'est le but.
     *
     * @var list<array{commande: string, quoi: string}>
     */
    private const VERIFICATIONS = [
        ['commande' => 'app:pilotage:reconcilier', 'quoi' => 'Les huit reconciliations du cockpit'],
        ['commande' => 'app:grands-comptes:controler', 'quoi' => 'Le controle de conformite (outil 7)'],
        ['commande' => 'app:demo:registre-controles', 'quoi' => 'Le registre des 54 controles et 4 renforcements'],
        ['commande' => 'app:demo:figer-fabrique-mesure', 'quoi' => 'Le contrat de mesure de l\'outil 8'],
        ['commande' => 'app:demo:verifier-isolation', 'quoi' => 'Aucune dependance exterieure'],
    ];

    public function __construct(
        private readonly Connection $cnx,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refaire', null, InputOption::VALUE_NONE,
                'Rejoue toutes les etapes, meme celles deja faites.')
            ->addOption('sans-verifications', null, InputOption::VALUE_NONE,
                'Installe sans jouer les verifications de fin (deconseille).');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Installation de la plateforme de demonstration');

        if (!$this->schemaPresent()) {
            $io->error('Le schema de la base est absent. Jouez d\'abord les migrations :');
            $io->text('    php bin/console doctrine:migrations:migrate --no-interaction');

            return Command::FAILURE;
        }

        $refaire = true === $entree->getOption('refaire');
        $io->text($refaire
            ? 'Mode --refaire : toutes les etapes sont rejouees.'
            : 'Les etapes deja faites seront passees. --refaire pour tout rejouer.');

        $lignes = [];
        $fautes = 0;

        foreach (self::ETAPES as $rang => $etape) {
            $numero = $rang + 1;
            $deja = !$refaire && $this->dejaFait($etape['temoin'], $etape['attendu']);

            if ($deja) {
                $lignes[] = [(string) $numero, $etape['quoi'], 'deja fait', '—'];
                continue;
            }

            $io->text(sprintf(' %d/%d  %s…', $numero, \count(self::ETAPES), $etape['quoi']));
            $debut = microtime(true);
            $erreur = $this->jouer($etape['commande']);
            $duree = sprintf('%.1f s', microtime(true) - $debut);

            if (null !== $erreur) {
                ++$fautes;
                $lignes[] = [(string) $numero, $etape['quoi'], 'ECHEC', $duree];
                $io->error(sprintf('%s : %s', $etape['commande'], $erreur));
                break;
            }
            $lignes[] = [(string) $numero, $etape['quoi'], 'fait', $duree];
        }

        $io->newLine();
        $io->table(['#', 'Etape', 'Etat', 'Duree'], $lignes);

        if ($fautes > 0) {
            $io->error('Installation interrompue. Corrigez, puis relancez : les etapes '
                .'deja faites seront passees.');

            return Command::FAILURE;
        }

        if (true === $entree->getOption('sans-verifications')) {
            $io->warning('Verifications non jouees. Une plateforme installee mais fausse '
                .'n\'est pas installee.');

            return Command::SUCCESS;
        }

        $io->section('Les verifications');
        $verifs = [];
        foreach (self::VERIFICATIONS as $v) {
            $erreur = $this->jouer($v['commande']);
            $verifs[] = [$v['quoi'], null === $erreur ? 'OK' : 'ECHEC'];
            if (null !== $erreur) {
                ++$fautes;
            }
        }
        $io->table(['Verification', 'Verdict'], $verifs);

        if ($fautes > 0) {
            $io->error(sprintf('%d verification(s) en echec. La plateforme est debout, '
                .'mais elle ne dit pas la verite : ne la montrez pas en l\'etat.', $fautes));

            return Command::FAILURE;
        }

        $io->success('Plateforme installee et verifiee. Il reste a poser la porte d\'acces '
            .'(DEMO_ACCES_EMPREINTE_B64) si ce n\'est pas deja fait.');

        return Command::SUCCESS;
    }

    /** Le schema est-il la ? On demande a la base, pas au systeme de fichiers. */
    private function schemaPresent(): bool
    {
        try {
            return 1 === (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM information_schema.schemata WHERE schema_name = 'remboursement'");
        } catch (Throwable) {
            return false;
        }
    }

    /** Le temoin de cette etape est-il deja en place ? */
    private function dejaFait(?string $table, int $attendu): bool
    {
        if (null === $table) {
            // Pas de temoin : l'etape est rejouee a chaque fois. Elles sont
            // toutes idempotentes -- elles vident puis reecrivent.
            return false;
        }
        try {
            return (int) $this->cnx->fetchOne('SELECT count(*) FROM '.$table) >= $attendu;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Joue une commande de la meme application, et rend son erreur ou rien.
     *
     * La sortie est mise en tampon : une installation qui deverse dix mille
     * lignes ne se lit pas. En cas d'echec, on rend les dernieres lignes, qui
     * sont celles qui disent pourquoi.
     */
    private function jouer(string $ligne): ?string
    {
        $morceaux = explode(' ', $ligne);
        $nom = array_shift($morceaux);
        $arguments = ['command' => $nom];
        foreach ($morceaux as $option) {
            $arguments[$option] = true;
        }

        $application = $this->getApplication();
        if (null === $application) {
            return 'application console indisponible';
        }

        $tampon = new BufferedOutput();
        try {
            $entree = new ArrayInput($arguments);
            $entree->setInteractive(false);
            $code = $application->find($nom)->run($entree, $tampon);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if (0 !== $code) {
            $texte = trim($tampon->fetch());
            $fin = \array_slice(explode("\n", $texte), -6);

            return sprintf('code %d — %s', $code, trim(implode(' / ', $fin)));
        }

        return null;
    }
}
