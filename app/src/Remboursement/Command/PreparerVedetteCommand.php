<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\ControleBuyBack;
use App\Remboursement\Service\FabricantPieces;
use App\Remboursement\Service\Ia\AnalyseDossierService;
use App\Remboursement\Service\Ia\ProviderOcrLocal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepare un dossier de demonstration : ses pieces synthetiques, puis la
 * lecture locale par le VRAI service d'analyse du module.
 *
 * Cette commande ne decide rien et ne franchit aucune transition metier au-dela
 * de celles que le module declenche lui-meme. Elle fait deux choses :
 *
 *   1. elle attache au dossier les pieces synthetiques, en `bytea`, par le vrai
 *      modele DossierPiece -- meme table, meme empreinte, meme ecran ;
 *   2. elle appelle `AnalyseDossierService::analyser()`, c'est-a-dire le vrai
 *      orchestrateur d'extraction du module, qui passe chaque piece au
 *      fournisseur, journalise une ExtractionPiece, agrege les colonnes
 *      `controle_*`, calcule le verdict indicatif et franchit lui-meme la
 *      transition vers « a verifier ».
 *
 * Rien n'est ecrit directement en base pour accelerer le scenario.
 */
#[AsCommand(
    name: 'app:remboursement:preparer-vedette',
    description: 'Attache les pieces synthetiques d\'un dossier et lance la lecture locale.',
)]
final class PreparerVedetteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DossierRepository $dossiers,
        private readonly FabricantPieces $fabricant,
        private readonly AnalyseDossierService $analyse,
        private readonly ControleBuyBack $controleBuyBack,
        private readonly \App\Remboursement\Demo\SourcePiece $source,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reference', null, InputOption::VALUE_REQUIRED,
                'La reference du dossier a preparer.')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED,
                'A defaut, choisir le plus gros dossier de ce scenario.', 'SC-08-06')
            ->addOption('refaire', null, InputOption::VALUE_NONE,
                "Refabriquer les pieces d'un dossier deja analyse (le journal d'extraction ne sera pas rejoue).");
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Preparation d\'un dossier de demonstration');

        $dossier = $this->choisir($entree, $io);
        if (null === $dossier) {
            return Command::FAILURE;
        }

        // Un dossier qui a quitte « depose » a deja ete lu par le module : lui
        // retirer ses pieces effacerait un journal d'extraction que le service ne
        // rejouera pas depuis ce statut. On refuse plutot que d'abimer la piste.
        if (!\in_array($dossier->getStatut(), [DossierStatut::BROUILLON, DossierStatut::DEPOSE], true)
            && true !== $entree->getOption('refaire')) {
            $io->error(sprintf(
                'Le dossier %s est en « %s » : il a deja ete lu. Choisissez un dossier encore'
                ." au depot, ou passez --refaire en assumant la perte du journal d'extraction.",
                $dossier->getReference(), $dossier->getStatut()->value));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Reference' => $dossier->getReference()],
            ['Motif' => $dossier->getMotif()->value],
            ['Statut de depart' => $dossier->getStatut()->value],
            ['Client' => $dossier->getNomClient()],
            ['Montant demande' => number_format((float) $dossier->getMontant(), 2, ',', ' ').' €'],
            ['Immatriculation' => (string) $dossier->getImmatriculation()],
            ['Etablissement' => (string) $dossier->getEtablissementCode()],
        );

        // ---- Le controle Buy Back, tel que le module le calcule. On le LIT,
        // on ne le refait pas : c'est le meme service que la validation du
        // depot et l'endpoint temps reel appellent.
        $buyBack = null;
        $c = $this->controleBuyBack->pour($dossier->getImmatriculation(), (float) $dossier->getMontant());
        if (null !== $c) {
            $buyBack = $c['vehicule'];
            $io->section('Controle Buy Back, par le vrai ControleBuyBack');
            $io->table(['Element', 'Valeur'], [
                ['Engagement de reprise TTC', number_format($c['erTtc'], 2, ',', ' ').' €'],
                ['Montant demande', number_format((float) $c['montant'], 2, ',', ' ').' €'],
                ['Ecart', number_format((float) $c['ecart'], 2, ',', ' ').' €'],
                ['Tolerance du module', number_format(ControleBuyBack::SEUIL, 2, ',', ' ').' €'],
                ['Surpaiement retenu', $c['surpaiement'] ? 'OUI — depot bloque' : 'non — aucun blocage'],
            ]);
        }

        // ------------------------------------------------------- les pieces
        $io->section('Pieces synthetiques attachees au dossier');
        $this->supprimerPieces($dossier);

        $types = $this->fabricant->typesPour($dossier->getMotif(), null !== $buyBack);
        $lignes = [];
        foreach ($types as $type) {
            // Le contexte vient du MONDE : divergence d'IBAN, montant de
            // facture, mention manuscrite, vehicule gage, document illisible,
            // panne du fournisseur. Rien n'est code en dur ici.
            $doc = $this->fabricant->pour($dossier, $type, $buyBack,
                $this->source->contexte($dossier, $type));
            $contenu = $doc['html'];
            $piece = new DossierPiece(
                $dossier,
                $type,
                $doc['nom'],
                $contenu,
                $doc['mime'],
                \strlen($contenu),
                hash('sha256', $contenu),
                'preparation@demonstration.invalid',
            );
            $this->em->persist($piece);

            $lignes[] = [
                $type,
                $this->fabricant->libelle($type),
                number_format(\strlen($contenu) / 1024, 1, ',', ' ').' Ko',
                (string) \count($doc['champs']).' champs',
                substr((string) hash('sha256', $contenu), 0, 12).'…',
            ];
        }
        $this->em->flush();
        $io->table(['Type', 'Document', 'Taille', 'Source canonique', 'Empreinte'], $lignes);
        $io->text('Invariant « visible = structuré » tenu sur chaque document : la fabrication');
        $io->text('echoue si une valeur lue ne figure pas dans le document visible.');

        // ---------------------------------- la lecture, par le vrai service
        $io->section('Lecture locale, par le vrai AnalyseDossierService');
        $avant = $dossier->getStatut()->value;
        $this->analyse->analyser($dossier);
        $this->em->refresh($dossier);

        $io->text(sprintf('Statut : %s → %s (transition franchie par le service lui-meme).',
            $avant, $dossier->getStatut()->value));

        $extractions = $this->em->getConnection()->fetchAllAssociative(
            'SELECT type_piece, statut, provider, latence_ms,
                    left(champs_extraits::text, 74) AS champs
               FROM remboursement.extraction_piece
              WHERE dossier_id = ? ORDER BY id', [$dossier->getId()]);
        $io->table(['Piece', 'Extraction', 'Lecture', 'ms', 'Champs lus'],
            array_map(static fn (array $l): array => [
                (string) $l['type_piece'], (string) $l['statut'],
                (string) $l['provider'],
                (string) $l['latence_ms'], (string) $l['champs'],
            ], $extractions));
        $io->text(ProviderOcrLocal::LIBELLE);

        // ------------------------------------------------------ le triptyque
        $io->section('Le triptyque, tel que le module l\'a rempli');
        $io->table(['Champ', 'Saisie secretaire', 'Lecture de la piece', 'Valeur retenue'], [
            ['Client', $dossier->getNomClient(), (string) $dossier->getControleNom(),
                (string) $dossier->getValideNom() ?: '— non encore décidée'],
            ['IBAN', $this->masquer($dossier->getIbanClient()),
                $this->masquer($dossier->getControleIban()),
                null === $dossier->getValideIban() ? '— non encore décidée' : $this->masquer($dossier->getValideIban())],
            ['Montant', number_format((float) $dossier->getMontant(), 2, ',', ' '),
                null === $dossier->getControleMontant() ? '—' : number_format((float) $dossier->getControleMontant(), 2, ',', ' '),
                null === $dossier->getValideMontant() ? '— non encore décidée' : number_format((float) $dossier->getValideMontant(), 2, ',', ' ')],
            ['Immatriculation', (string) $dossier->getImmatriculation(),
                (string) $dossier->getControleImmatriculation(),
                (string) $dossier->getValideImmatriculation() ?: '— non encore décidée'],
            ['Code ICAR', (string) $dossier->getCodeIcar(), (string) $dossier->getControleIcar(),
                (string) $dossier->getValideIcar() ?: '— non encore décidée'],
        ]);
        $io->text(sprintf('Verdict indicatif du module : %s — %s',
            (string) $dossier->getVerdictIa() ?: 'aucun',
            (string) $dossier->getVerdictInfo() ?: 'aucune divergence nommee'));

        $io->success(sprintf(
            'Dossier %s pret. Il attend le comptable dans la file « a verifier ».',
            $dossier->getReference()));

        return Command::SUCCESS;
    }

    /** Choisit le dossier : par reference, ou le plus gros du scenario demande. */
    private function choisir(InputInterface $entree, SymfonyStyle $io): ?Dossier
    {
        $reference = $entree->getOption('reference');
        if (\is_string($reference) && '' !== $reference) {
            $dossier = $this->dossiers->findOneBy(['reference' => $reference]);
            if (null === $dossier) {
                $io->error('Dossier introuvable : '.$reference);

                return null;
            }

            return $dossier;
        }

        $scenario = (string) $entree->getOption('scenario');
        $ref = $this->em->getConnection()->fetchOne(
            'SELECT v.reference FROM remboursement_verite.dossier v
               JOIN remboursement.dossier d ON d.reference = v.reference
              WHERE v.code_scenario = ? ORDER BY d.montant DESC LIMIT 1', [$scenario]);
        if (!\is_string($ref)) {
            $io->error('Aucun dossier pour le scenario '.$scenario);

            return null;
        }
        $io->text(sprintf('Scenario %s : dossier %s retenu (le plus gros montant).', $scenario, $ref));

        return $this->dossiers->findOneBy(['reference' => $ref]);
    }

    private function supprimerPieces(Dossier $dossier): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM remboursement.extraction_piece WHERE dossier_id = ?', [$dossier->getId()]);
        $this->em->getConnection()->executeStatement(
            'DELETE FROM remboursement.dossier_piece WHERE dossier_id = ?', [$dossier->getId()]);
    }

    private function masquer(?string $iban): string
    {
        if (null === $iban || '' === $iban) {
            return '—';
        }
        if (\strlen($iban) < 12) {
            return $iban;
        }

        return substr($iban, 0, 8).' … '.substr($iban, -4);
    }
}
