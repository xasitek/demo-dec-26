<?php

declare(strict_types=1);

namespace App\BonusEco\Command;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Throwable;

#[AsCommand(name: 'app:bonus-eco:preview-mail', description: 'Envoie les 2 templates de relance en preview a une adresse.')]
final class PreviewMailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse destinataire pour le preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $ecritureExemple = [
            'numero' => 86115992,
            'codesoc' => 'SYN',
            'codeetab' => 'SYNTHAUTO OSKNEM 67 VN',
            'dateecriture' => '2026-06-10',
            'numpiece' => 'V1/241 26/06-00012',
            'numvin' => 'VSY9SYN00377145401',
            'numimmat' => 'HL-335-DB',
            'marque' => 'MARQUE A',
            'modele' => 'MODELE A1 électrique',
            'nom' => 'VELKUR',
            'prenom' => 'Fargil',
            'nomclientproprietaire' => 'VELKUR',
            'civilite' => 'Mme',
            'reference' => '10008855',
            'codecompte' => '4432000',
            'compte' => '4432000',
            'email' => 'fargil.velkur@demonstration.invalid',
            'numtel1' => '03 99 00 00 12',
            'montant_signe' => 7650.00,
            'debit' => 7650.00,
            'credit' => null,
            'retard' => '>30',
            'prenomvendeur' => 'Kirdan',
            'nomvendeur' => 'NAUTEG',
            'emailvendeur' => '',
            'nomsecretaire' => 'Sabfex TRIKAL',
            'emailsecr' => 'sabfex.trikal@demonstration.invalid',
        ];
        $aspExemple = [
            'lib_etat' => 'Demande initialisée',
            'num_dossier_mensuel' => 'ECO2606001234',
            'mt_paye' => null,
            'mt_bonus' => 4000,
            'numero_or' => '2606001234VEN00012',
            'date_paie_bonus' => null,
        ];
        $auteur = new class {
            public string $firstName = 'Sabfex';
            public string $lastName = 'KRESSNAU';
        };

        // Email 1 : relance unitaire
        $email1 = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($email))
            ->subject('[PREVIEW] Relance dossier bonus écologique - HL-335-DB')
            ->htmlTemplate('bonus_eco/emails/relance.html.twig')
            ->context([
                'ecriture' => $ecritureExemple,
                'asp' => $aspExemple,
                'auteur' => $auteur,
                'type' => 'vendeur',
                'destinataire_nom' => 'Kirdan NAUTEG',
            ]);

        // Email 2 : relance lot (3 dossiers fictifs)
        $email2 = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($email))
            ->subject('[PREVIEW] Relance dossiers bonus écologique en correction (3 dossiers)')
            ->htmlTemplate('bonus_eco/emails/relance_lot.html.twig')
            ->context([
                'ecritures' => [
                    $ecritureExemple,
                    array_merge($ecritureExemple, [
                        'numpiece' => 'V1/241 26/06-00013',
                        'numvin' => 'VSY9SYN00877035990',
                        'numimmat' => 'HL-471-DX',
                        'modele' => 'MODELE A2 électrique',
                        'nom' => 'DULCEWEN',
                        'montant_signe' => 4830.00,
                        'retard' => '<30',
                    ]),
                    array_merge($ecritureExemple, [
                        'numpiece' => 'V1/241 26/05-00099',
                        'numvin' => 'VSY9SYN00476676253',
                        'numimmat' => 'HK-473-ZS',
                        'modele' => 'MODELE A2 électrique',
                        'nom' => 'KRESSNAU',
                        'montant_signe' => 3620.00,
                        'retard' => '>60',
                        'codeetab' => 'SYNTHAUTO TEGOSK 90 VN',
                    ]),
                ],
                'auteur' => $auteur,
                'type' => 'vendeur',
                'destinataire_nom' => 'Kirdan NAUTEG',
            ]);

        try {
            $this->mailer->send($email1);
            $io->success('Email 1 (relance unitaire) envoyé à '.$email);
            $this->mailer->send($email2);
            $io->success('Email 2 (relance lot) envoyé à '.$email);
        } catch (Throwable $e) {
            $io->error('Echec : '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
