<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\ControleIbanMod97;
use App\Remboursement\Demo\FabriqueMesure;
use App\Remboursement\Demo\Navigateur;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\Ia\AnalyseDossierService;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * R04 A L'EPREUVE — les deux etages, sur des cas reels, dans les vrais ecrans.
 *
 * TROIS EPREUVES, ET LA TROISIEME COMPTE AUTANT QUE LES DEUX PREMIERES.
 *
 *   1. A L'ENTREE. Le comptable soumet le formulaire de son ecran avec un IBAN
 *      de structure parfaite mais de cle fausse. Le dossier ne progresse pas,
 *      et l'ecran dit pourquoi.
 *
 *   2. DEVANT LA CAISSE. Un dossier deja valide par le directeur, portant un
 *      IBAN de cle fausse, demande sa generation sur le vrai bus. Aucun XML,
 *      aucun fichier de paiement, aucun CSV comptable, et la tentative est
 *      tracee.
 *
 *   3. LA CONTRE-EPREUVE. Le meme parcours avec un IBAN valide produit ses
 *      fichiers. Un renforcement qui bloquerait aussi les paiements legitimes
 *      ne serait pas un controle, ce serait une panne.
 *
 * TOUT SE PASSE DANS UNE TRANSACTION ANNULEE. L'univers jury n'est pas touche.
 */
#[AsCommand(
    name: 'app:demo:garde-iban-mod97',
    description: 'RENFORCEMENT R04 : la cle de controle de l\'IBAN, a l\'entree et devant la caisse.',
)]
final class GardeIbanCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly DossierRepository $dossiers,
        private readonly FabriqueMesure $fabrique,
        private readonly AnalyseDossierService $analyse,
        private readonly WorkflowRemboursement $workflow,
        private readonly MessageBusInterface $bus,
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
                'Mot de passe de la porte d\'acces (epreuve d\'entree seulement).');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('R04 — la cle de controle de l\'IBAN, a l\'epreuve');
        $io->text('RENFORCEMENT DE LA COPIE DE DEMONSTRATION. Le module herite verifie la');
        $io->text('structure de l\'IBAN ; il ne verifie jamais sa cle de controle MOD 97.');

        $this->fabrique->semer(20260922);
        $fautes = 0;

        $fautes += $this->epreuveEntree($io,
            (string) $entree->getOption('identifiant'),
            (string) $entree->getOption('mot-de-passe'));
        $fautes += $this->epreuveCaisse($io);
        $fautes += $this->contreEpreuve($io);

        if ($fautes > 0) {
            $io->error(sprintf('%d controle(s) en echec.', $fautes));

            return Command::FAILURE;
        }

        $io->success('R04 tient aux deux etages, et il ne bloque pas les paiements legitimes.');

        return Command::SUCCESS;
    }

    /**
     * Premier etage : l'ecran du comptable refuse la cle fausse.
     *
     * On soumet le formulaire de l'ecran, avec son propre jeton, comme le
     * ferait la personne. Rien n'est appele en raccourci.
     */
    private function epreuveEntree(SymfonyStyle $io, string $identifiant, string $motDePasse): int
    {
        $io->section('1. A l\'entree — le comptable soumet une cle fausse');

        if ('' === $motDePasse) {
            $io->warning('Sans --mot-de-passe, l\'epreuve d\'entree par les ecrans est ignoree. '
                .'Les deux autres epreuves, elles, ne passent pas par HTTP.');

            return 0;
        }

        $this->fabrique->ouvrir();
        try {
            $dossier = $this->dossierACleFausse(1);
            $this->analyse->analyser($dossier);
            $this->em->flush();
            $reference = (string) $dossier->getReference();
            $id = (int) $dossier->getId();
            $ibanFaux = (string) $dossier->getIbanClient();

            $navigateur = new Navigateur($this->noyau, $identifiant, $motDePasse);
            if (!$navigateur->ouvrir()) {
                $io->error('La porte d\'acces a refuse : epreuve d\'entree non concluante.');

                return 1;
            }
            $ecran = '/remboursement/dossier/'.$id;
            $page = $navigateur->poste('comptable', $ecran);
            $formulaire = $page->filter('form[action="'.$ecran.'/valider"]');
            if (0 === $formulaire->count()) {
                $io->error('Le formulaire de validation comptable est absent de l'."'".'ecran.');

                return 1;
            }

            // Le formulaire de l'ecran, avec ses propres valeurs et son propre
            // jeton. On ne change qu'une chose : l'IBAN retenu porte une cle
            // fausse. Tout le reste est ce que le module propose.
            $form = $formulaire->form();
            $form->setValues(['iban' => $ibanFaux, 'code_icar' => 'ICAR-R04',
                'libelle' => 'EPREUVE R04', 'code_comptable' => '4111000',
                'role_tiers' => 'COMPTANT']);
            $navigateur->soumettre($form);

            $this->em->clear();
            $apres = $this->dossiers->findOneBy(['reference' => $reference]);
            $statut = $apres?->getStatut()->value ?? '(disparu)';
            $transmis = (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM remboursement.dossier_transition
                  WHERE dossier_id = ? AND transition = 'envoyer_directeur'", [$id]);
            $visible = $navigateur->texteVisible().' '.$navigateur->contenu();

            return $this->rendre($io, [
                ['Le dossier ne progresse pas', 'statut '.$statut, 'a_verifier' === $statut],
                ['Aucune transmission au directeur', $transmis.' transition(s)', 0 === $transmis],
                ['Le motif est lisible a l\'ecran', ControleIbanMod97::MESSAGE,
                    str_contains($visible, ControleIbanMod97::MESSAGE)],
                ['La structure, elle, etait recevable', 'oui',
                    ControleIbanMod97::structureRecevable($ibanFaux)],
            ]);
        } finally {
            $this->fabrique->annuler();
        }
    }

    /** Second etage : devant la caisse, rien ne sort. */
    private function epreuveCaisse(SymfonyStyle $io): int
    {
        $io->section('2. Devant la caisse — la demande de generation est refusee');

        $this->fabrique->ouvrir();
        try {
            $dossier = $this->dossierACleFausse(2);
            $this->menerAuDirecteur($dossier);
            $id = (int) $dossier->getId();

            $this->bus->dispatch(new GenererFichiers($id), [new TransportNamesStamp(['sync'])]);
            $this->em->clear();

            $pieces = $this->cnx->fetchAllKeyValue(
                'SELECT type, count(*) FROM remboursement.dossier_piece
                  WHERE dossier_id = ? GROUP BY type', [$id]);
            $sepa = (int) ($pieces['fichier_sepa'] ?? 0);
            $od = (int) ($pieces['fichier_od'] ?? 0);
            $xml = (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM remboursement.dossier_piece
                  WHERE dossier_id = ? AND mime_type = 'application/xml'", [$id]);
            $trace = (string) $this->cnx->fetchOne(
                "SELECT coalesce(max(message), '') FROM remboursement.rejeu_evenement
                  WHERE dossier_id = ? AND source = 'garde-iban-mod97'", [$id]);
            $empreinte = (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM remboursement.paiement_empreinte WHERE dossier_id = ?', [$id]);

            return $this->rendre($io, [
                ['Aucun fichier de paiement', $sepa.' fichier(s) SEPA', 0 === $sepa],
                ['Aucun XML produit', $xml.' document(s) XML', 0 === $xml],
                ['Aucun CSV comptable', $od.' fichier(s) OD', 0 === $od],
                ['Le motif R04 est trace', '' === $trace ? 'aucune trace' : $trace,
                    str_contains($trace, ControleIbanMod97::CODE)
                    && str_contains($trace, ControleIbanMod97::MESSAGE)],
                ['Aucune empreinte de paiement laissee', $empreinte.' empreinte(s)', 0 === $empreinte],
            ]);
        } finally {
            $this->fabrique->annuler();
        }
    }

    /** La contre-epreuve : un IBAN valide doit passer, sinon R04 est une panne. */
    private function contreEpreuve(SymfonyStyle $io): int
    {
        $io->section('3. Contre-epreuve — un IBAN valide produit bien ses fichiers');

        $this->fabrique->ouvrir();
        try {
            $rang = 3;
            $contrat = $this->fabrique->contrat($rang);
            $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC,
                $this->fabrique->etablissement($rang), $this->fabrique->client($rang),
                $this->fabrique->iban($rang),
                number_format((float) $contrat->getErTtc(), 2, '.', ''),
                $contrat->getImmat(), 'ICAR-R04');
            $this->menerAuDirecteur($dossier);
            $id = (int) $dossier->getId();

            $this->bus->dispatch(new GenererFichiers($id), [new TransportNamesStamp(['sync'])]);
            $this->em->clear();

            $pieces = $this->cnx->fetchAllKeyValue(
                'SELECT type, count(*) FROM remboursement.dossier_piece
                  WHERE dossier_id = ? GROUP BY type', [$id]);
            $trace = (int) $this->cnx->fetchOne(
                "SELECT count(*) FROM remboursement.rejeu_evenement
                  WHERE dossier_id = ? AND source = 'garde-iban-mod97'", [$id]);

            return $this->rendre($io, [
                ['Le fichier de paiement est produit',
                    (string) ((int) ($pieces['fichier_sepa'] ?? 0)).' fichier(s) SEPA',
                    1 === (int) ($pieces['fichier_sepa'] ?? 0)],
                ['Le CSV comptable est produit',
                    (string) ((int) ($pieces['fichier_od'] ?? 0)).' fichier(s) OD',
                    1 === (int) ($pieces['fichier_od'] ?? 0)],
                ['R04 n\'a rien reproche', $trace.' trace(s)', 0 === $trace],
            ]);
        } finally {
            $this->fabrique->annuler();
        }
    }

    /** Un dossier dont l'IBAN a une structure parfaite et une cle fausse. */
    private function dossierACleFausse(int $rang): Dossier
    {
        $contrat = $this->fabrique->contrat($rang);
        $valide = $this->fabrique->iban($rang);
        $fausse = substr($valide, 0, -1).(string) ((((int) substr($valide, -1)) + 1) % 10);

        return $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC,
            $this->fabrique->etablissement($rang), $this->fabrique->client($rang), $fausse,
            number_format((float) $contrat->getErTtc(), 2, '.', ''),
            $contrat->getImmat(), 'ICAR-R04');
    }

    /** Le dossier, mene jusqu'a « valide directeur » par le vrai workflow. */
    private function menerAuDirecteur(Dossier $dossier): void
    {
        $this->analyse->analyser($dossier);
        $dossier->enregistrerValidation([
            'nom' => $dossier->getNomClient(),
            'iban' => $dossier->getIbanClient(),
            'bic' => $dossier->getBicClient(),
            'montant' => $dossier->getMontant(),
            'immatriculation' => $dossier->getImmatriculation(),
            'code_icar' => $dossier->getCodeIcar() ?: 'ICAR-R04',
            'libelle' => 'EPREUVE R04',
            'code_comptable' => '4111000',
            'role_tiers' => 'COMPTANT',
        ], 'comptable@demonstration.invalid');
        $this->workflow->appliquer($dossier, 'envoyer_directeur',
            'comptable@demonstration.invalid', null, false, true, true);
        $this->workflow->appliquer($dossier, 'valider_directeur',
            'Directeur (e-mail)', null, false, true, true);
        $this->em->flush();
    }

    /**
     * @param list<array{0: string, 1: string, 2: bool}> $controles
     */
    private function rendre(SymfonyStyle $io, array $controles): int
    {
        $fautes = 0;
        $lignes = [];
        foreach ($controles as [$quoi, $vu, $ok]) {
            $lignes[] = [$quoi, $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->table(['Controle', 'Constate', 'Verdict'], $lignes);

        return $fautes;
    }
}
