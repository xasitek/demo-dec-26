<?php

declare(strict_types=1);

namespace App\Recouvrement\Command;

use App\Recouvrement\Service\HtmlPdfConverter;
use App\Recouvrement\Service\LogoRecouvrement;
use App\Recouvrement\Service\SelectionRelanceService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Envoie les TROIS formats d'e-mail de relance (1re relance, relance ferme, mise
 * en demeure) pour un compte reel, afin de previsualiser le rendu (ex. dans
 * Mailpit). N'ecrit RIEN en base et ne suit pas la cadence : on force le niveau /
 * le drapeau MED sur un meme groupe pour montrer les trois gabarits.
 *
 * Usage typique (transport par defaut redirige vers Mailpit) :
 *   MAILER_DSN="smtp://127.0.0.1:1025" php bin/console app:recouvrement:preview-emails
 *
 * Sans compte fourni, on prend un compte APV ayant une immatriculation ET un OR
 * (numor), pour que la colonne "ID" du releve soit visible.
 */
#[AsCommand(
    name: 'app:recouvrement:preview-emails',
    description: 'Previsualise les 3 formats d\'email de relance (envoi vers le mailer courant, sans ecriture).',
)]
final class PreviewRelanceMailCommand extends Command
{
    private const FROM_EMAIL = 'recouvrement@relances.demonstration.invalid';
    private const REPLY_TO = 'relances@demonstration.invalid';

    /** @var array<string, array{sujet: string, template: string, niveau: int, med: bool, lien: string|null}> */
    private const FORMATS = [
        'relance_1' => ['sujet' => '1re relance', 'template' => 'recouvrement/email/relance_1.html.twig', 'niveau' => 1, 'med' => false, 'lien' => null],
        'relance_2' => ['sujet' => 'relance ferme', 'template' => 'recouvrement/email/relance_2.html.twig', 'niveau' => 2, 'med' => false, 'lien' => null],
        'mise_en_demeure' => ['sujet' => 'mise en demeure', 'template' => 'recouvrement/email/mise_en_demeure.html.twig', 'niveau' => 5, 'med' => true, 'lien' => null],
        // Variante debordement : factures trop volumineuses -> lien de telechargement
        // au lieu de la mention "en piece jointe" (lien factice pour l'apercu).
        'telechargement' => ['sujet' => 'variante lien telechargement', 'template' => 'recouvrement/email/relance_1.html.twig', 'niveau' => 1, 'med' => false, 'lien' => 'http://localhost:8123/recouvrement/telecharger/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'],
    ];

    public function __construct(
        private readonly SelectionRelanceService $selection,
        private readonly Connection $connection,
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly HtmlPdfConverter $htmlPdf,
        private readonly LogoRecouvrement $logo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('compte', InputArgument::OPTIONAL, 'Code compte a previsualiser (defaut : un compte APV avec immat + OR).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $compte = (string) ($input->getArgument('compte') ?? '');
        if ('' === $compte) {
            $compte = $this->compteParDefaut();
            if (null === $compte) {
                $io->error('Aucun compte APV avec immatriculation + OR trouve. Fournissez un code compte en argument.');

                return Command::FAILURE;
            }
        }

        $groupe = $this->selection->groupePourCompte($compte);
        if (null === $groupe) {
            $io->error(sprintf('Compte "%s" non eligible (aucune facture echue couverte par une regle active).', $compte));

            return Command::FAILURE;
        }

        $io->title(sprintf('Previsualisation des 3 formats - compte %s', $compte));
        $io->writeln(sprintf('%d facture(s), total %s EUR.', $groupe['nb_factures'], $groupe['total']));

        foreach (self::FORMATS as $format) {
            // On part du meme groupe reel et on force le niveau + le drapeau MED
            // propres a chaque gabarit (le contenu/releve reste identique).
            $variante = $groupe;
            $variante['niveau'] = $format['niveau'];
            $variante['mise_en_demeure'] = $format['med'];

            $html = $this->twig->render($format['template'], [
                'groupe' => $variante,
                'token' => 'PREVIEW',
                // Logo embarque en CID (comme le vrai envoi) : Gmail affiche le CID mais
                // bloque les data-URI. Le data-URI reste pour le PDF (dompdf).
                'logo_src' => $this->logo->srcCid(),
                'piece_jointe' => true,
                'lien_telechargement' => $format['lien'],
            ]);

            // Page de garde (releve document PDF) jointe, pour previsualiser le rendu PDF.
            $releveHtml = $this->twig->render('recouvrement/pdf/releve.html.twig', [
                'groupe' => $variante,
                'logo_src' => $this->logo->dataUri(),
            ]);

            $sujet = sprintf('[PREVIEW - %s] %s', $format['sujet'], $groupe['destinataire_nom'] ?: $compte);
            $email = (new Email())
                ->from(new Address(self::FROM_EMAIL, 'Service recouvrement'))
                ->to(new Address(self::REPLY_TO))
                ->replyTo(new Address(self::REPLY_TO))
                ->subject($sujet)
                ->html($html)
                ->attach($this->htmlPdf->enPdf($releveHtml), 'releve.pdf', 'application/pdf');
            // Logo SYNTHAUTO embarque en inline (CID), reference par le corps via cid:logosynth.
            if (is_file($this->logo->chemin())) {
                $email->embedFromPath($this->logo->chemin(), LogoRecouvrement::CID, 'image/png');
            }
            // Route via Mailjet (transport dedie) pour un apercu fidele au vrai envoi.
            $email->getHeaders()->addTextHeader('X-Transport', 'relances');

            $this->mailer->send($email);
            $io->writeln(sprintf(' - envoye : <info>%s</info>', $sujet));
        }

        $io->success('3 formats envoyes vers le mailer courant (ex. http://localhost:8025 pour Mailpit).');

        return Command::SUCCESS;
    }

    /**
     * Un compte APV (4114/4164) ayant une facture echue impayee avec immatriculation
     * ET ordre de reparation renseignes, pour que la colonne "ID" soit visible.
     */
    private function compteParDefaut(): ?string
    {
        $compte = $this->connection->fetchOne(
            'SELECT compte FROM recouvrement.v_impayes '
            ."WHERE collectif IN ('4114000', '4164000') "
            .'AND jours_retard > 0 AND montant_solde > 0 AND montant_initial_facturation > 0 '
            ."AND numimmat IS NOT NULL AND numimmat <> '' "
            ."AND numor IS NOT NULL AND numor NOT IN ('', '0') "
            .'LIMIT 1',
        );

        return false === $compte ? null : (string) $compte;
    }
}
