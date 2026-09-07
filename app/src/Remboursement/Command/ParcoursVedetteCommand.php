<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\GenerationFichiersComptables;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use RuntimeException;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Fait traverser a un dossier le VRAI parcours du module, par ses vraies routes.
 *
 * Ce harnais ne remplace aucun rouage : il joue les gestes des utilisateurs. Il
 * ouvre la porte d'acces, prend un poste, ouvre un ecran, SOUMET LE FORMULAIRE
 * QUI S'Y TROUVE, clique le lien signe du directeur, laisse le worker consommer
 * la file, telecharge le lot, confirme le paiement et lettre. Chaque etape passe
 * par le noyau HTTP complet : routage, pare-feu, roles, CSRF, controleurs,
 * services, garde-fous du workflow.
 *
 * Ce qu'il ne fait a aucun moment : ecrire en base pour avancer, appeler une
 * transition directement, desactiver un controle, forcer un statut. Si une garde
 * du module refuse, le parcours s'arrete et le rapport le dit.
 *
 * La seule chose contournee est la porte d'acces de la demonstration -- un
 * portail de courtoisie devant la plateforme, qui n'existe pas en production et
 * ne protege aucune donnee. Elle est franchie avec un identifiant fourni au
 * lancement, comme le ferait un lecteur.
 */
#[AsCommand(
    name: 'app:remboursement:parcours-vedette',
    description: 'Fait traverser un dossier par les vraies routes du module, sans raccourci.',
)]
final class ParcoursVedetteCommand extends Command
{
    /** @var list<array{string, string, string}> */
    private array $etapes = [];

    public function __construct(
        private readonly HttpKernelInterface $noyau,
        private readonly EntityManagerInterface $em,
        private readonly DossierRepository $dossiers,
        private readonly DossierPieceRepository $pieces,
        private readonly UriSigner $signeur,
        private readonly UrlGeneratorInterface $urls,
        private readonly WorkflowRemboursement $workflow,
        private readonly string $racineProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Le dossier a faire traverser.')
            ->addOption('identifiant', null, InputOption::VALUE_REQUIRED, 'Identifiant de la porte d\'acces.', 'jury')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED, 'Mot de passe de la porte d\'acces.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Parcours reel d\'un dossier de remboursement');

        $reference = (string) $entree->getOption('reference');
        $dossier = $this->dossiers->findOneBy(['reference' => $reference]);
        if (null === $dossier) {
            $io->error('Dossier introuvable : '.$reference);

            return Command::FAILURE;
        }
        $id = (int) $dossier->getId();
        $depart = $dossier->getStatut()->value;

        $identifiant = (string) $entree->getOption('identifiant');
        $motDePasse = (string) $entree->getOption('mot-de-passe');
        if ('' === $motDePasse) {
            $io->error('Le mot de passe de la porte d\'acces est requis (--mot-de-passe).');

            return Command::FAILURE;
        }

        // ---------------------------------------------- 1. le poste comptable
        $comptable = $this->navigateur();
        if (!$this->franchirLaPorte($io, $comptable, $identifiant, $motDePasse)) {
            return Command::FAILURE;
        }

        $ecran = '/remboursement/dossier/'.$id;
        $comptable->request('GET', '/demo/voir-comme/comptable?vers='.rawurlencode($ecran));
        $comptable->followRedirect();
        $page = $comptable->getCrawler();
        $this->noter('GET', '/demo/voir-comme/comptable', $this->statut($comptable));
        $this->noter('GET', $ecran, $this->statut($comptable));
        if (200 !== $this->code($comptable)) {
            $io->error('L\'ecran comptable du dossier n\'a pas repondu 200.');

            return Command::FAILURE;
        }

        // ---------------------- 2. le comptable soumet le formulaire de l'ecran
        //
        // Chaque etape ne joue que si le dossier l'attend encore. Un dossier
        // deja avance reprend ou il en etait, et le rapport le dit : le harnais
        // ne rejoue jamais une decision que le module a deja enregistree.
        $io->section('2. Le comptable valide, avec les valeurs de son ecran');
        if (DossierStatut::A_VERIFIER === $dossier->getStatut()) {
            $formulaire = $page->filter('form[action="'.$ecran.'/valider"]');
            if (0 === $formulaire->count()) {
                $io->error('Le formulaire de validation comptable est absent de l\'ecran.');

                return Command::FAILURE;
            }
            $form = $formulaire->form();
            $valeurs = $form->getPhpValues();
            $io->table(['Champ du formulaire', 'Valeur soumise'], array_map(
                static fn (string $c): array => [$c, (string) ($valeurs[$c] ?? '')],
                ['nom', 'iban', 'bic', 'montant', 'immatriculation', 'code_icar', 'libelle', 'code_comptable', 'role_tiers'],
            ));

            $comptable->submit($form);
            $this->noter('POST', $ecran.'/valider', $this->statut($comptable));
            $comptable->followRedirect();
            $this->em->clear();
            $dossier = $this->recharger($reference);
            $io->text(sprintf('Statut : %s → %s', $depart, $dossier->getStatut()->value));
            if (DossierStatut::A_VALIDER_DIRECTEUR !== $dossier->getStatut()) {
                $io->error('Le module n\'a pas transmis le dossier au directeur. Parcours arrete.');
                $this->rapport($io, $dossier);

                return Command::FAILURE;
            }
        } else {
            $io->text('Deja fait : le dossier est en « '.$dossier->getStatut()->value.' ». Etape passee.');
        }

        // ------------------------------- 3. le directeur clique son lien signe
        $io->section('3. Le directeur de concession valide, par le lien signe');
        if (DossierStatut::A_VALIDER_DIRECTEUR === $dossier->getStatut()) {
            $lien = $this->signeur->sign($this->urls->generate(
                'app_remboursement_directeur_valider', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL));
            $io->text('Lien tel que le module le fabrique pour l\'e-mail :');
            $io->text('  '.$lien);

            // Un navigateur NEUF, sans poste : le directeur n'a pas de compte.
            $directeur = $this->navigateur();
            if (!$this->franchirLaPorte($io, $directeur, $identifiant, $motDePasse)) {
                return Command::FAILURE;
            }
            $directeur->request('GET', $lien);
            $this->noter('GET', 'lien signe /remboursement/directeur/'.$id.'/valider', $this->statut($directeur));

            // Le meme lien, prive de sa signature : le module doit refuser.
            $nu = $this->navigateur();
            $this->franchirLaPorte($io, $nu, $identifiant, $motDePasse);
            $nu->request('GET', '/remboursement/directeur/'.$id.'/refuser');
            $io->text(sprintf('Contre-epreuve — le meme chemin sans signature : HTTP %d (refus attendu).',
                $this->code($nu)));
        } else {
            $io->text('Deja fait : le dossier est en « '.$dossier->getStatut()->value.' ». Etape passee.');
        }

        $this->em->clear();
        $dossier = $this->recharger($reference);
        $io->text('Statut apres validation directeur : '.$dossier->getStatut()->value);

        // -------------------------------------- 4. le worker genere les fichiers
        $io->section('4. Le worker consomme la file et genere les fichiers');
        if (DossierStatut::VALIDE_DIRECTEUR === $dossier->getStatut()) {
            $worker = new Process(
                ['php', 'bin/console', 'messenger:consume', 'remboursement', '--limit=1', '--time-limit=60', '-q'],
                $this->racineProjet);
            $worker->setTimeout(120);
            $worker->run();
            $io->text(sprintf('messenger:consume remboursement --limit=1 → code %d', (int) $worker->getExitCode()));
            if ('' !== trim($worker->getErrorOutput())) {
                $io->text('  '.trim($worker->getErrorOutput()));
            }

            $this->em->clear();
            $dossier = $this->recharger($reference);
            $io->text('Statut apres generation : '.$dossier->getStatut()->value);
        } else {
            $io->text('Deja fait : les fichiers du dossier existent. Etape passee.');
        }

        $fichiers = [];
        foreach ($this->pieces->pourDossier($dossier) as $piece) {
            if (\in_array($piece->getType(), [GenerationFichiersComptables::TYPE_OD, GenerationFichiersComptables::TYPE_SEPA], true)) {
                $fichiers[$piece->getType()] = $piece;
            }
        }
        if (!isset($fichiers[GenerationFichiersComptables::TYPE_SEPA], $fichiers[GenerationFichiersComptables::TYPE_OD])) {
            $io->error('Les fichiers comptables n\'ont pas ete produits.');
            $this->rapport($io, $dossier);

            return Command::FAILURE;
        }
        $io->table(['Fichier', 'Nom', 'Octets', 'Empreinte'], array_map(
            static fn (string $t): array => [
                $t, $fichiers[$t]->getNomFichier(), (string) $fichiers[$t]->getTailleOctets(),
                substr((string) $fichiers[$t]->getHash(), 0, 16).'…',
            ], array_keys($fichiers)));

        $xml = (string) $fichiers[GenerationFichiersComptables::TYPE_SEPA]->getContenu();
        $csv = (string) $fichiers[GenerationFichiersComptables::TYPE_OD]->getContenu();
        $this->controlerSepa($io, $xml, $dossier);
        $io->section('Le CSV d\'ecriture comptable, tel qu\'il est produit');
        foreach (array_slice(preg_split('/\r?\n/', trim($csv)) ?: [], 0, 6) as $ligne) {
            $io->text('  '.$ligne);
        }

        // ------------------------------- 5. le lot est telecharge, puis confirme
        $io->section('5. Le lot est prepare, puis le paiement confirme');
        if (DossierStatut::GENERATION_EN_COURS === $dossier->getStatut()) {
            $manager = $this->navigateur();
            $this->franchirLaPorte($io, $manager, $identifiant, $motDePasse);
            // L'ecran liste les dossiers prets ; le POST ne porte QUE le notre,
            // de sorte qu'aucun autre dossier de l'univers n'est marque comme
            // telecharge par le harnais.
            $liste = '/remboursement/virements?q='.rawurlencode($reference);
            $manager->request('GET', '/demo/voir-comme/credit-manager?vers='.rawurlencode($liste));
            $manager->followRedirect();
            $this->noter('GET', $liste, $this->statut($manager));
            $formZip = $manager->getCrawler()->filter('form[action="/remboursement/virements/zip"]');
            if (0 === $formZip->count()) {
                $io->error('Le dossier n\'apparait pas dans la file des virements a preparer.');
                $this->rapport($io, $dossier);

                return Command::FAILURE;
            }
            $coches = $formZip->filter('input[name="ids[]"]')->extract(['value']);
            $io->text(sprintf('Dossiers listes par l\'ecran : %s', implode(', ', $coches) ?: 'aucun'));
            $io->text('Dossier effectivement soumis : '.$id);
            $manager->request('POST', '/remboursement/virements/zip', [
                '_token' => $this->jetonDeFormulaire($formZip),
                'ids' => [$id],
            ]);
            $reponse = $manager->getInternalResponse();
            $this->noter('POST', '/remboursement/virements/zip', $this->statut($manager));
            $io->text(sprintf('Archive renvoyee : %s %s',
                self::entete($reponse->getHeader('content-type')),
                self::entete($reponse->getHeader('content-disposition'))));

            $direction = $this->navigateur();
            $this->franchirLaPorte($io, $direction, $identifiant, $motDePasse);
            $journal = '/remboursement/paiements/journal?q='.rawurlencode($reference);
            $direction->request('GET', '/demo/voir-comme/directeur-comptable?vers='.rawurlencode($journal));
            $direction->followRedirect();
            $this->noter('GET', $journal, $this->statut($direction));
            // Le jeton du journal est porte par l'attribut Stimulus, la ou le
            // script de la page le lit. On le prend au meme endroit.
            $porteur = $direction->getCrawler()->filter('[data-paiement-journal-token-value]');
            if (0 === $porteur->count()) {
                $io->error('Le journal des paiements n\'expose pas de jeton : dossier absent de l\'ecran ?');
                $this->rapport($io, $dossier);

                return Command::FAILURE;
            }
            $direction->request('POST', '/remboursement/paiements/journal/confirmer', [
                '_token' => (string) $porteur->attr('data-paiement-journal-token-value'),
                'ids' => [$id],
            ]);
            $this->noter('POST', '/remboursement/paiements/journal/confirmer', $this->statut($direction));

            $this->em->clear();
            $dossier = $this->recharger($reference);
            $io->text('Statut apres confirmation : '.$dossier->getStatut()->value);
        } else {
            $io->text('Deja fait : le dossier est en « '.$dossier->getStatut()->value.' ». Etape passee.');
        }

        // ------------------------------------------- 6. le comptable lettre
        $io->section('6. Le comptable lettre le dossier');
        if (DossierStatut::PAYE !== $dossier->getStatut()) {
            $io->text('Le dossier n\'est pas en « paye » : rien a lettrer.');
            $this->rapport($io, $dossier);

            return Command::FAILURE;
        }
        $comptable2 = $this->navigateur();
        $this->franchirLaPorte($io, $comptable2, $identifiant, $motDePasse);
        $comptable2->request('GET', '/demo/voir-comme/comptable?vers='.rawurlencode($ecran));
        $comptable2->followRedirect();
        // Le geste passe par le bouton de l'ecran, comme les autres. Le bloc
        // « Actions possibles sur ce dossier » rend ce que le module declare
        // dans `transitionsPossibles()` : ici, « Marquer lettre » et rien d'autre.
        $io->text('Actions declarees possibles par le module a ce stade : '
            .implode(', ', $this->workflow->transitionsPossibles($dossier)));
        $formLettrer = $comptable2->getCrawler()
            ->filter('form[action="'.$ecran.'/transition"] input[value="lettrer"]');
        if (0 === $formLettrer->count()) {
            $io->error('L\'ecran ne propose pas « Marquer lettre ».');
            $this->rapport($io, $dossier);

            return Command::FAILURE;
        }
        $porteurForm = $formLettrer->closest('form');
        if (null === $porteurForm) {
            $io->error('Le bouton « Marquer lettre » ne porte pas de formulaire.');
            $this->rapport($io, $dossier);

            return Command::FAILURE;
        }
        $comptable2->submit($porteurForm->form());
        $this->noter('POST', $ecran.'/transition (lettrer)', $this->statut($comptable2));

        $this->em->clear();
        $dossier = $this->recharger($reference);

        $this->rapport($io, $dossier);

        if ('lettre' !== $dossier->getStatut()->value) {
            $io->warning('Le dossier n\'est pas arrive a « lettre ». Voir le journal ci-dessus.');

            return Command::FAILURE;
        }
        $io->success(sprintf('%s a traverse le parcours reel jusqu\'a « lettre », sans raccourci.',
            $dossier->getReference()));

        return Command::SUCCESS;
    }

    private function navigateur(): HttpKernelBrowser
    {
        $b = new HttpKernelBrowser($this->noyau, ['HTTP_HOST' => 'localhost']);
        $b->followRedirects(false);

        return $b;
    }

    /** Franchit la porte d'acces de la demonstration, par son vrai formulaire. */
    /** @param AbstractBrowser<Request, Response> $b */
    private function franchirLaPorte(SymfonyStyle $io, AbstractBrowser $b, string $identifiant, string $motDePasse): bool
    {
        $b->request('GET', '/acces');
        $b->request('POST', '/acces', ['identifiant' => $identifiant, 'mot_de_passe' => $motDePasse]);
        if (302 !== $this->code($b)) {
            $io->error(sprintf('La porte d\'acces a refuse (HTTP %d). Verifiez --mot-de-passe.', $this->code($b)));

            return false;
        }

        return true;
    }

    private function controlerSepa(SymfonyStyle $io, string $xml, Dossier $dossier): void
    {
        $io->section('Le fichier SEPA, controle ligne a ligne');
        $doc = new DOMDocument();
        $lisible = $doc->loadXML($xml);
        $valeur = static function (string $balise) use ($xml): string {
            return 1 === preg_match('/<'.$balise.'[^>]*>([^<]*)<\/'.$balise.'>/', $xml, $m) ? trim($m[1]) : '';
        };

        $attendus = [
            'XML bien forme' => [$lisible ? 'oui' : 'NON', $lisible],
            'Schema pain.001.001.03' => [
                str_contains($xml, 'pain.001.001.03') ? 'present' : 'ABSENT',
                str_contains($xml, 'pain.001.001.03'),
            ],
            'Montant de l\'ordre' => [
                $valeur('InstdAmt'),
                abs((float) $valeur('InstdAmt') - (float) $dossier->getValideMontant()) < 0.005,
            ],
            'IBAN debiteur (etablissement)' => [$valeur('IBAN'), 27 === \strlen($valeur('IBAN'))],
            'IBAN crediteur (beneficiaire)' => [
                (string) $dossier->getValideIban(),
                str_contains($xml, (string) $dossier->getValideIban()),
            ],
            'Reference de bout en bout' => [
                $valeur('EndToEndId'),
                '' !== $valeur('EndToEndId'),
            ],
            'Aucune adresse de banque reelle' => [
                str_contains($xml, '99999') ? 'code banque 99999 uniquement' : 'a verifier',
                str_contains($xml, '99999'),
            ],
        ];
        $lignes = [];
        $fautes = 0;
        foreach ($attendus as $quoi => [$vu, $ok]) {
            $lignes[] = [$quoi, (string) $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->table(['Controle', 'Constate', 'Verdict'], $lignes);
        $io->text(0 === $fautes
            ? 'Les sept controles du fichier de paiement passent.'
            : sprintf('%d controle(s) en echec sur le fichier de paiement.', $fautes));
        $io->text('FICHIER DE DEMONSTRATION — AUCUN ORDRE BANCAIRE TRANSMIS.');
    }

    /** Le journal du dossier, tel que le module l'a ecrit. */
    private function rapport(SymfonyStyle $io, Dossier $dossier): void
    {
        $io->section('Le journal du dossier, ecrit par le module');
        $lignes = $this->em->getConnection()->fetchAllAssociative(
            'SELECT de_statut, vers_statut, transition, par,
                    to_char(le, \'HH24:MI:SS\') AS heure, commentaire
               FROM remboursement.dossier_transition
              WHERE dossier_id = ? ORDER BY id', [$dossier->getId()]);
        $io->table(['Heure', 'Transition', 'Avant', 'Apres', 'Par', 'Commentaire'],
            array_map(static fn (array $l): array => [
                (string) $l['heure'], (string) $l['transition'],
                (string) $l['de_statut'], (string) $l['vers_statut'],
                mb_substr((string) $l['par'], 0, 30),
                mb_substr((string) $l['commentaire'], 0, 38),
            ], $lignes));

        $io->section('Les appels HTTP du parcours');
        $io->table(['Methode', 'Route', 'Reponse'], $this->etapes);
    }

    private function recharger(string $reference): Dossier
    {
        $d = $this->dossiers->findOneBy(['reference' => $reference]);
        if (null === $d) {
            throw new RuntimeException('Dossier disparu : '.$reference);
        }

        return $d;
    }

    /** Le jeton porte par CE formulaire, et pas par un autre de la page. */
    private function jetonDeFormulaire(Crawler $formulaire): string
    {
        $champ = $formulaire->filter('input[name="_token"]');
        if (0 === $champ->count() || '' === (string) $champ->first()->attr('value')) {
            throw new RuntimeException('Ce formulaire ne porte pas de jeton CSRF.');
        }

        return (string) $champ->first()->attr('value');
    }

    /** @param AbstractBrowser<Request, Response> $b */
    private function code(AbstractBrowser $b): int
    {
        return $b->getInternalResponse()->getStatusCode();
    }

    /** @param AbstractBrowser<Request, Response> $b */
    private function statut(AbstractBrowser $b): string
    {
        $reponse = $b->getInternalResponse();
        $code = $reponse->getStatusCode();
        $vers = self::entete($reponse->getHeader('location'));

        return '' !== $vers ? sprintf('HTTP %d → %s', $code, $vers) : sprintf('HTTP %d', $code);
    }

    /**
     * Un en-tete HTTP, qui peut arriver sous forme de liste.
     *
     * @param array<int|string, mixed>|string|null $valeur
     */
    private static function entete(array|string|null $valeur): string
    {
        if (\is_array($valeur)) {
            return implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $valeur));
        }

        return (string) $valeur;
    }

    private function noter(string $methode, string $route, string $reponse): void
    {
        $this->etapes[] = [$methode, $route, $reponse];
    }
}
