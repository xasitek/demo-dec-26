<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\Navigateur;
use App\Remboursement\Demo\SourcePiece;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\ControleBuyBack;
use App\Remboursement\Service\FabricantPieces;
use App\Remboursement\Service\GenerationFichiersComptables;
use App\Remboursement\Service\Ia\AnalyseDossierService;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

/**
 * Les quatre scenes adversariales de l'outil 8, jouees dans les vrais ecrans.
 *
 * Chaque cas passe par le noyau HTTP complet et par les services herites. Rien
 * n'est mime : quand le formulaire de depot refuse, c'est le formulaire qui
 * refuse ; quand l'extraction tombe, c'est le fournisseur qui leve son
 * exception ; quand un doublon apparait, c'est la cle du module qui le voit.
 *
 * Les faits documentaires -- IBAN divergent sur le RIB, montant de facture
 * different, mention manuscrite, vehicule gage, document illisible, panne du
 * fournisseur -- viennent du MONDE synthetique, pas de ce fichier : ils sont
 * charges dans `remboursement.source_piece_demo` et la fabrique de documents
 * les lit. Rien n'est code en dur ici.
 */
#[AsCommand(
    name: 'app:demo:cas-adversariaux',
    description: 'Joue les quatre cas adversariaux de l\'outil 8 dans les vrais ecrans.',
)]
final class CasAdversariauxCommand extends Command
{
    public function __construct(
        private readonly HttpKernelInterface $noyau,
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly DossierRepository $dossiers,
        private readonly ControleBuyBack $controleBuyBack,
        private readonly FabricantPieces $fabricant,
        private readonly SourcePiece $source,
        private readonly AnalyseDossierService $analyse,
        private readonly WorkflowRemboursement $workflow,
        private readonly MessageBusInterface $bus,
        private readonly string $racineProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cas', null, InputOption::VALUE_REQUIRED,
                'A (surpaiement), B (doublon), C (IBAN divergent), D (panne et illisible), ou tous.', 'tous')
            ->addOption('identifiant', null, InputOption::VALUE_REQUIRED,
                'Identifiant de la porte d\'acces.', 'jury')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED,
                'Mot de passe de la porte d\'acces.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Cas adversariaux de l\'outil 8');

        $motDePasse = (string) $entree->getOption('mot-de-passe');
        if ('' === $motDePasse) {
            $io->error('Le mot de passe de la porte d\'acces est requis (--mot-de-passe).');

            return Command::FAILURE;
        }
        $identifiant = (string) $entree->getOption('identifiant');

        $cas = strtoupper((string) $entree->getOption('cas'));
        $aJouer = 'TOUS' === $cas ? ['A', 'B', 'C', 'D'] : [$cas];

        $bilan = [];
        foreach ($aJouer as $c) {
            $bilan[$c] = match ($c) {
                'A' => $this->casA($io, $identifiant, $motDePasse),
                'B' => $this->casB($io, $identifiant, $motDePasse),
                'C' => $this->casC($io, $identifiant, $motDePasse),
                'D' => $this->casD($io, $identifiant, $motDePasse),
                default => throw new InvalidArgumentException('Cas inconnu : '.$c),
            };
        }

        $io->section('Bilan des cas joues');
        $io->table(['Cas', 'Verdict'], array_map(
            static fn (string $c): array => [$c, $bilan[$c] ? 'CONFORME' : 'ECHEC'], array_keys($bilan)));

        return \in_array(false, $bilan, true) ? Command::FAILURE : Command::SUCCESS;
    }

    // =====================================================================
    // CAS A — surpaiement de l'engagement de reprise
    // =====================================================================
    private function casA(SymfonyStyle $io, string $identifiant, string $motDePasse): bool
    {
        $io->section('CAS A — Buy Back : demande superieure de 600 € a l\'engagement');

        $dossier = $this->parScenario('SC-08-07');
        if (null === $dossier) {
            $io->error('Aucun dossier SC-08-07.');

            return false;
        }

        $montant = (float) $dossier->getMontant();
        $c = $this->controleBuyBack->pour($dossier->getImmatriculation(), $montant);
        if (null === $c) {
            $io->error('Le vehicule de ce dossier n\'a pas de contrat Buy Back.');

            return false;
        }

        $io->table(['Ce que le contrat dit', 'Valeur'], [
            ['Dossier', (string) $dossier->getReference()],
            ['Immatriculation', (string) $dossier->getImmatriculation()],
            ['Engagement de reprise TTC', number_format($c['erTtc'], 2, ',', ' ').' €'],
            ['Montant demande', number_format($montant, 2, ',', ' ').' €'],
            ['Ecart', number_format((float) $c['ecart'], 2, ',', ' ').' €'],
            ['Seuil de tolerance', number_format(ControleBuyBack::SEUIL, 2, ',', ' ').' € (ControleBuyBack::SEUIL)'],
            ['Regle appliquee', 'un ecart au-dela du seuil est un SURPAIEMENT : le depot est refuse'],
            ['Verdict du service herite', $c['surpaiement'] ? 'SURPAIEMENT' : 'aucun'],
            ['Statut actuel du dossier', $dossier->getStatut()->value],
            ['Ou en est ce dossier', 'au depot. Dans le parcours reel, le formulaire le refuse :'
                .' il n\'atteint jamais la file du comptable'],
        ]);

        $navigateur = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$navigateur->ouvrir()) {
            $io->error('La porte d\'acces a refuse.');

            return false;
        }

        // ---- 1. le controle en temps reel de l'ecran de depot
        $navigateur->poste('secretaire', '/remboursement/deposer');
        $navigateur->requete('GET', sprintf('/remboursement/deposer/controle-buyback?immat=%s&montant=%s',
            rawurlencode((string) $dossier->getImmatriculation()), rawurlencode((string) $montant)));
        $fragment = $navigateur->texteVisible();
        $io->text('1. Le controle en temps reel de l\'ecran de depot repond :');
        $io->text('   « '.mb_substr($fragment, 0, 220).' »');
        $vuEnDirect = str_contains(mb_strtolower($fragment), 'engagement');

        // ---- 2. le depot reel, avec toutes ses pieces
        $io->text('2. La secretaire depose vraiment, pieces comprises.');
        $ecran = $navigateur->aller('/remboursement/deposer');
        $formulaire = $ecran->filter('form[method="post"]');
        if (0 === $formulaire->count()) {
            $io->error('Le formulaire de depot est absent de l\'ecran.');

            return false;
        }

        $reponse = $navigateur->xhr('POST', '/remboursement/deposer',
            [
                '_token' => $navigateur->jeton($formulaire),
                'motif' => DossierMotif::RACHAT_SEC->value,
                'etablissement' => (string) $dossier->getEtablissementCode(),
                'nom_client' => (string) $dossier->getNomClient(),
                'iban_client' => (string) $dossier->getIbanClient(),
                'bic_client' => (string) $dossier->getBicClient(),
                'montant' => number_format($montant, 2, ',', ''),
                'immatriculation' => (string) $dossier->getImmatriculation(),
                'code_icar' => '' !== (string) $dossier->getCodeIcar() ? (string) $dossier->getCodeIcar() : 'ICAR-DEMO',
            ],
            $this->fichiersDepot($dossier));

        $erreurs = \is_array($reponse) ? ($reponse['erreurs'] ?? []) : [];
        $refuse = \is_array($reponse) && false === ($reponse['ok'] ?? null);
        $surpaiementNomme = false;
        foreach ($erreurs as $e) {
            if (str_contains(mb_strtolower((string) $e), 'engagement de reprise')) {
                $surpaiementNomme = true;
            }
        }
        $io->text('   Reponse du module : HTTP '.$navigateur->code().($refuse ? ' — depot REFUSE' : ' — depot accepte'));
        foreach ($erreurs as $e) {
            $io->text('   • '.(string) $e);
        }

        // ---- 3. le renforcement : le surpaiement rebarre devant la caisse
        $io->text('3. RENFORCEMENT DE LA COPIE : le meme controle rejoue avant le paiement.');
        $avantEvts = $this->compterEvenements((int) $dossier->getId());
        $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()));
        $this->consommer();
        $apresEvts = $this->compterEvenements((int) $dossier->getId());
        $trace = $this->cnx->fetchAssociative(
            'SELECT decision, message FROM remboursement.rejeu_evenement
              WHERE dossier_id = ? ORDER BY id DESC LIMIT 1', [(int) $dossier->getId()]);
        if (false !== $trace) {
            $io->text('   '.(string) $trace['message']);
        }

        // ---- 4. le controle automatique demande : aucun paiement pour SC-08-07
        $paiements = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM remboursement.dossier_piece p
               JOIN remboursement.dossier d ON d.id = p.dossier_id
               JOIN remboursement_verite.dossier v ON v.reference = d.reference
              WHERE v.code_scenario = 'SC-08-07' AND p.type IN (?, ?)",
            [GenerationFichiersComptables::TYPE_SEPA, GenerationFichiersComptables::TYPE_OD]);
        $population = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM remboursement_verite.dossier WHERE code_scenario = 'SC-08-07'");

        $controles = [
            ['Le controle en direct nomme l\'engagement', $vuEnDirect ? 'oui' : 'non', $vuEnDirect],
            ['Le depot est refuse', $refuse ? 'oui' : 'non', $refuse],
            ['Le refus nomme le surpaiement', $surpaiementNomme ? 'oui' : 'non', $surpaiementNomme],
            ['La garde avant paiement a trace un refus', (string) ($apresEvts - $avantEvts), $apresEvts > $avantEvts],
            ['Le dossier n\'a pas atteint la generation',
                $this->recharger((string) $dossier->getReference())->getStatut()->value,
                DossierStatut::GENERATION_EN_COURS !== $this->recharger((string) $dossier->getReference())->getStatut()],
            [sprintf('0 fichier de paiement sur les %d dossiers SC-08-07', $population),
                (string) $paiements, 0 === $paiements],
        ];

        return $this->afficherControles($io, $controles);
    }

    // =====================================================================
    // CAS B — doublon immediat
    // =====================================================================
    private function casB(SymfonyStyle $io, string $identifiant, string $motDePasse): bool
    {
        $io->section('CAS B — la secretaire depose un dossier qui ressemble a un existant');

        // Un dossier actif, rachat sec, dont l'immatriculation est la cle.
        $reference = $this->cnx->fetchOne(
            "SELECT d.reference FROM remboursement.dossier d
              WHERE d.motif = 'rachat_sec' AND d.statut = 'depose'
                AND d.immatriculation IS NOT NULL
              ORDER BY d.id LIMIT 1");
        if (!\is_string($reference)) {
            $io->error('Aucun dossier de rachat actif.');

            return false;
        }
        $existant = $this->recharger($reference);

        $io->text(sprintf('Dossier existant : %s, immatriculation %s, %s €, etablissement %s.',
            (string) $existant->getReference(), (string) $existant->getImmatriculation(),
            number_format((float) $existant->getMontant(), 2, ',', ' '),
            (string) $existant->getEtablissementCode()));

        $navigateur = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$navigateur->ouvrir()) {
            $io->error('La porte d\'acces a refuse.');

            return false;
        }

        $avant = (int) $this->cnx->fetchOne('SELECT count(*) FROM remboursement.dossier');
        $ecran = $navigateur->poste('secretaire', '/remboursement/deposer');
        $formulaire = $ecran->filter('form[method="post"]');
        $reponse = $navigateur->xhr('POST', '/remboursement/deposer',
            [
                '_token' => $navigateur->jeton($formulaire),
                'motif' => DossierMotif::RACHAT_SEC->value,
                'etablissement' => (string) $existant->getEtablissementCode(),
                'nom_client' => (string) $existant->getNomClient(),
                'iban_client' => (string) $existant->getIbanClient(),
                'bic_client' => (string) $existant->getBicClient(),
                // Meme immatriculation, meme montant : la cle du module est la meme.
                'montant' => number_format((float) $existant->getMontant(), 2, ',', ''),
                'immatriculation' => (string) $existant->getImmatriculation(),
                'code_icar' => '' !== (string) $existant->getCodeIcar() ? (string) $existant->getCodeIcar() : 'ICAR-DEMO',
            ],
            $this->fichiersDepot($existant));

        $accepte = \is_array($reponse) && true === ($reponse['ok'] ?? null);
        $apres = (int) $this->cnx->fetchOne('SELECT count(*) FROM remboursement.dossier');
        $io->text(sprintf('Depot : HTTP %d — %s (population %d → %d).',
            $navigateur->code(), $accepte ? 'accepte' : 'refuse', $avant, $apres));
        if (!\is_array($reponse) || !$accepte) {
            foreach ((array) ($reponse['erreurs'] ?? []) as $e) {
                $io->text('   • '.(string) $e);
            }
        }

        $nouveau = $this->cnx->fetchOne(
            'SELECT reference FROM remboursement.dossier ORDER BY id DESC LIMIT 1');
        if (!\is_string($nouveau) || $nouveau === $reference) {
            $io->error('Aucun nouveau dossier : le cas ne peut pas etre joue.');

            return false;
        }
        // L'analyse part sur la file : c'est le VRAI worker qui la consomme, et
        // sans lui le dossier reste en « extraction » -- l'ecran comptable ne
        // montrerait rien, faute d'etre encore arrive dans sa file.
        $tours = $this->consommerJusqua($nouveau, DossierStatut::EXTRACTION_IA);
        $this->em->clear();
        $jumeau = $this->recharger($nouveau);
        $io->text(sprintf('Apres %d passage(s) du worker : statut %s.', $tours, $jumeau->getStatut()->value));

        // ---- l'ecran du comptable doit montrer le doublon
        $ecranComptable = '/remboursement/dossier/'.$jumeau->getId();
        $comptable = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$comptable->ouvrir()) {
            $io->error('La porte d\'acces du poste comptable a refuse.');

            return false;
        }
        $comptable->poste('comptable', $ecranComptable);
        $io->text(sprintf('Ecran comptable atteint : %s (%s), %d caracteres.',
            $comptable->ou(), $comptable->statut(), \strlen($comptable->contenu())));
        $texte = $comptable->texteVisible();
        $minuscule = mb_strtolower($texte);

        $signaux = [
            'meme immatriculation' => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $jumeau->getImmatriculation()))
                === preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $existant->getImmatriculation())),
            'meme cle anti-doublon' => (string) $jumeau->getCleDoublon() === (string) $existant->getCleDoublon(),
            'meme empreinte IBAN' => (string) $jumeau->getIbanHash() === (string) $existant->getIbanHash(),
            'meme montant' => abs((float) $jumeau->getMontant() - (float) $existant->getMontant()) < 0.005,
        ];
        $io->table(['Signal commun au dossier existant', 'Constate'], array_map(
            static fn (string $k): array => [$k, $signaux[$k] ? 'oui' : 'non'], array_keys($signaux)));

        $bandeau = str_contains($minuscule, 'doublon');
        $reference_visible = str_contains($texte, (string) $existant->getReference());
        $io->text('Ecran comptable : '.($bandeau ? 'un doublon est signale' : 'AUCUN signal de doublon'));
        $io->text('Dossier existant nomme a l\'ecran : '.($reference_visible ? 'oui' : 'non'));

        // ---- la decision du comptable arrete la progression
        $actions = $this->workflow->transitionsPossibles($jumeau);
        $io->text('Actions que le module autorise : '.implode(', ', $actions));
        $marque = false;
        if (\in_array('marquer_doublon', $actions, true)) {
            $page = $comptable->aller($ecranComptable);
            $form = $page->filter('form[action="'.$ecranComptable.'/transition"] input[value="marquer_doublon"]');
            if ($form->count() > 0) {
                $porteur = $form->closest('form');
                if (null !== $porteur) {
                    $comptable->requete('POST', $ecranComptable.'/transition', [
                        '_token' => $comptable->jeton($porteur),
                        'transition' => 'marquer_doublon',
                        'commentaire' => 'Doublon confirme : meme vehicule, meme montant, meme compte.',
                    ]);
                    $marque = true;
                }
            }
        }
        $this->em->clear();
        $jumeau = $this->recharger($nouveau);

        $controles = [
            ['Le module a calcule la meme cle anti-doublon', $signaux['meme cle anti-doublon'] ? 'oui' : 'non', $signaux['meme cle anti-doublon']],
            ['Le module a reconnu la meme empreinte IBAN', $signaux['meme empreinte IBAN'] ? 'oui' : 'non', $signaux['meme empreinte IBAN']],
            ['L\'ecran comptable signale le doublon', $bandeau ? 'oui' : 'non', $bandeau],
            ['Le dossier existant est nomme', $reference_visible ? 'oui' : 'non', $reference_visible],
            ['Le comptable a pu decider', $marque ? 'oui' : 'non', $marque],
            ['Le jumeau est arrete', $jumeau->getStatut()->value, DossierStatut::DOUBLON === $jumeau->getStatut()],
            ['Le jumeau n\'a aucun fichier de paiement', (string) $this->compterPaiements((int) $jumeau->getId()), 0 === $this->compterPaiements((int) $jumeau->getId())],
        ];

        $io->text('Le dossier depose pendant cette scene est un dossier DE SESSION : il n\'appartient');
        $io->text('pas a l\'univers charge, et le reset de demonstration le retire (--nettoyer-session).');

        return $this->afficherControles($io, $controles);
    }

    // =====================================================================
    // CAS C — IBAN divergent
    // =====================================================================
    private function casC(SymfonyStyle $io, string $identifiant, string $motDePasse): bool
    {
        $io->section('CAS C — le RIB ne porte pas l\'IBAN saisi');

        $dossier = $this->parScenario('SC-08-12');
        if (null === $dossier) {
            $io->error('Aucun dossier SC-08-12.');

            return false;
        }
        $source = $this->source->pour((int) $dossier->getId());
        $ibanSaisi = (string) $dossier->getIbanClient();
        $ibanRib = (string) ($source['iban_rib'] ?? '');

        $io->table(['Element', 'Valeur'], [
            ['Dossier', (string) $dossier->getReference()],
            ['IBAN saisi par la secretaire', $this->masquer($ibanSaisi)],
            ['IBAN porte par le RIB', $this->masquer($ibanRib)],
            ['Les deux diverge-t-ils ?', $ibanSaisi !== $ibanRib ? 'oui' : 'NON'],
        ]);
        if ($ibanSaisi === $ibanRib) {
            $io->error('Ce dossier ne porte pas de divergence : le monde ne l\'a pas voulu.');

            return false;
        }

        // ---- les pieces, puis la lecture par le vrai service
        $this->preparer($dossier);
        $this->em->clear();
        $dossier = $this->recharger((string) $dossier->getReference());

        $io->table(['Champ', 'Saisie', 'Lu sur la piece', 'Valeur retenue'], [
            ['IBAN', $this->masquer($ibanSaisi), $this->masquer((string) $dossier->getControleIban()),
                null === $dossier->getValideIban() ? 'vide — le comptable n\'a pas statue' : $this->masquer((string) $dossier->getValideIban())],
        ]);

        $lectureJuste = (string) $dossier->getControleIban() === $ibanRib;
        $retenuVide = null === $dossier->getValideIban();

        // ---- l'ecran comptable doit signaler la divergence
        $navigateur = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$navigateur->ouvrir()) {
            $io->error('La porte d\'acces a refuse.');

            return false;
        }
        $ecran = '/remboursement/dossier/'.$dossier->getId();
        $page = $navigateur->poste('comptable', $ecran);
        $texte = $navigateur->texteVisible();
        $divergenceVue = str_contains(mb_strtolower($texte), 'différent de la valeur lue')
            || str_contains(mb_strtolower($texte), 'a verifier')
            || str_contains(mb_strtolower($texte), 'à vérifier');
        $io->text('Ecran comptable : '.($divergenceVue ? 'la divergence est signalee' : 'AUCUN signal'));
        $io->text('Verdict du module : '.((string) $dossier->getVerdictInfo() ?: 'aucun'));

        // ---- le comptable ouvre le RIB, puis tranche
        $piece = $this->cnx->fetchAssociative(
            "SELECT id FROM remboursement.dossier_piece WHERE dossier_id = ? AND type = 'rib' LIMIT 1",
            [(int) $dossier->getId()]);
        if (false !== $piece) {
            $navigateur->requete('GET', '/remboursement/dossier/piece/'.$piece['id']);
            $io->text(sprintf('Le comptable ouvre le RIB : %s', $navigateur->statut()));
        }

        $formulaire = $page->filter('form[action="'.$ecran.'/valider"]');
        if (0 === $formulaire->count()) {
            $io->error('Le formulaire du comptable est absent.');

            return false;
        }
        $form = $formulaire->form();
        $valeurs = $form->getPhpValues();
        // Le comptable retient l'IBAN DU RIB : c'est la piece qui fait foi.
        $valeurs['iban'] = $ibanRib;
        $navigateur->requete('POST', $ecran.'/valider', $valeurs);
        $io->text('Le comptable retient l\'IBAN du RIB : '.$navigateur->statut());

        $this->em->clear();
        $dossier = $this->recharger((string) $dossier->getReference());

        // ---- ce que le journal garde
        $io->section('Ce que le journal garde de la decision');
        $io->table(['Element', 'Valeur'], [
            ['Valeur saisie', $this->masquer($ibanSaisi)],
            ['Valeur lue sur la piece', $this->masquer((string) $dossier->getControleIban())],
            ['Valeur retenue', $this->masquer((string) $dossier->getValideIban())],
            ['Auteur de la decision', (string) $dossier->getValidePar()],
            ['Date de la decision', null === $dossier->getModifieLe() ? '—' : $dossier->getModifieLe()->format('d/m/Y H:i:s')],
            ['Transition tracee', (string) $this->cnx->fetchOne(
                'SELECT transition FROM remboursement.dossier_transition WHERE dossier_id = ? ORDER BY id DESC LIMIT 1',
                [(int) $dossier->getId()])],
        ]);

        $controles = [
            ['La lecture rend l\'IBAN du RIB, pas la saisie', $lectureJuste ? 'oui' : 'non', $lectureJuste],
            ['La valeur retenue etait vide avant decision', $retenuVide ? 'oui' : 'non', $retenuVide],
            ['L\'ecran signale la divergence', $divergenceVue ? 'oui' : 'non', $divergenceVue],
            ['La valeur retenue est celle du RIB', $this->masquer((string) $dossier->getValideIban()),
                (string) $dossier->getValideIban() === $ibanRib],
            ['La decision porte un auteur', (string) $dossier->getValidePar(), '' !== (string) $dossier->getValidePar()],
            ['La decision est datee', null !== $dossier->getModifieLe() ? 'oui' : 'non', null !== $dossier->getModifieLe()],
            ['Le dossier a avance vers le directeur', $dossier->getStatut()->value,
                DossierStatut::A_VALIDER_DIRECTEUR === $dossier->getStatut()],
        ];

        return $this->afficherControles($io, $controles);
    }

    // =====================================================================
    // CAS D — analyse indisponible, et document illisible
    // =====================================================================
    private function casD(SymfonyStyle $io, string $identifiant, string $motDePasse): bool
    {
        $io->section('CAS D — deux situations que rien ne doit confondre');
        $io->text('Une PANNE du fournisseur de lecture est une indisponibilite technique.');
        $io->text('Un document ILLISIBLE est un fait documentaire. Le module les traite differemment.');

        $panne = $this->parSourcePiece('panne_extraction');
        $illisible = $this->parSourcePiece('piece_illisible');
        if (null === $panne || null === $illisible) {
            $io->error('Le monde ne porte pas les deux situations.');

            return false;
        }

        $comptable = new Navigateur($this->noyau, $identifiant, $motDePasse);
        if (!$comptable->ouvrir()) {
            $io->error('La porte du poste comptable a refuse.');

            return false;
        }
        $premier = true;

        $resultats = [];
        foreach (['panne' => $panne, 'illisible' => $illisible] as $nature => $dossier) {
            $io->text('');
            $io->text(sprintf('--- %s : dossier %s', mb_strtoupper($nature), (string) $dossier->getReference()));
            $this->preparer($dossier);
            $this->em->clear();
            $dossier = $this->recharger((string) $dossier->getReference());

            $extractions = $this->cnx->fetchAllAssociative(
                'SELECT type_piece, statut, left(coalesce(message_erreur, \'\'), 70) AS erreur
                   FROM remboursement.extraction_piece WHERE dossier_id = ? ORDER BY id',
                [(int) $dossier->getId()]);
            $io->table(['Piece', 'Extraction', 'Message'], array_map(
                static fn (array $l): array => [
                    (string) $l['type_piece'], (string) $l['statut'], (string) $l['erreur'],
                ], $extractions));

            $enSurcharge = false;
            foreach ($extractions as $e) {
                if ('surcharge' === (string) $e['statut']) {
                    $enSurcharge = true;
                }
            }
            $transition = (string) $this->cnx->fetchOne(
                "SELECT transition FROM remboursement.dossier_transition
                  WHERE dossier_id = ? AND transition IN ('terminer_extraction', 'echec_extraction')
                  ORDER BY id DESC LIMIT 1", [(int) $dossier->getId()]);

            $io->text(sprintf('Statut : %s — transition d\'extraction : %s',
                $dossier->getStatut()->value, '' !== $transition ? $transition : 'aucune'));
            $io->text('Verdict du module : '.((string) $dossier->getVerdictInfo() ?: 'aucun'));

            // La reprise en main se verifie A L'ECRAN, sous l'identite du
            // comptable : les transitions possibles dependent de ses roles, et
            // une console n'a pas d'utilisateur connecte.
            $ecran = '/remboursement/dossier/'.$dossier->getId();
            if ($premier) {
                $comptable->poste('comptable', $ecran);
                $premier = false;
            } else {
                $comptable->aller($ecran);
            }
            $page = $comptable->crawler();
            $formulaire = $page->filter('form[action="'.$ecran.'/valider"]');
            $actions = $page->filter('form[action="'.$ecran.'/transition"] input[name="transition"]')
                ->each(static fn ($n): string => (string) $n->attr('value'));
            $reprise = $formulaire->count() > 0;
            $io->text(sprintf('Ecran comptable : HTTP %d — formulaire du comptable %s%s',
                $comptable->code(), $reprise ? 'present' : 'ABSENT',
                [] !== $actions ? ', actions : '.implode(', ', $actions) : ''));

            $resultats[$nature] = [
                'statut' => $dossier->getStatut(),
                'surcharge' => $enSurcharge,
                'transition' => $transition,
                'refus' => \in_array($dossier->getStatut(), [
                    DossierStatut::REFUSE, DossierStatut::DOUBLON, DossierStatut::FRAUDE], true),
                'reprise' => $reprise,
            ];
        }

        $controles = [
            ['PANNE : une extraction est en surcharge', $resultats['panne']['surcharge'] ? 'oui' : 'non', $resultats['panne']['surcharge']],
            ['PANNE : la transition est echec_extraction', $resultats['panne']['transition'], 'echec_extraction' === $resultats['panne']['transition']],
            ['PANNE : le dossier arrive en « a verifier »', $resultats['panne']['statut']->value, DossierStatut::A_VERIFIER === $resultats['panne']['statut']],
            ['PANNE : aucun refus produit par la panne', $resultats['panne']['refus'] ? 'REFUS' : 'aucun', !$resultats['panne']['refus']],
            ['PANNE : le comptable peut reprendre la main', $resultats['panne']['reprise'] ? 'oui' : 'non', $resultats['panne']['reprise']],
            ['ILLISIBLE : la lecture a reussi', $resultats['illisible']['surcharge'] ? 'non' : 'oui', !$resultats['illisible']['surcharge']],
            ['ILLISIBLE : la transition est terminer_extraction', $resultats['illisible']['transition'], 'terminer_extraction' === $resultats['illisible']['transition']],
            ['ILLISIBLE : aucun refus automatique', $resultats['illisible']['refus'] ? 'REFUS' : 'aucun', !$resultats['illisible']['refus']],
            ['Les deux situations sont distinguees',
                $resultats['panne']['transition'].' / '.$resultats['illisible']['transition'],
                $resultats['panne']['transition'] !== $resultats['illisible']['transition']],
        ];

        return $this->afficherControles($io, $controles);
    }

    // =====================================================================
    // outils communs
    // =====================================================================

    /** @param list<array{0: string, 1: string, 2: bool}> $controles */
    private function afficherControles(SymfonyStyle $io, array $controles): bool
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

        return 0 === $fautes;
    }

    private function parScenario(string $scenario): ?Dossier
    {
        $ref = $this->cnx->fetchOne(
            'SELECT d.reference FROM remboursement.dossier d
               JOIN remboursement_verite.dossier v ON v.reference = d.reference
              WHERE v.code_scenario = ? AND d.statut = ? ORDER BY d.montant DESC LIMIT 1',
            [$scenario, DossierStatut::DEPOSE->value]);

        return \is_string($ref) ? $this->dossiers->findOneBy(['reference' => $ref]) : null;
    }

    private function parSourcePiece(string $colonne): ?Dossier
    {
        $ref = $this->cnx->fetchOne(
            'SELECT d.reference FROM remboursement.dossier d
               JOIN remboursement.source_piece_demo s ON s.dossier_id = d.id
              WHERE s.'.$colonne.' AND d.statut = ? ORDER BY d.id LIMIT 1',
            [DossierStatut::DEPOSE->value]);

        return \is_string($ref) ? $this->dossiers->findOneBy(['reference' => $ref]) : null;
    }

    /**
     * Attache les pieces du dossier, puis laisse le vrai service les lire.
     *
     * Le dossier est RECHARGE depuis sa reference : entre deux cas, le gestionnaire
     * d'entites est vide, et un objet detache ne peut pas porter de nouvelles
     * pieces -- Doctrine refuserait, a juste titre.
     */
    private function preparer(Dossier $detache): void
    {
        $dossier = $this->recharger((string) $detache->getReference());

        $this->cnx->executeStatement(
            'DELETE FROM remboursement.extraction_piece WHERE dossier_id = ?', [(int) $dossier->getId()]);
        $this->cnx->executeStatement(
            'DELETE FROM remboursement.dossier_piece WHERE dossier_id = ?', [(int) $dossier->getId()]);

        foreach ($this->fabricant->typesPour($dossier->getMotif(), false) as $type) {
            $doc = $this->fabricant->pour($dossier, $type, null, $this->source->contexte($dossier, $type));
            $this->em->persist(new \App\Remboursement\Entity\DossierPiece(
                $dossier, $type, $doc['nom'], $doc['html'], $doc['mime'],
                \strlen($doc['html']), hash('sha256', $doc['html']),
                'preparation@demonstration.invalid'));
        }
        $this->em->flush();
        $this->analyse->analyser($dossier);
    }

    /**
     * Les pieces d'un depot, en fichiers reels : le formulaire attend des
     * televersements, pas des chaines.
     *
     * @return array<string, UploadedFile>
     */
    private function fichiersDepot(Dossier $dossier): array
    {
        $fichiers = [];
        foreach (array_keys($dossier->getMotif()->piecesRequises()) as $type) {
            // Le formulaire n'accepte que des PDF et des images : on lui donne
            // une image. Le garde-fou du module n'est pas contourne, il est
            // nourri — et le document porte les memes champs que sa version HTML.
            $doc = $this->fabricant->pourDepot($dossier, $type, null, $this->source->contexte($dossier, $type));
            $chemin = sys_get_temp_dir().'/depot-'.$type.'-'.bin2hex(random_bytes(4)).'.svg';
            file_put_contents($chemin, $doc['svg']);
            $fichiers[$type] = new UploadedFile($chemin, $doc['nom'], $doc['mime'], null, true);
        }

        return $fichiers;
    }

    private function compterPaiements(int $dossierId): int
    {
        return (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier_piece WHERE dossier_id = ? AND type IN (?, ?)',
            [$dossierId, GenerationFichiersComptables::TYPE_SEPA, GenerationFichiersComptables::TYPE_OD]);
    }

    private function compterEvenements(int $dossierId): int
    {
        return (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.rejeu_evenement WHERE dossier_id = ?', [$dossierId]);
    }

    /**
     * Fait tourner le worker jusqu'a ce que le dossier quitte un statut donne.
     *
     * La file peut contenir d'autres messages : consommer une seule fois ne
     * garantit pas que c'est CELUI du dossier qu'on suit. On boucle donc, avec
     * une borne : cinq passages, pas plus.
     */
    private function consommerJusqua(string $reference, DossierStatut $tantQue): int
    {
        for ($tour = 1; $tour <= 5; ++$tour) {
            $this->consommer();
            $this->em->clear();
            if ($tantQue !== $this->recharger($reference)->getStatut()) {
                return $tour;
            }
        }

        return 5;
    }

    private function consommer(): int
    {
        $worker = new Process(
            ['php', 'bin/console', 'messenger:consume', 'remboursement', '--limit=1', '--time-limit=20', '-q'],
            $this->racineProjet);
        $worker->setTimeout(60);
        $worker->run();

        return (int) $worker->getExitCode();
    }

    private function recharger(string $reference): Dossier
    {
        $d = $this->dossiers->findOneBy(['reference' => $reference]);
        if (null === $d) {
            throw new RuntimeException('Dossier disparu : '.$reference);
        }

        return $d;
    }

    private function masquer(?string $iban): string
    {
        if (null === $iban || '' === $iban) {
            return '—';
        }

        return \strlen($iban) < 12 ? $iban : substr($iban, 0, 8).' … '.substr($iban, -4);
    }
}
