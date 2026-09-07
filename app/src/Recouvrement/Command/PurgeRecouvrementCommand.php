<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nettoyage / retention des donnees du module Recouvrement (a planifier, ex.
 * mensuel). Purge SEULEMENT ce qui est sur a supprimer et propre au recouvrement :
 *
 *   1. courrier_pdf des relances EMAIL en debordement : le blob n'est utile que le
 *      temps ou le client peut cliquer le lien de telechargement. On vide le blob
 *      apres N jours mais on GARDE la ligne relance_envoi (preuve legale).
 *   2. facture_pdf televerses devenus inutiles : la facture est soldee/disparue de
 *      Progiciel de facon STABLE. Verite = mirror.bal_eloficash (v_impayes est filtree et
 *      confond payee / disparue / non echue), + delai de stabilite (contre-passation).
 *   3. preparation_run termines : pur etat d'UI de progression.
 *
 * NON traite ici volontairement (a cadrer separement) :
 *   - retour_client / pieces jointes : donnees personnelles, retention longue
 *     (~3 ans), a valider avec le metier / referent RGPD ;
 *   - shared.notification : transverse a tous les modules, purge a mutualiser ;
 *   - transport Messenger `failed` : utiliser l'outillage natif (messenger:failed:*).
 *
 * Par defaut : SIMULATION (compte et affiche, ne supprime rien). --force execute.
 */
#[AsCommand(
    name: 'app:recouvrement:purge',
    description: 'Purge les donnees obsoletes du recouvrement (blobs email expires, PDF soldes, runs termines).',
)]
final class PurgeRecouvrementCommand extends Command
{
    /** Blob courrier_pdf d'un email vide N jours apres l'envoi (lien expire). */
    private const COURRIER_PDF_JOURS = 90;

    /** Blob courrier_pdf d'un COURRIER PAPIER vide N jours apres avoir ete poste. */
    private const COURRIER_PDF_PAPIER_JOURS = 60;

    /** PDF televerse supprimable au plus tot N jours apres son upload. */
    private const FACTURE_PDF_JOURS = 180;

    /** ...et seulement si la ligne source n'a pas bouge depuis N jours (stabilite). */
    private const FACTURE_PDF_STABILITE_JOURS = 90;

    /** Run de preparation termine supprime apres N jours. */
    private const PREPARATION_RUN_JOURS = 90;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Execute reellement la purge. Sans cette option : simulation (aucune suppression).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $io->title('Recouvrement : purge des donnees obsoletes'.($force ? '' : ' (simulation)'));
        if (!$force) {
            $io->note('Simulation : rien n\'est supprime. Relancez avec --force pour executer.');
        }

        $lignes = [];
        $lignes[] = $this->purgerCourrierPdfEmail($force);
        $lignes[] = $this->purgerCourrierPdfPapier($force);
        $lignes[] = $this->purgerFacturePdfSoldees($force);
        $lignes[] = $this->purgerPreparationRuns($force);

        $io->table(
            ['Cible', 'Lignes', 'Espace libere', 'Retention'],
            array_map(
                static fn (array $r): array => [$r['cible'], (string) $r['lignes'], $r['espace'], $r['retention']],
                $lignes,
            ),
        );

        $total = array_sum(array_map(static fn (array $r): int => $r['lignes'], $lignes));
        if ($force) {
            $io->success(sprintf('%d element(s) purge(s).', $total));
            $this->logger->info('Purge recouvrement : {total} elements purges', ['total' => $total, 'detail' => $lignes]);
        } else {
            $io->success(sprintf('%d element(s) seraient purges (simulation).', $total));
        }

        return Command::SUCCESS;
    }

    /**
     * Vide le blob courrier_pdf des relances EMAIL anciennes (lien de telechargement
     * expire). La LIGNE relance_envoi est conservee (preuve). Les COURRIERS papier
     * sont traites separement (purgerCourrierPdfPapier), une fois postes.
     *
     * @return array{cible: string, lignes: int, espace: string, retention: string}
     */
    private function purgerCourrierPdfEmail(bool $force): array
    {
        $seuil = $this->seuil(self::COURRIER_PDF_JOURS);
        $where = <<<'SQL'
            FROM recouvrement.relance_envoi
            WHERE vecteur = 'email' AND courrier_pdf IS NOT NULL
              AND COALESCE(envoye_le, prepare_le, cree_le) < :seuil
            SQL;

        $stat = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(octet_length(courrier_pdf)), 0) AS octets '.$where,
            ['seuil' => $seuil],
        ) ?: ['n' => 0, 'octets' => 0];
        $n = (int) $stat['n'];

        if ($force && $n > 0) {
            $this->connection->executeStatement(
                'UPDATE recouvrement.relance_envoi SET courrier_pdf = NULL '
                .'WHERE vecteur = \'email\' AND courrier_pdf IS NOT NULL AND COALESCE(envoye_le, prepare_le, cree_le) < :seuil',
                ['seuil' => $seuil],
            );
        }

        return [
            'cible' => 'courrier_pdf (email, blob)',
            'lignes' => $n,
            'espace' => $this->humaniserOctets((int) $stat['octets']),
            'retention' => self::COURRIER_PDF_JOURS.' j apres envoi',
        ];
    }

    /**
     * Vide le blob courrier_pdf des COURRIERS PAPIER deja POSTES (statut envoye) depuis
     * N jours : le PDF (releve + factures, souvent plusieurs Mo) n'est plus utile une
     * fois le courrier imprime et poste. La LIGNE relance_envoi est conservee (preuve).
     * Les courriers ENCORE EN ATTENTE (a_envoyer) gardent leur PDF (telechargement).
     *
     * @return array{cible: string, lignes: int, espace: string, retention: string}
     */
    private function purgerCourrierPdfPapier(bool $force): array
    {
        $seuil = $this->seuil(self::COURRIER_PDF_PAPIER_JOURS);
        $where = <<<'SQL'
            FROM recouvrement.relance_envoi
            WHERE vecteur = 'courrier' AND statut = 'envoye' AND courrier_pdf IS NOT NULL
              AND envoye_le < :seuil
            SQL;

        $stat = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(octet_length(courrier_pdf)), 0) AS octets '.$where,
            ['seuil' => $seuil],
        ) ?: ['n' => 0, 'octets' => 0];
        $n = (int) $stat['n'];

        if ($force && $n > 0) {
            $this->connection->executeStatement(
                'UPDATE recouvrement.relance_envoi SET courrier_pdf = NULL '
                .'WHERE vecteur = \'courrier\' AND statut = \'envoye\' AND courrier_pdf IS NOT NULL AND envoye_le < :seuil',
                ['seuil' => $seuil],
            );
        }

        return [
            'cible' => 'courrier_pdf (papier poste, blob)',
            'lignes' => $n,
            'espace' => $this->humaniserOctets((int) $stat['octets']),
            'retention' => self::COURRIER_PDF_PAPIER_JOURS.' j apres postage',
        ];
    }

    /**
     * Supprime les PDF televerses devenus inutiles : anciens ET dont l'ecriture n'a
     * plus AUCUNE ligne source ouverte (presente + solde <> 0) dans bal_eloficash, de
     * facon STABLE (pas de modif source recente -> absorbe un incident ETL ou une
     * contre-passation). v_impayes n'est PAS utilisee (elle confond payee/disparue).
     *
     * @return array{cible: string, lignes: int, espace: string, retention: string}
     */
    private function purgerFacturePdfSoldees(bool $force): array
    {
        $seuilPdf = $this->seuil(self::FACTURE_PDF_JOURS);
        $seuilStab = $this->seuil(self::FACTURE_PDF_STABILITE_JOURS);

        $where = <<<'SQL'
            FROM recouvrement.facture_pdf fp
            WHERE fp.uploaded_le < :seuilPdf
              AND NOT EXISTS (
                    SELECT 1 FROM mirror.bal_eloficash b
                    WHERE b.donnees->>'clé écriture' = fp.ecriture_id
                      AND b.present_dans_sage = true
                      AND COALESCE(b.donnees->>'Montant solde en devise entité', '0,00 €') <> '0,00 €'
              )
              AND NOT EXISTS (
                    SELECT 1 FROM mirror.bal_eloficash b
                    WHERE b.donnees->>'clé écriture' = fp.ecriture_id
                      AND b.modifie_le > :seuilStab
              )
            SQL;

        $params = ['seuilPdf' => $seuilPdf, 'seuilStab' => $seuilStab];

        $stat = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(fp.taille_octets), 0) AS octets '.$where,
            $params,
        ) ?: ['n' => 0, 'octets' => 0];
        $n = (int) $stat['n'];

        if ($force && $n > 0) {
            $this->connection->executeStatement('DELETE '.$where, $params);
        }

        return [
            'cible' => 'facture_pdf (soldees)',
            'lignes' => $n,
            'espace' => $this->humaniserOctets((int) $stat['octets']),
            'retention' => sprintf('%d j + %d j stabilite', self::FACTURE_PDF_JOURS, self::FACTURE_PDF_STABILITE_JOURS),
        ];
    }

    /**
     * Supprime les runs de preparation termines (etat d'UI). Ne touche jamais un run
     * EN_COURS.
     *
     * @return array{cible: string, lignes: int, espace: string, retention: string}
     */
    private function purgerPreparationRuns(bool $force): array
    {
        $seuil = $this->seuil(self::PREPARATION_RUN_JOURS);
        $where = "FROM recouvrement.preparation_run WHERE statut <> 'en_cours' AND lance_le < :seuil";

        $n = (int) $this->connection->fetchOne('SELECT COUNT(*) '.$where, ['seuil' => $seuil]);

        if ($force && $n > 0) {
            $this->connection->executeStatement('DELETE '.$where, ['seuil' => $seuil]);
        }

        return [
            'cible' => 'preparation_run (termines)',
            'lignes' => $n,
            'espace' => '-',
            'retention' => self::PREPARATION_RUN_JOURS.' j',
        ];
    }

    private function seuil(int $jours): string
    {
        return (new DateTimeImmutable('-'.$jours.' days'))->format('Y-m-d H:i:sP');
    }

    private function humaniserOctets(int $octets): string
    {
        if ($octets <= 0) {
            return '-';
        }
        $unites = ['o', 'Ko', 'Mo', 'Go'];
        $i = (int) min(3, floor(log($octets, 1024)));

        return sprintf('%.1f %s', $octets / 1024 ** $i, $unites[$i]);
    }
}
