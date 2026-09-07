<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\GardeAntiRejeu;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\GenerationFichiersComptables;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Met l'anti-rejeu a l'epreuve : trois demandes de paiement, un seul fichier.
 *
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION : ni l'empreinte de paiement, ni le
 * middleware qui la lit n'existent dans le module historique.
 *
 * La commande ne simule pas les appels : elle DEPOSE trois fois le vrai message
 * `GenererFichiers` sur le vrai bus, exactement comme le fait le lien signe du
 * directeur, et laisse le vrai worker consommer la file entre chaque. Ce qui est
 * mesure, c'est ce que la base porte apres : nombre de fichiers, MsgId,
 * condensats, evenements de rejeu.
 */
#[AsCommand(
    name: 'app:demo:anti-rejeu',
    description: 'RENFORCEMENT : trois demandes de paiement, un seul fichier. Preuve par la base.',
)]
final class AntiRejeuCommand extends Command
{
    public function __construct(
        private readonly GardeAntiRejeu $garde,
        private readonly DossierRepository $dossiers,
        private readonly \App\Remboursement\Repository\DossierPieceRepository $pieces,
        private readonly Connection $cnx,
        private readonly MessageBusInterface $bus,
        private readonly string $racineProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reference', null, InputOption::VALUE_REQUIRED,
                'Le dossier a eprouver. Il doit deja porter un paiement genere.')
            ->addOption('installer', null, InputOption::VALUE_NONE,
                'Creer les tables du renforcement si elles manquent.')
            ->addOption('appels', null, InputOption::VALUE_REQUIRED,
                'Nombre de demandes a deposer sur le bus.', '3');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Anti-rejeu du paiement — renforcement de la copie');
        $io->text('Ce mecanisme est un AJOUT de la copie de demonstration.');
        $io->text('Ce que le module historique fait deja : il refuse de generer si le dossier');
        $io->text('n\'est pas en « valide directeur ». Ce que le renforcement ajoute : une');
        $io->text('empreinte du paiement, un refus par la cle fonctionnelle, et une trace.');

        if (true === $entree->getOption('installer')) {
            $this->garde->installer();
            $io->text('Tables du renforcement en place.');
        }

        $reference = (string) $entree->getOption('reference');
        $dossier = $this->dossiers->findOneBy(['reference' => $reference]);
        if (null === $dossier) {
            $io->error('Dossier introuvable : '.$reference);

            return Command::FAILURE;
        }
        $id = (int) $dossier->getId();

        $empreinte = $this->garde->empreinte($id);
        if (null === $empreinte) {
            $io->error(sprintf(
                'Le dossier %s ne porte pas encore d\'empreinte de paiement : faites-le'
                .' d\'abord traverser le parcours (statut actuel : %s).',
                $reference, $dossier->getStatut()->value));

            return Command::FAILURE;
        }

        $io->section('1. L\'etat AVANT les demandes');
        $avant = $this->etat($id);
        $io->definitionList(
            ['Dossier' => $reference.' — statut '.$dossier->getStatut()->value],
            ['Cle fonctionnelle' => substr((string) $empreinte['cle_fonctionnelle'], 0, 32).'…'],
            ['MsgId du fichier' => (string) $empreinte['msg_id']],
            ['Fichier SEPA' => (string) $empreinte['nom_sepa']],
            ['Condensat SEPA' => substr((string) $empreinte['hash_sepa'], 0, 32).'…'],
            ['Fichiers en base' => sprintf('%d SEPA, %d OD', $avant['sepa'], $avant['od'])],
            ['Rejeux comptes' => (string) $empreinte['rejeux']],
            ['Evenements de rejeu' => (string) $avant['evenements']],
        );

        // ------------------------------------------------- les demandes
        $appels = max(1, (int) $entree->getOption('appels'));
        $io->section(sprintf('2. %d demandes de paiement deposees sur le vrai bus', $appels));
        for ($i = 1; $i <= $appels; ++$i) {
            $this->bus->dispatch(new GenererFichiers($id));
            $consomme = $this->consommer();
            $etat = $this->etat($id);
            $io->text(sprintf(
                'Demande %d : file consommee (code %d) — %d SEPA, %d OD, %d evenement(s) de rejeu.',
                $i, $consomme, $etat['sepa'], $etat['od'], $etat['evenements']));
        }

        // ------------------------------------------------- ce que la base dit
        $io->section('3. L\'etat APRES, lu en base');
        $apres = $this->etat($id);
        $empreinteApres = $this->garde->empreinte($id);
        if (null === $empreinteApres) {
            $io->error('L\'empreinte du paiement a disparu : c\'est un echec, pas un rejeu.');

            return Command::FAILURE;
        }
        // La piece est lue PAR L'ENTITE : la colonne est un bytea, et son
        // contenu brut n'est pas le XML -- c'est getContenu() qui le rend.
        $sepa = null;
        foreach ($this->pieces->pourDossier($dossier) as $p) {
            if (GenerationFichiersComptables::TYPE_SEPA === $p->getType()) {
                $sepa = $p;
            }
        }

        $msgIdFichier = null === $sepa ? 'aucun' : GardeAntiRejeu::msgId($sepa->getContenu());
        $hashFichier = null === $sepa ? '' : hash('sha256', $sepa->getContenu());

        $controles = [
            ['Un seul fichier SEPA en base', sprintf('%d', $apres['sepa']), 1 === $apres['sepa']],
            ['Un seul fichier OD en base', sprintf('%d', $apres['od']), 1 === $apres['od']],
            ['MsgId inchange', $msgIdFichier, $msgIdFichier === (string) $empreinte['msg_id']],
            ['Nom de fichier inchange', null === $sepa ? '—' : $sepa->getNomFichier(),
                null !== $sepa && $sepa->getNomFichier() === (string) $empreinte['nom_sepa']],
            ['Condensat du fichier inchange', substr($hashFichier, 0, 16).'…',
                $hashFichier === (string) $empreinte['hash_sepa']],
            ['Reference de paiement inchangee', (string) $empreinteApres['reference'],
                (string) $empreinteApres['reference'] === (string) $empreinte['reference']],
            ['Rejeux tracés', (string) $empreinteApres['rejeux'],
                (int) $empreinteApres['rejeux'] === (int) $empreinte['rejeux'] + $appels],
            ['Evenements de rejeu ecrits', (string) $apres['evenements'],
                $avant['evenements'] + $appels === $apres['evenements']],
            ['Aucun second paiement', sprintf('%d paiement(s) enregistre(s)', $apres['empreintes']),
                1 === $apres['empreintes']],
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

        $io->section('4. Ce que les evenements disent, mot pour mot');
        foreach ($this->cnx->fetchAllAssociative(
            'SELECT source, decision, message, to_char(le, \'HH24:MI:SS\') AS heure
               FROM remboursement.rejeu_evenement WHERE dossier_id = ? ORDER BY id', [$id]) as $e) {
            $io->text(sprintf('  %s  [%s / %s]  %s',
                (string) $e['heure'], (string) $e['source'], (string) $e['decision'],
                (string) $e['message']));
        }

        $io->newLine();
        $io->text('KPI cible : 0 double paiement. Constate : '
            .(1 === $apres['sepa'] && 1 === $apres['empreintes'] ? '0 double paiement.' : 'ECHEC.'));

        if ($fautes > 0) {
            $io->error(sprintf('%d controle(s) en echec.', $fautes));

            return Command::FAILURE;
        }
        $io->success(sprintf(
            '%d demandes, un seul paiement, meme MsgId, meme fichier, meme condensat.', $appels));

        return Command::SUCCESS;
    }

    /** @return array{sepa: int, od: int, evenements: int, empreintes: int} */
    private function etat(int $dossierId): array
    {
        return [
            'sepa' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM remboursement.dossier_piece WHERE dossier_id = ? AND type = ?',
                [$dossierId, GenerationFichiersComptables::TYPE_SEPA]),
            'od' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM remboursement.dossier_piece WHERE dossier_id = ? AND type = ?',
                [$dossierId, GenerationFichiersComptables::TYPE_OD]),
            'evenements' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM remboursement.rejeu_evenement WHERE dossier_id = ?', [$dossierId]),
            'empreintes' => (int) $this->cnx->fetchOne(
                'SELECT count(*) FROM remboursement.paiement_empreinte WHERE dossier_id = ?', [$dossierId]),
        ];
    }

    /** Le vrai worker, sur la vraie file. */
    private function consommer(): int
    {
        $worker = new \Symfony\Component\Process\Process(
            ['php', 'bin/console', 'messenger:consume', 'remboursement',
                '--limit=1', '--time-limit=20', '-q'],
            $this->racineProjet);
        $worker->setTimeout(60);
        $worker->run();

        return (int) $worker->getExitCode();
    }
}
