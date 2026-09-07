<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * OUTIL DE DEMONSTRATION -- remet un dossier a son point de depart jouable.
 *
 * Ce n'est PAS un geste metier, et ce n'en sera jamais un. Aucun utilisateur,
 * aucun ecran, aucune route ne l'expose : la seule facon de l'appeler est la
 * console, sur cette copie de demonstration. Un remboursement paye ne se
 * « depaye » pas, et le module reel n'a evidemment aucune transition pour cela.
 *
 * Ce qu'il fait, et pourquoi il ecrit directement en base. Rejouer une
 * demonstration suppose de REMONTER le temps, ce qu'aucun workflow ne sait
 * faire. La commande efface donc ce que le parcours a ecrit -- pieces,
 * extractions, journal, valeurs lues, valeurs retenues, dates de decision,
 * marquage du lot SEPA -- puis fait re-franchir au dossier, PAR LE VRAI
 * WORKFLOW, la seule transition de son etat initial : « deposer ». Le journal
 * repart donc d'une ligne authentique, pas d'un statut pose de force.
 *
 * Ce qu'il ne touche jamais :
 *   - les outils figes 4, 5, 6 et 7 : aucun autre schema n'est ouvert ;
 *   - la saisie de la secretaire (client, IBAN, montant, immatriculation, code
 *     ICAR, date de depot), qui appartient au monde synthetique charge ;
 *   - la verite de mesure, qui n'est jamais ECRITE ici (elle est lue, et
 *     seulement pour savoir quel dossier porte le scenario vedette : un outil
 *     de demonstration a ce droit, un module metier ne l'a pas) ;
 *   - les 2 499 autres dossiers, sauf demande explicite.
 */
#[AsCommand(
    name: 'app:demo:reset-remboursement',
    description: 'DEMONSTRATION : remet un dossier de remboursement a son etat initial jouable.',
)]
final class ResetDemoCommand extends Command
{
    /** Les colonnes que le parcours ecrit, et que le reset rend a NULL. */
    private const COLONNES_A_VIDER = [
        'controle_nom', 'controle_iban', 'controle_bic', 'controle_montant',
        'controle_immatriculation', 'controle_icar', 'controle_extra',
        'verdict_ia', 'verdict_info', 'refus_motif',
        'valide_nom', 'valide_iban', 'valide_bic', 'valide_montant',
        'valide_immatriculation', 'valide_icar', 'valide_libelle',
        'valide_code_comptable', 'valide_role_tiers', 'valide_par',
        'correction_pieces', 'correction_champs',
        'valide_directeur_le', 'confirme_le', 'paye_le',
        'sepa_telecharge_le', 'sepa_telecharge_par',
        'modifie_par', 'modifie_le',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly DossierRepository $dossiers,
        private readonly WorkflowRemboursement $workflow,
        private readonly \App\Remboursement\Demo\ChaineIntegrite $chaine,
        #[Autowire('%kernel.environment%')]
        private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reference', null, InputOption::VALUE_REQUIRED,
                'Le dossier a remettre a zero. A defaut, le dossier vedette du scenario.')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED,
                'Scenario dont on remet le dossier vedette.', 'SC-08-06')
            ->addOption('tous', null, InputOption::VALUE_NONE,
                'Remettre a zero TOUS les dossiers qui ont quitte le depot.')
            ->addOption('nettoyer-session', null, InputOption::VALUE_NONE,
                'Retirer les dossiers crees pendant la session (hors univers charge).')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Autoriser hors environnement de demonstration.');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Reset de demonstration — module Remboursement');

        if ('demo' !== $this->environnement && true !== $entree->getOption('force')) {
            $io->error(sprintf(
                'Environnement « %s ». Ce reset n\'appartient qu\'a la copie de demonstration.'
                .' Relancez avec --force si vous savez ce que vous faites.', $this->environnement));

            return Command::FAILURE;
        }

        if (true === $entree->getOption('nettoyer-session')) {
            return $this->nettoyerSession($io);
        }

        $cibles = $this->cibles($entree, $io);
        if ([] === $cibles) {
            return Command::FAILURE;
        }

        $lignes = [];
        foreach ($cibles as $dossier) {
            $avant = $dossier->getStatut()->value;
            $compte = $this->remettre($dossier);
            $this->em->clear();
            $recharge = $this->dossiers->findOneBy(['reference' => $dossier->getReference()]);
            $lignes[] = [
                (string) $dossier->getReference(),
                $avant,
                null === $recharge ? '?' : $recharge->getStatut()->value,
                (string) $compte['pieces'],
                (string) $compte['extractions'],
                (string) $compte['transitions'],
            ];
        }

        // La chaine d'integrite est reposee : un reset supprime des transitions,
        // donc des maillons, et une chaine amputee en son milieu est cassee --
        // elle a raison de le dire. Elle protege une exploitation, pas une
        // machine a remonter le temps.
        $maillons = $this->chaine->reconstruire();

        $io->table(
            ['Dossier', 'Statut avant', 'Statut apres', 'Pieces retirees', 'Extractions', 'Transitions effacees'],
            $lignes);
        $io->text('La saisie de la secretaire est intacte : client, IBAN, montant, immatriculation,');
        $io->text('code ICAR et date de depot appartiennent au monde synthetique, pas au parcours.');
        $io->text('Aucun autre schema n\'a ete ouvert : les outils 4, 5, 6 et 7 sont hors de portee.');
        $io->text('Les traces des deux renforcements de la copie sont reprises aussi : empreinte');
        $io->text('de paiement et evenements de rejeu effaces, chaine d\'integrite reposee sur le');
        $io->text(sprintf('journal restant (%s maillons). Une chaine amputee en son milieu serait',
            number_format($maillons, 0, ',', ' ')));
        $io->text('cassee, et elle aurait raison de le dire : elle protege une exploitation, pas');
        $io->text('une machine a remonter le temps.');

        $io->success(1 === \count($lignes)
            ? 'Dossier pret a etre rejoue depuis la file « a verifier » apres analyse.'
            : sprintf('%d dossiers remis a leur etat initial.', \count($lignes)));

        return Command::SUCCESS;
    }

    /**
     * Retire les dossiers nes pendant la session.
     *
     * L'univers charge est exactement l'ensemble des references presentes dans
     * la verite de mesure. Tout dossier qui n'y figure pas a ete cree pendant
     * une demonstration -- un depot joue devant le jury, par exemple -- et n'a
     * pas a rester dans l'etat presente.
     */
    private function nettoyerSession(SymfonyStyle $io): int
    {
        $references = $this->cnx->fetchFirstColumn(
            'SELECT d.reference FROM remboursement.dossier d
              WHERE NOT EXISTS (SELECT 1 FROM remboursement_verite.dossier v
                                 WHERE v.reference = d.reference)
              ORDER BY d.id');

        if ([] === $references) {
            $io->text("Aucun dossier de session : l'etat presente est celui de l'univers charge.");

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d dossier(s) de session a retirer : %s',
            \count($references), implode(', ', array_slice($references, 0, 12))));

        $ids = $this->cnx->fetchFirstColumn(
            'SELECT id FROM remboursement.dossier d
              WHERE NOT EXISTS (SELECT 1 FROM remboursement_verite.dossier v
                                 WHERE v.reference = d.reference)');
        $liste = implode(',', array_map('intval', $ids));

        foreach ([
            'remboursement.journal_integrite',
            'remboursement.rejeu_evenement',
            'remboursement.paiement_empreinte',
            'remboursement.source_piece_demo',
            'remboursement.extraction_piece',
            'remboursement.dossier_piece',
            'remboursement.dossier_transition',
        ] as $table) {
            if (null !== $this->cnx->fetchOne('SELECT to_regclass(?)', [$table])) {
                $this->cnx->executeStatement(
                    'DELETE FROM '.$table.' WHERE dossier_id IN ('.$liste.')');
            }
        }
        $retires = $this->cnx->executeStatement(
            'DELETE FROM remboursement.dossier WHERE id IN ('.$liste.')');

        $maillons = $this->chaine->reconstruire();
        $io->text(sprintf('Chaine reposee sur le journal restant : %s maillons.',
            number_format($maillons, 0, ',', ' ')));

        $io->success(sprintf('%d dossier(s) de session retire(s).', $retires));

        return Command::SUCCESS;
    }

    /**
     * @return list<Dossier>
     */
    private function cibles(InputInterface $entree, SymfonyStyle $io): array
    {
        if (true === $entree->getOption('tous')) {
            /** @var list<Dossier> $tous */
            $tous = $this->em->createQuery(
                'SELECT d FROM App\Remboursement\Entity\Dossier d WHERE d.statut != :depose')
                ->setParameter('depose', DossierStatut::DEPOSE)
                ->getResult();
            if ([] === $tous) {
                $io->text('Aucun dossier n\'a quitte le depot : rien a remettre.');
            }

            return $tous;
        }

        $reference = $entree->getOption('reference');
        if (\is_string($reference) && '' !== $reference) {
            $dossier = $this->dossiers->findOneBy(['reference' => $reference]);
            if (null === $dossier) {
                $io->error('Dossier introuvable : '.$reference);

                return [];
            }

            return [$dossier];
        }

        // Le dossier vedette du scenario : le plus gros montant.
        //
        // Cette requete LIT le schema de verite, et c'est assume : un outil de
        // demonstration et de mesure a le droit de savoir quel scenario porte
        // quel dossier. La regle qu'on ne franchit pas est ailleurs -- aucun
        // MODULE METIER, aucun ecran, aucune route du parcours ne lit ce schema.
        // Le reset n'est ni un module, ni un ecran, ni une route.
        $scenario = (string) $entree->getOption('scenario');
        $ref = $this->cnx->fetchOne(
            'SELECT d.reference FROM remboursement.dossier d
               JOIN remboursement_verite.dossier v ON v.reference = d.reference
              WHERE v.code_scenario = ? ORDER BY d.montant DESC LIMIT 1', [$scenario]);
        if (!\is_string($ref)) {
            $io->error('Aucun dossier pour le scenario '.$scenario);

            return [];
        }
        $io->text(sprintf('Dossier vedette du scenario %s : %s', $scenario, $ref));
        $dossier = $this->dossiers->findOneBy(['reference' => $ref]);

        return null === $dossier ? [] : [$dossier];
    }

    /**
     * Remet un dossier a son etat initial.
     *
     * @return array{pieces: int, extractions: int, transitions: int}
     */
    private function remettre(Dossier $dossier): array
    {
        $id = (int) $dossier->getId();

        $extractions = $this->cnx->executeStatement(
            'DELETE FROM remboursement.extraction_piece WHERE dossier_id = ?', [$id]);
        $pieces = $this->cnx->executeStatement(
            'DELETE FROM remboursement.dossier_piece WHERE dossier_id = ?', [$id]);
        $transitions = $this->cnx->executeStatement(
            'DELETE FROM remboursement.dossier_transition WHERE dossier_id = ?', [$id]);

        // Les deux RENFORCEMENTS de la copie laissent aussi des traces, et le
        // reset doit les reprendre : sans cela, l'empreinte de paiement
        // survivrait au retour au depot et l'anti-rejeu bloquerait la
        // demonstration suivante -- il ferait exactement son travail, mais sur
        // un paiement qui n'existe plus.
        foreach ([
            'remboursement.journal_integrite',
            'remboursement.rejeu_evenement',
            'remboursement.paiement_empreinte',
        ] as $table) {
            if (null !== $this->cnx->fetchOne('SELECT to_regclass(?)', [$table])) {
                $this->cnx->executeStatement('DELETE FROM '.$table.' WHERE dossier_id = ?', [$id]);
            }
        }

        // Les colonnes ecrites par le parcours reviennent a NULL, et le statut
        // repart de BROUILLON pour que la transition « deposer » soit legale.
        $vides = implode(' = NULL, ', self::COLONNES_A_VIDER).' = NULL';
        $this->cnx->executeStatement(
            'UPDATE remboursement.dossier SET '.$vides.', statut = ? WHERE id = ?',
            [DossierStatut::BROUILLON->value, $id]);

        // Le depot est re-franchi PAR LE VRAI WORKFLOW : le journal du dossier
        // recommence par une ligne authentique, avec sa garde de statut.
        $this->em->clear();
        $frais = $this->dossiers->find($id);
        if (null !== $frais) {
            $this->workflow->appliquer($frais, 'deposer', (string) $frais->getCreePar(),
                "Depot initial de l'univers de demonstration.", false, true, true);
        }

        return [
            'pieces' => (int) $pieces,
            'extractions' => (int) $extractions,
            'transitions' => (int) $transitions,
        ];
    }
}
