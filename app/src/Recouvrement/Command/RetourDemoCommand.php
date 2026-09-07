<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Enum\RetourSource;
use App\Recouvrement\Service\IngestionRetourService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fabrique 3 retours clients de demonstration pour alimenter la vue comptable
 * sans boite IMAP reelle branchee.
 *
 * Les 3 retours couvrent les categories cles : promesse de paiement,
 * contestation, et un message non categorisable. Ils passent par le VRAI chemin
 * d'ingestion (IngestionRetourService) : rattachement par token + categorisation
 * par mots-cles sont donc reellement exerces.
 *
 * Strategie de rattachement :
 *   - s'il existe deja des relances envoyees, on s'appuie sur leur token ;
 *   - sinon on cree des relances "demo" (statut ENVOYE) a partir d'impayes
 *     reels de recouvrement.v_impayes (compte + ecriture + email existants),
 *     puis on y rattache les retours via le token.
 *
 * Aucune dependance reseau, aucun email envoye. A usage dev/demo uniquement.
 */
#[AsCommand(
    name: 'app:recouvrement:retour-demo',
    description: 'Cree 3 retours clients de demonstration (promesse, contestation, non categorise).',
)]
final class RetourDemoCommand extends Command
{
    /** Corps des 3 retours de demonstration. */
    private const RETOURS = [
        [
            'sujet' => 'Re: Relance facture impayee',
            'corps' => "Bonjour,\n\nJe vous confirme que je vais payer la facture des reception de mon salaire la semaine prochaine. Je vous prie de bien vouloir patienter.\n\nCordialement.",
            'attendu' => 'promesse_paiement',
        ],
        [
            'sujet' => 'Re: Mise en demeure de payer',
            'corps' => "Bonjour,\n\nJe conteste formellement cette facture : elle a deja ete reglee le mois dernier par virement. Il s'agit d'une erreur de facturation de votre part.\n\nMerci de regulariser.",
            'attendu' => 'contestation',
        ],
        [
            'sujet' => 'Re: Relance facture impayee',
            'corps' => "Bonjour,\n\nPouvez-vous me rappeler de quelle facture il s'agit exactement ? Je n'ai pas le detail sous les yeux.\n\nMerci d'avance.",
            'attendu' => 'non_categorise',
        ],
    ];

    public function __construct(
        private readonly IngestionRetourService $ingestionRetourService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recouvrement : retours clients de demonstration');

        $supports = $this->preparerSupports($io);
        if ([] === $supports) {
            $io->warning('Aucune relance ni impaye exploitable : impossible de fabriquer des retours de demo.');

            return Command::SUCCESS;
        }

        $rows = [];
        $crees = 0;

        foreach (self::RETOURS as $index => $demo) {
            $support = $supports[$index % \count($supports)];
            $messageId = sprintf('<demo-%s-%d@retour.recouvrement>', bin2hex(random_bytes(4)), $index);

            $email = [
                'messageId' => $messageId,
                'inReplyTo' => $support['token'].'@recouvrement.demonstration.invalid',
                'from' => $support['email'],
                'sujet' => $demo['sujet'],
                'corpsTexte' => $demo['corps'],
                'corpsHtml' => '<p>'.nl2br(htmlspecialchars($demo['corps'])).'</p>',
                'headers' => [
                    'X-Recouvrement-Token' => $support['token'],
                    'From' => $support['email'],
                    'Message-ID' => $messageId,
                ],
                'recuLe' => new DateTimeImmutable(),
            ];

            $retour = $this->ingestionRetourService->ingerer($email, RetourSource::MANUEL);

            if (null === $retour) {
                $rows[] = ['(ignore - doublon)', $support['compte'] ?? '-', $demo['attendu'], '-'];

                continue;
            }

            ++$crees;
            $categorie = $retour->getCategorie();
            $rows[] = [
                (string) $retour->getId(),
                $retour->getCompteCode() ?? '-',
                null !== $categorie ? $categorie->value : '-',
                null !== $retour->getRelanceEnvoi() ? 'token' : 'aucun',
            ];
        }

        $io->table(['Retour ID', 'Compte', 'Categorie', 'Rattachement'], $rows);
        $io->success(sprintf('%d retour(s) de demonstration cree(s).', $crees));

        return Command::SUCCESS;
    }

    /**
     * Fournit jusqu'a 3 "supports" (token + email + compte) sur lesquels rattacher
     * les retours. Reutilise les relances envoyees existantes, sinon en cree a
     * partir d'impayes reels.
     *
     * @return list<array{token: string, email: string, compte: ?string, ecriture: ?string}>
     */
    private function preparerSupports(SymfonyStyle $io): array
    {
        $supports = $this->supportsDepuisRelances();
        if (\count($supports) >= 3) {
            return \array_slice($supports, 0, 3);
        }

        $manquants = 3 - \count($supports);
        $crees = $this->creerRelancesDemo($manquants);
        if ([] !== $crees) {
            $io->writeln(sprintf('<comment>%d</comment> relance(s) de demo creee(s) pour le rattachement.', \count($crees)));
        }

        return array_merge($supports, $crees);
    }

    /**
     * Reutilise les relances deja en base (peu importe le statut) ayant un email.
     *
     * @return list<array{token: string, email: string, compte: ?string, ecriture: ?string}>
     */
    private function supportsDepuisRelances(): array
    {
        /** @var list<array{token: string, destinataire: ?string, compte_code: ?string, ecriture_id: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT token, destinataire, compte_code, ecriture_id '
            .'FROM recouvrement.relance_envoi '
            ."WHERE destinataire IS NOT NULL AND destinataire <> '' "
            .'ORDER BY id DESC LIMIT 3',
        );

        $supports = [];
        foreach ($rows as $row) {
            $supports[] = [
                'token' => $row['token'],
                'email' => (string) $row['destinataire'],
                'compte' => $row['compte_code'],
                'ecriture' => $row['ecriture_id'],
            ];
        }

        return $supports;
    }

    /**
     * Cree des relances de demo (statut ENVOYE) a partir d'impayes reels et
     * renvoie leurs supports de rattachement.
     *
     * @return list<array{token: string, email: string, compte: ?string, ecriture: ?string}>
     */
    private function creerRelancesDemo(int $nombre): array
    {
        if ($nombre < 1) {
            return [];
        }

        /** @var list<array{compte: ?string, ecriture_id: ?string, email: ?string, reference_facture: ?string, montant_solde: ?string}> $impayes */
        // DISTINCT ON (compte) : un seul impaye par compte, pour ne pas creer deux
        // relances (compte, niveau=1) -> respecte l'index unique partiel groupe.
        $impayes = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (compte) compte, ecriture_id, email, reference_facture, montant_solde '
            .'FROM recouvrement.v_impayes '
            ."WHERE jours_retard > 0 AND email IS NOT NULL AND email <> '' "
            .'AND ecriture_id IS NOT NULL AND compte IS NOT NULL '
            .'ORDER BY compte, jours_retard DESC LIMIT :limit',
            ['limit' => $nombre],
            ['limit' => ParameterType::INTEGER],
        );

        $supports = [];
        foreach ($impayes as $impaye) {
            $compte = (string) $impaye['compte'];
            $ecriture = (string) $impaye['ecriture_id'];
            $email = (string) $impaye['email'];
            $token = bin2hex(random_bytes(16));

            $relance = new RelanceEnvoi(
                $compte,
                1,
                RelanceVecteur::EMAIL,
                RelanceStatut::ENVOYE,
                $token,
            );
            $relance->setEcritureId($ecriture);
            $relance->setNbFactures(1);
            $relance->setReferenceFacture(self::nullable($impaye['reference_facture'] ?? null));
            $relance->setDestinataire($email);
            $relance->setMontantSolde(self::nullable($impaye['montant_solde'] ?? null));
            $relance->setSujet('Relance facture impayee (demo)');
            $relance->setEnvoyeLe(new DateTimeImmutable());

            $this->entityManager->persist($relance);

            $supports[] = [
                'token' => $token,
                'email' => $email,
                'compte' => $compte,
                'ecriture' => $ecriture,
            ];
        }

        if ([] !== $supports) {
            $this->entityManager->flush();
        }

        return $supports;
    }

    private static function nullable(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = trim($valeur);

        return '' === $texte ? null : $texte;
    }
}
