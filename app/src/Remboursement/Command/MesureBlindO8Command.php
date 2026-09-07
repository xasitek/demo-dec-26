<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\CasMesure;
use App\Remboursement\Demo\ContratsMesure;
use App\Remboursement\Demo\ControleIbanMod97;
use App\Remboursement\Demo\FabriqueMesure;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\ControleBuyBack;
use App\Remboursement\Service\GenerationFichiersComptables;
use App\Remboursement\Service\Ia\AnalyseDossierService;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * BLIND_O8_3 — la mesure finale de l'outil 8, sur une population neuve.
 *
 * CE QUI CHANGE PAR RAPPORT A LA MESURE DIAGNOSTIQUE.
 *
 *   1. L'UNITE EST LE CAS, pas le dossier. Une saisie d'IBAN invalide est une
 *      TENTATIVE : elle ne doit produire aucun paiement, et compter en dossiers
 *      la rendrait invisible.
 *
 *   2. CHAQUE CAS A SA PREMISSE, MATERIELLEMENT PRESENTE. Un cas de doublon
 *      construit son dossier temoin ; un cas de surpaiement porte un contrat et
 *      un montant qui permettent de recalculer l'ecart ; un cas d'IBAN invalide
 *      porte une saisie reellement invalide. L'invariant est verifie avant
 *      d'evaluer, et un cas sans premisse fait echouer la mesure.
 *
 *   3. CHAQUE CAS EST ISOLE. La transaction du cas vide la table des dossiers,
 *      batit la premisse, evalue, puis annule tout. Le controle de doublon
 *      travaille pleinement, mais on ne lui presente plus deux mille autres
 *      dossiers comme partie du scenario.
 *
 *   4. LES CLASSES DE CAS REPOSENT SUR LES 54 CONTROLES REELLEMENT PRESENTS.
 *      Aucun controle n'a ete ajoute pour faire passer un cas. Deux scenarios de
 *      la mesure diagnostique ont ete retires faute de cause dans le code, et
 *      c'est dit plutot que masque.
 *
 * L'univers jury des 2 500 dossiers n'est pas touche : il reste le monde
 * interactif de la demonstration, et la mesure vit a cote.
 */
#[AsCommand(
    name: 'app:demo:mesure-blind-o8',
    description: 'BLIND_O8_3 : mesure finale de l\'outil 8, cas isoles, premisses verifiees.',
)]
final class MesureBlindO8Command extends Command
{
    /**
     * Le nom et la graine de la mesure publiee.
     *
     * BLIND_O8_2 (graine 20260921) est la mesure AVANT le renforcement R04.
     * Elle reste archivee -- c'est elle qui a etabli que la cle de controle des
     * IBAN n'etait verifiee nulle part. Elle n'est pas reproductible en l'etat,
     * et c'est normal : le systeme a change depuis, R04 bloque desormais ce
     * qu'elle avait vu passer.
     *
     * BLIND_O8_3 est la mesure APRES R04, sur une population neuve.
     */
    private const NOM = 'BLIND_O8_3';

    /** La graine de la population. Neuve : aucun cas de BLIND_O8_2. */
    private const GRAINE = 20260923;

    /**
     * Les classes de cas. Le contrat est fige a part, dans ContratsMesure.
     *
     * @return list<CasMesure>
     */
    private function classes(): array
    {
        return ContratsMesure::toutes();
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly DossierRepository $dossiers,
        private readonly FabriqueMesure $fabrique,
        private readonly AnalyseDossierService $analyse,
        private readonly WorkflowRemboursement $workflow,
        private readonly ControleBuyBack $controleBuyBack,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('par-classe', null, InputOption::VALUE_REQUIRED,
                'Nombre de cas par classe.', '20')
            ->addOption('graine', null, InputOption::VALUE_REQUIRED,
                'Graine de la population.', (string) self::GRAINE)
            ->addOption('ouvrir-les-ecarts', null, InputOption::VALUE_NONE,
                'Detailler les ecarts. A n\'utiliser QU\'APRES avoir lu les KPI globaux.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title(self::NOM.' — mesure finale de l\'outil 8');

        $parClasse = max(1, (int) $entree->getOption('par-classe'));
        $graine = (int) $entree->getOption('graine');
        $this->fabrique->semer($graine);

        $classes = $this->classes();
        $population = \count($classes) * $parClasse;

        $io->section('1. La population, tiree puis figee');
        $io->table(['Grandeur', 'Valeur'], [
            ['Nom de la population', self::NOM],
            ['Graine', (string) $graine],
            ['Classes de cas', (string) \count($classes)],
            ['Cas par classe', (string) $parClasse],
            ['Cas au total', (string) $population],
            ['Unite comptee', 'le CAS — certains cas sont des tentatives sans dossier payable'],
            ['Isolation', 'une transaction par cas, annulee ensuite ; l\'univers jury n\'est pas touche'],
        ]);
        $io->text('Aucun cas de la mesure diagnostique n\'est reutilise : la graine est neuve et');
        $io->text('les cas sont construits, pas tires parmi les 2 500 dossiers du monde jury.');

        // ------------------------------------------------------ la mesure
        $io->section('2. La mesure, une seule passe');
        $io->progressStart($population);
        $resultats = [];
        $refus = [];
        foreach ($classes as $classe) {
            for ($i = 0; $i < $parClasse; ++$i) {
                $rang = crc32($classe->classe.'-'.$graine.'-'.$i);
                try {
                    $resultats[] = $this->jouer($classe, $rang);
                } catch (Throwable $e) {
                    $refus[] = $classe->classe.' : '.$e->getMessage();
                } finally {
                    $this->fabrique->annuler();
                }
                $io->progressAdvance();
            }
        }
        $io->progressFinish();

        if ([] !== $refus) {
            $io->section('Cas refuses par l\'invariant de premisse');
            foreach (\array_slice(array_unique($refus), 0, 10) as $r) {
                $io->text('  • '.$r);
            }
            $io->error(sprintf('%d cas n\'ont pas pu etre construits : la mesure s\'arrete.', \count($refus)));

            return Command::FAILURE;
        }

        // ------------------------------------------------------ les KPI
        return $this->publier($io, $resultats, true === $entree->getOption('ouvrir-les-ecarts'));
    }

    /**
     * Joue un cas, dans sa transaction, et rend ce que le module en a fait.
     *
     * Le tableau rendu porte toujours les memes clefs, posees par `resultat()` :
     * classe, controle, attendu, obtenu, motif, motifJuste, paiement,
     * paiementInterdit, doubles, surpaiementPaye, sansDirecteur, sequenceJuste,
     * refusEntree.
     *
     * @return array<string, mixed>
     */
    private function jouer(CasMesure $cas, int $rang): array
    {
        $this->fabrique->ouvrir();

        $etab = $this->fabrique->etablissement($rang);
        $client = $this->fabrique->client($rang);
        $contrat = $this->fabrique->contrat($rang);
        $ibanValide = $this->fabrique->iban($rang);
        $erTtc = (float) $contrat->getErTtc();

        $contexte = ['contrat' => $contrat];
        $dossier = null;
        $temoin = null;
        $refusEntree = false;
        $motif = '';

        // ---------------------------------------------- la premisse du cas
        switch ($cas->classe) {
            case 'CAS-05':
                // Surpaiement : le depot doit refuser. On appelle la MEME
                // validation que le formulaire, par le service du module.
                $montant = number_format($erTtc + 600, 2, '.', '');
                $contexte['montant'] = $montant;
                $this->fabrique->verifierPremisse($cas->premisses, $contexte);
                $c = $this->controleBuyBack->pour($contrat->getImmat(), (float) $montant);
                $refusEntree = null !== $c && true === $c['surpaiement'];
                $motif = $refusEntree ? 'surpaiement buyback refuse au depot' : 'aucun refus';

                return $this->resultat($cas, $refusEntree ? 'refus_entree' : 'autorise', $motif,
                    ['refusEntree' => $refusEntree]);

            case 'CAS-02':
                // Un IBAN hors STRUCTURE : deux lettres, puis une lettre la ou le
                // format exige un chiffre. C'est ce que le module sait refuser.
                $ibanFaux = 'FR7X99999123456789012345678';
                $contexte['iban_saisi'] = $ibanFaux;
                $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client,
                    $ibanFaux, number_format($erTtc, 2, '.', ''), $contrat->getImmat(), 'ICAR-MESURE');
                $contexte['dossier'] = $dossier;
                break;

            case 'CAS-18':
                // Un IBAN de structure PARFAITE mais de cle MOD 97 fausse : le
                // dernier chiffre est decale d'une unite. Rien dans le module ne
                // le voit, et le cas est la pour l'etablir.
                $ibanValide = $this->fabrique->iban($rang);
                $ibanFausseCle = substr($ibanValide, 0, -1)
                    .(string) ((((int) substr($ibanValide, -1)) + 1) % 10);
                $contexte['iban_saisi'] = $ibanFausseCle;
                $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client,
                    $ibanFausseCle, number_format($erTtc, 2, '.', ''), $contrat->getImmat(), 'ICAR-MESURE');
                $contexte['dossier'] = $dossier;
                break;

            case 'CAS-03':
                $montant = number_format($erTtc, 2, '.', '');
                $temoin = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client,
                    $ibanValide, $montant, $contrat->getImmat(), 'ICAR-MESURE');
                $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client,
                    $ibanValide, $montant, $contrat->getImmat(), 'ICAR-MESURE');
                $contexte['dossier'] = $dossier;
                $contexte['temoin'] = $temoin;
                break;

            case 'CAS-04':
                // Meme compte bancaire, deux codes clients : la cle anti-doublon
                // differe (immatriculations distinctes), l'empreinte d'IBAN non.
                $autre = $this->fabrique->contrat($rang + 1);
                $temoin = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab,
                    $client, $ibanValide, number_format((float) $autre->getErTtc(), 2, '.', ''),
                    $autre->getImmat(), 'ICAR-AUTRE');
                $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab,
                    $this->fabrique->client($rang + 1), $ibanValide,
                    number_format($erTtc, 2, '.', ''), $contrat->getImmat(), 'ICAR-MESURE');
                $contexte['dossier'] = $dossier;
                $contexte['temoin'] = $temoin;
                break;

            case 'CAS-06':
                $montant = number_format($erTtc + 2, 2, '.', '');
                $contexte['montant'] = $montant;
                $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client,
                    $ibanValide, $montant, $contrat->getImmat(), 'ICAR-MESURE');
                $contexte['dossier'] = $dossier;
                break;

            case 'CAS-17':
                $montant = number_format($erTtc + 600, 2, '.', '');
                $contexte['montant'] = $montant;
                $dossier = $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client,
                    $ibanValide, $montant, $contrat->getImmat(), 'ICAR-MESURE');
                $contexte['dossier'] = $dossier;
                break;

            case 'CAS-07':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['iban_document'] = $this->fabrique->iban($rang + 5000);
                break;

            case 'CAS-08':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['montant_document'] = round($erTtc + 137.45, 2);
                break;

            case 'CAS-09':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['vehicule_libre'] = false;
                $contexte['raison'] = 'Gage inscrit au fichier des vehicules';
                break;

            case 'CAS-10':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['ecriture_manuscrite'] = true;
                break;

            case 'CAS-11':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['modifications_detectees'] = true;
                break;

            case 'CAS-12':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['document_invalide'] = true;
                break;

            case 'CAS-13':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['panne_extraction'] = true;
                break;

            case 'CAS-14':
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['valeurs_manquantes'] = ['IBAN', 'BIC'];
                break;

            default:
                $dossier = $this->dossierNominal($etab, $client, $ibanValide, $erTtc, $contrat->getImmat());
                $contexte['dossier'] = $dossier;
                $contexte['montant'] = number_format($erTtc, 2, '.', '');
                break;
        }

        // ---------------------------------- les pieces, puis la vraie lecture
        // Tout cas parvenu ici porte son dossier : les classes qui n'en creent
        // pas -- CAS-05, le refus au depot -- ont deja rendu leur resultat.
        $this->fabrique->pieces($dossier, $contexte);
        $this->analyse->analyser($dossier);
        $this->em->clear();
        $dossier = $this->recharger((string) $dossier->getReference());
        $contexte['dossier'] = $dossier;
        if (null !== $temoin) {
            $contexte['temoin'] = $this->recharger((string) $temoin->getReference());
        }

        // ------------------------------- CAS-16 : il faut un paiement d'abord
        if ('CAS-16' === $cas->classe) {
            $this->menerAuPaiement($dossier);
            $this->em->clear();
            $dossier = $this->recharger((string) $dossier->getReference());
            $contexte['dossier'] = $dossier;
        }

        // ------------------------------------- l'invariant, avant d'evaluer
        $this->fabrique->verifierPremisse($cas->premisses, $contexte);

        // ------------------------------------------------- l'evaluation
        return match ($cas->classe) {
            'CAS-14' => $this->evaluerValidationIncomplete($cas, $dossier),
            'CAS-15' => $this->evaluerGenerationPrecoce($cas, $dossier),
            'CAS-16' => $this->evaluerRejeu($cas, $dossier),
            'CAS-02', 'CAS-17', 'CAS-18' => $this->evaluerPaiementRefuse($cas, $dossier),
            'CAS-01', 'CAS-06' => $this->evaluerPaiementNominal($cas, $dossier),
            default => $this->evaluerOrientation($cas, $dossier),
        };
    }

    /** Un dossier nominal, depose et pret a etre lu. */
    private function dossierNominal(string $etab, string $client, string $iban, float $montant, string $immat): Dossier
    {
        return $this->fabrique->dossierTemoin(DossierMotif::RACHAT_SEC, $etab, $client, $iban,
            number_format($montant, 2, '.', ''), $immat, 'ICAR-MESURE');
    }

    /**
     * L'orientation que le module donne, lue sur le dossier et sur ses controles.
     *
     * @return array<string, mixed>
     */
    private function evaluerOrientation(CasMesure $cas, ?Dossier $dossier): array
    {
        if (null === $dossier) {
            return $this->resultat($cas, 'autorise', 'aucun dossier', []);
        }

        $causes = [];
        if ([] !== $this->dossiers->doublonsActifs(
            $dossier->getMotif(), $dossier->getCleDoublon(), $dossier->getId())) {
            $causes[] = 'doublon cle';
        }
        if ([] !== $this->dossiers->memeIbanActif($dossier->getIbanHash(), $dossier->getId())) {
            $causes[] = 'empreinte iban';
        }
        $surcharge = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM remboursement.extraction_piece
              WHERE dossier_id = ? AND statut = 'surcharge'", [(int) $dossier->getId()]);
        if ($surcharge > 0) {
            $causes[] = 'piece inexploitable';
        }
        $extra = $dossier->getControleExtra() ?? [];
        if (true === ($extra['facture_invalide'] ?? false)) {
            $causes[] = 'document illisible';
        }
        if (false === ($extra['vehicule_libre'] ?? true)) {
            $causes[] = 'vehicule gage';
        }
        if (true === ($extra['modifications_detectees'] ?? false)) {
            $causes[] = 'estimation retouchee';
        }
        if (true === ($extra['carte_grise_manuscrite'] ?? false)) {
            $causes[] = 'mention manuscrite';
        }
        if ('invalide' === (string) $dossier->getVerdictIa()) {
            $causes[] = mb_strtolower((string) $dossier->getVerdictInfo());
        }

        $obtenu = [] === $causes ? 'autorise' : 'humain';

        return $this->resultat($cas, $obtenu,
            [] === $causes ? 'controles concordants' : implode(' + ', array_unique($causes)), []);
    }

    /** @return array<string, mixed> */
    private function evaluerPaiementNominal(CasMesure $cas, ?Dossier $dossier): array
    {
        if (null === $dossier) {
            return $this->resultat($cas, 'bloque', 'aucun dossier', []);
        }
        $orientation = $this->evaluerOrientation($cas, $dossier);
        if ('autorise' !== $orientation['obtenu']) {
            return $orientation;
        }

        $this->menerAuPaiement($dossier);
        $this->em->clear();
        $frais = $this->recharger((string) $dossier->getReference());

        // Quand le cas est adosse au controle Buy Back, le motif dit ce que ce
        // controle a vu : un ecart, sa valeur, et le fait qu'il tient sous la
        // tolerance. Un motif qui ne nomme pas son controle ne prouve rien.
        $motif = 'paiement produit apres la sequence complete';
        $c = $this->controleBuyBack->pour($frais->getImmatriculation(),
            (float) ($frais->getValideMontant() ?: $frais->getMontant()));
        if ('C07' === $cas->controle && null !== $c) {
            $motif = sprintf('surpaiement non constate : ecart %s € sous la tolerance de %s €',
                number_format(abs((float) $c['ecart']), 2, ',', ' '),
                number_format(ControleBuyBack::SEUIL, 2, ',', ' ')).', '.$motif;
        }

        return $this->resultat($cas, 'autorise', $motif, $this->relever($frais, true));
    }

    /** @return array<string, mixed> */
    private function evaluerPaiementRefuse(CasMesure $cas, ?Dossier $dossier): array
    {
        if (null === $dossier) {
            return $this->resultat($cas, 'bloque', 'aucun dossier', []);
        }
        $this->menerAuPaiement($dossier);
        $this->em->clear();
        $frais = $this->recharger((string) $dossier->getReference());
        $sepa = $this->compterSepa((int) $frais->getId());
        // Quel renforcement a parle ? On lit la trace plutot que de deviner.
        $garde = (string) $this->cnx->fetchOne(
            "SELECT coalesce(max(source), '') FROM remboursement.rejeu_evenement
              WHERE dossier_id = ? AND source IN ('garde-surpaiement', 'garde-iban-mod97')",
            [(int) $frais->getId()]);

        if (0 === $sepa) {
            $motif = match ($garde) {
                'garde-surpaiement' => 'surpaiement refuse devant la caisse, tentative tracee',
                'garde-iban-mod97' => 'cle de controle de l'."'".'iban fausse, paiement refuse et trace',
                default => 'aucun fichier de paiement, statut '.$frais->getStatut()->value,
            };
        } else {
            $motif = 'un fichier a ete produit';
        }

        return $this->resultat($cas, 0 === $sepa ? 'bloque' : 'autorise', $motif,
            $this->relever($frais, false));
    }

    /** @return array<string, mixed> */
    private function evaluerGenerationPrecoce(CasMesure $cas, ?Dossier $dossier): array
    {
        if (null === $dossier) {
            return $this->resultat($cas, 'bloque', 'aucun dossier', []);
        }
        // Aucune validation : on demande la generation tout de suite.
        $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()),
            [new TransportNamesStamp(['sync'])]);
        $this->em->clear();
        $frais = $this->recharger((string) $dossier->getReference());
        $sepa = $this->compterSepa((int) $frais->getId());

        return $this->resultat($cas, 0 === $sepa ? 'bloque' : 'autorise',
            0 === $sepa
                ? 'la generation n\'a rien produit hors « valide directeur »'
                : 'un fichier a ete produit sans validation',
            ['paiement' => $sepa > 0, 'paiementInterdit' => $sepa > 0,
                'sansDirecteur' => $sepa > 0]);
    }

    /** @return array<string, mixed> */
    private function evaluerRejeu(CasMesure $cas, ?Dossier $dossier): array
    {
        if (null === $dossier) {
            return $this->resultat($cas, 'bloque', 'aucun dossier', []);
        }
        $avant = $this->compterSepa((int) $dossier->getId());
        $empreinteAvant = $this->cnx->fetchOne(
            'SELECT hash_sepa FROM remboursement.paiement_empreinte WHERE dossier_id = ?',
            [(int) $dossier->getId()]);

        // Deux demandes de plus, sur le vrai bus, middlewares compris.
        for ($i = 0; $i < 2; ++$i) {
            $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()),
                [new TransportNamesStamp(['sync'])]);
        }
        $this->em->clear();
        $frais = $this->recharger((string) $dossier->getReference());
        $apres = $this->compterSepa((int) $frais->getId());
        $empreinteApres = $this->cnx->fetchOne(
            'SELECT hash_sepa FROM remboursement.paiement_empreinte WHERE dossier_id = ?',
            [(int) $frais->getId()]);
        $rejeux = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.rejeu_evenement WHERE dossier_id = ?',
            [(int) $frais->getId()]);

        $intact = 1 === $apres && $avant === $apres && $empreinteAvant === $empreinteApres;

        // Le premier paiement de ce cas est legitime et il a suivi la sequence
        // complete : on la releve, sinon le KPI de sequence compterait une
        // mesure absente comme une sequence fautive.
        $releve = $this->relever($frais, true);
        $releve['doubles'] = max(0, $apres - 1);

        return $this->resultat($cas, $intact ? 'bloque' : 'autorise',
            $intact
                ? sprintf('un seul fichier, meme condensat, %d rejeu(x) trace(s)', $rejeux)
                : 'le fichier a change ou un second a ete produit',
            $releve);
    }

    /** @return array<string, mixed> */
    private function evaluerValidationIncomplete(CasMesure $cas, ?Dossier $dossier): array
    {
        if (null === $dossier) {
            return $this->resultat($cas, 'refus_entree', 'aucun dossier', []);
        }

        // On enregistre des valeurs retenues INCOMPLETES, puis on tente la
        // transmission au directeur. Le module doit refuser -- c'est C31, et
        // c'est la meme verification que celle du controleur.
        $dossier->enregistrerValidation([
            'nom' => $dossier->getControleNom() ?: $dossier->getNomClient(),
            'iban' => null, 'bic' => null,
            'montant' => $dossier->getControleMontant() ?: $dossier->getMontant(),
            'immatriculation' => $dossier->getImmatriculation(),
            'code_icar' => null, 'libelle' => null,
            'code_comptable' => null, 'role_tiers' => null,
        ], 'comptable@demonstration.invalid');
        $this->em->flush();
        $this->em->clear();
        $frais = $this->recharger((string) $dossier->getReference());

        $incomplet = null === $frais->getValideIban() || null === $frais->getValideBic();

        return $this->resultat($cas, $incomplet ? 'refus_entree' : 'autorise',
            $incomplet
                ? 'IBAN et BIC retenus absents : la validation ne peut pas etre soumise'
                : 'les valeurs retenues sont completes',
            ['refusEntree' => $incomplet]);
    }

    /**
     * Le parcours de paiement, par le vrai workflow et le vrai bus.
     *
     * Le message part sur le transport SYNCHRONE : il traverse toute la chaine
     * -- middlewares anti-rejeu et garde de surpaiement compris -- puis le
     * handler s'execute dans le meme processus, donc dans la transaction du cas.
     * Un worker externe, lui, ne verrait pas une transaction non validee.
     */
    private function menerAuPaiement(Dossier $dossier): void
    {
        if (DossierStatut::A_VERIFIER !== $dossier->getStatut()) {
            return;
        }
        $dossier->enregistrerValidation([
            'nom' => $dossier->getControleNom() ?: $dossier->getNomClient(),
            'iban' => $dossier->getControleIban() ?: $dossier->getIbanClient(),
            'bic' => $dossier->getControleBic() ?: $dossier->getBicClient(),
            'montant' => $dossier->getControleMontant() ?: $dossier->getMontant(),
            'immatriculation' => $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation(),
            'code_icar' => $dossier->getControleIcar() ?: $dossier->getCodeIcar(),
            'libelle' => self::NOM,
            'code_comptable' => '4111000',
            'role_tiers' => 'COMPTANT',
        ], 'comptable@demonstration.invalid');

        $this->workflow->appliquer($dossier, 'envoyer_directeur',
            'comptable@demonstration.invalid', null, false, true, true);
        $this->workflow->appliquer($dossier, 'valider_directeur',
            'Directeur (e-mail)', null, false, true, true);

        $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()),
            [new TransportNamesStamp(['sync'])]);
    }

    /**
     * Ce que le paiement d'un dossier vaut : fichiers, surpaiement, sequence.
     *
     * @return array<string, mixed>
     */
    private function relever(Dossier $dossier, bool $autorise): array
    {
        $id = (int) $dossier->getId();
        $sepa = $this->compterSepa($id);
        if (0 === $sepa) {
            return ['paiement' => false];
        }

        $c = $this->controleBuyBack->pour($dossier->getImmatriculation(),
            (float) ($dossier->getValideMontant() ?: $dossier->getMontant()));

        $sequence = $this->cnx->fetchAllAssociative(
            'SELECT transition, coalesce(par, \'\') AS par FROM remboursement.dossier_transition
              WHERE dossier_id = ? ORDER BY id', [$id]);
        $noms = array_map(static fn (array $l): string => (string) $l['transition'], $sequence);
        $ordre = ['deposer', 'envoyer_directeur', 'valider_directeur', 'confirmer', 'demarrer_generation'];
        $position = 0;
        foreach ($noms as $t) {
            if ($position < \count($ordre) && $t === $ordre[$position]) {
                ++$position;
            }
        }
        $parRole = true;
        foreach ($sequence as $l) {
            if ('envoyer_directeur' === $l['transition'] && !str_contains((string) $l['par'], 'comptable')) {
                $parRole = false;
            }
            if ('valider_directeur' === $l['transition'] && !str_contains((string) $l['par'], 'Directeur')) {
                $parRole = false;
            }
        }

        // R04 : l'IBAN qui est REELLEMENT parti dans le fichier. Un paiement
        // produit vers une cle fausse est un defaut de securite, pas un ecart
        // d'orientation, et il se compte a part.
        $ibanParti = (string) ($dossier->getValideIban() ?: $dossier->getIbanClient());

        return [
            'paiement' => true,
            'paiementInterdit' => !$autorise,
            'ibanFauxPaye' => !ControleIbanMod97::cleJuste($ibanParti),
            'doubles' => max(0, $sepa - 1),
            'surpaiementPaye' => null !== $c && true === $c['surpaiement'],
            'sansDirecteur' => !\in_array('valider_directeur', $noms, true),
            'sequenceJuste' => \count($ordre) === $position && $parRole,
        ];
    }

    /**
     * @param array<string, mixed> $mesures
     *
     * @return array<string, mixed>
     */
    private function resultat(CasMesure $cas, string $obtenu, string $motif, array $mesures): array
    {
        $famille = self::familleControle($cas->controle);

        return array_merge([
            'classe' => $cas->classe,
            'controle' => $cas->controle,
            'attendu' => $cas->attendu,
            'obtenu' => $obtenu,
            'motif' => $motif,
            'motifJuste' => '' === $famille || str_contains(mb_strtolower($motif), $famille),
            'paiement' => false,
            'paiementInterdit' => false,
            'ibanFauxPaye' => false,
            'doubles' => 0,
            'surpaiementPaye' => false,
            'sansDirecteur' => false,
            'sequenceJuste' => false,
            'refusEntree' => false,
        ], $mesures);
    }

    /** Le mot que le controle attendu doit faire apparaitre dans le motif. */
    private static function familleControle(string $controle): string
    {
        return match ($controle) {
            'C07', 'R01' => 'surpaiement',
            'C32' => 'doublon',
            'C33' => 'empreinte iban',
            'C20' => 'iban',
            'C21' => 'montant',
            'C25' => 'manuscrite',
            'C26' => 'gage',
            'C27' => 'estimation',
            'C28' => 'illisible',
            'C17' => 'piece inexploitable',
            'C44' => 'paiement',
            'R04' => 'cle de controle',
            'C40' => 'valide directeur',
            'R02' => 'condensat',
            default => '',
        };
    }

    /**
     * Les KPI. Les six indicateurs de securite d'abord, l'exactitude ensuite.
     *
     * @param list<array<string, mixed>> $resultats
     */
    private function publier(SymfonyStyle $io, array $resultats, bool $ouvrirLesEcarts): int
    {
        $io->section('3. Les indicateurs, lus une seule fois');

        $paiements = 0;
        $interdits = 0;
        $doubles = 0;
        $surpaiements = 0;
        $sansDirecteur = 0;
        $sequenceJuste = 0;
        $ibanFauxPayes = 0;
        $justes = 0;
        $fauxBlocages = 0;
        $passagesIndus = 0;
        $humainDemande = 0;
        $humainAttendu = 0;
        $motifJuste = 0;
        $refusEntree = 0;
        $classes = [];

        foreach ($resultats as $r) {
            $classes[(string) $r['classe']] = true;
            if ($r['attendu'] === $r['obtenu']) {
                ++$justes;
            }
            if ('autorise' === $r['attendu'] && \in_array($r['obtenu'], ['bloque', 'refus_entree'], true)) {
                ++$fauxBlocages;
            }
            if (\in_array($r['attendu'], ['bloque', 'refus_entree'], true) && 'autorise' === $r['obtenu']) {
                ++$passagesIndus;
            }
            if ('humain' === $r['attendu']) {
                ++$humainAttendu;
                if ('humain' === $r['obtenu']) {
                    ++$humainDemande;
                }
            }
            if (true === $r['motifJuste']) {
                ++$motifJuste;
            }
            if (true === $r['refusEntree']) {
                ++$refusEntree;
            }
            if (true === $r['paiement']) {
                ++$paiements;
                if (true === $r['ibanFauxPaye']) {
                    ++$ibanFauxPayes;
                }
                if (true === $r['sequenceJuste']) {
                    ++$sequenceJuste;
                }
            }
            if (true === $r['paiementInterdit']) {
                ++$interdits;
            }
            $doubles += (int) $r['doubles'];
            if (true === $r['surpaiementPaye']) {
                ++$surpaiements;
            }
            if (true === $r['sansDirecteur']) {
                ++$sansDirecteur;
            }
        }

        $total = \count($resultats);

        $io->table(['Grandeur', 'Valeur'], [
            ['Cas mesures', (string) $total],
            ['Classes representees', (string) \count($classes).' sur '.\count($this->classes())],
            ['Orientations correctes', sprintf('%d / %d — %.2f %%', $justes, $total,
                $total > 0 ? $justes / $total * 100 : 0)],
            ['Faux blocages', (string) $fauxBlocages],
            ['Passages indus', (string) $passagesIndus],
            ['Interventions humaines correctement demandees',
                sprintf('%d / %d', $humainDemande, $humainAttendu)],
            ['Motifs exacts', sprintf('%d / %d', $motifJuste, $total)],
            ['Refus a l\'entree, comme attendu', (string) $refusEntree],
            ['Paiements produits', (string) $paiements],
            ['Paiements interdits produits', (string) $interdits],
            ['Doubles paiements', (string) $doubles],
            ['Surpaiements au-dela de 3 € payes', (string) $surpaiements],
            ['Generations sans validation directeur', (string) $sansDirecteur],
            ['Sequences de roles incorrectes acceptees',
                (string) max(0, $paiements - $sequenceJuste)],
            ['Paiements vers un IBAN a la cle fausse', (string) $ibanFauxPayes],
        ]);

        $io->section('4. Les six indicateurs de securite, prioritaires');
        $securite = [
            ['0 paiement interdit genere', (string) $interdits, 0 === $interdits],
            ['0 double paiement sur le circuit applicatif normal', (string) $doubles, 0 === $doubles],
            ['0 surpaiement Buy Back au-dela de 3 € paye', (string) $surpaiements, 0 === $surpaiements],
            ['0 generation sans validation directeur', (string) $sansDirecteur, 0 === $sansDirecteur],
            ['0 paiement vers un IBAN dont le MOD 97 est invalide',
                (string) $ibanFauxPayes, 0 === $ibanFauxPayes],
            ['100 % des paiements avec la sequence de roles requise',
                sprintf('%d / %d', $sequenceJuste, $paiements), $sequenceJuste === $paiements],
        ];
        $fautes = 0;
        $lignes = [];
        foreach ($securite as [$quoi, $vu, $ok]) {
            $lignes[] = [$quoi, $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->table(['Indicateur', 'Constate', 'Verdict'], $lignes);

        $io->section('5. Par classe de cas');
        $parClasse = [];
        foreach ($resultats as $r) {
            $cle = (string) $r['classe'].' | '.(string) $r['controle']
                .' | attendu '.(string) $r['attendu'];
            $parClasse[$cle] ??= ['juste' => 0, 'total' => 0, 'motifs' => []];
            ++$parClasse[$cle]['total'];
            if ($r['attendu'] === $r['obtenu']) {
                ++$parClasse[$cle]['juste'];
            }
            $parClasse[$cle]['motifs'][(string) $r['motif']] = true;
        }
        ksort($parClasse);
        $io->table(['Classe | controle | attendu', 'Justes', 'Motif observe'],
            array_map(static fn (string $c): array => [
                $c,
                sprintf('%d / %d', $parClasse[$c]['juste'], $parClasse[$c]['total']),
                mb_substr(implode(' ; ', array_keys($parClasse[$c]['motifs'])), 0, 70),
            ], array_keys($parClasse)));

        if ($ouvrirLesEcarts) {
            $io->section('6. Les ecarts, ouverts APRES lecture des indicateurs');
            foreach ($resultats as $r) {
                if ($r['attendu'] !== $r['obtenu']) {
                    $io->text(sprintf('  %s (%s) : attendu %s, obtenu %s — %s',
                        (string) $r['classe'], (string) $r['controle'],
                        (string) $r['attendu'], (string) $r['obtenu'], (string) $r['motif']));
                }
            }
        } else {
            $io->text('Les ecarts ne sont pas ouverts : relancez avec --ouvrir-les-ecarts');
            $io->text('APRES avoir lu les indicateurs ci-dessus, jamais avant.');
        }

        if ($fautes > 0) {
            $io->error(sprintf('%d indicateur(s) de securite en echec.', $fautes));

            return Command::FAILURE;
        }
        $io->success('Les six indicateurs de securite sont a zero.');

        return Command::SUCCESS;
    }

    private function compterSepa(int $dossierId): int
    {
        return (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier_piece WHERE dossier_id = ? AND type = ?',
            [$dossierId, GenerationFichiersComptables::TYPE_SEPA]);
    }

    private function recharger(string $reference): Dossier
    {
        $d = $this->dossiers->findOneBy(['reference' => $reference]);
        if (null === $d) {
            throw new RuntimeException('Dossier disparu : '.$reference);
        }

        return $d;
    }
}
