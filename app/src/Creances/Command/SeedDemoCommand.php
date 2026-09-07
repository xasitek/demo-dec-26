<?php

declare(strict_types=1);

namespace App\Creances\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande utilitaire pour developpement : seed des donnees de demo (tiers
 * + balance agee + ecritures historiques) dans le schema `creances_demo`,
 * **dedie au module** et isole du miroir Progiciel. Permet de tester rapidement
 * le module Recouvrement sans avoir un dump Progiciel.
 *
 * Lecture par l'application : les vues `creances.v_tiers`,
 * `creances.v_balance_agee` et `creances.v_bal_eloficash` font un UNION ALL
 * entre `mirror.*` (donnees Progiciel reelles) et `creances_demo.*` (fakes),
 * donc les seeds apparaissent dans l'UI sans toucher au mirror.
 *
 * Refuse de s'executer si APP_ENV=prod (garde-fou).
 */
#[AsCommand(
    name: 'app:creances:seed-demo',
    description: 'Poser des donnees de demo dans creances_demo.* pour tester le module Recouvrement en local',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $env = 'dev',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Vider creances_demo.* avant d\'inserer')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirme l\'execution (refuse sans ce flag)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->env) {
            $io->error('Cette commande est interdite en production.');

            return Command::FAILURE;
        }

        if (!$input->getOption('confirm')) {
            $io->warning('Cette commande insere des donnees factices dans creances_demo.tiers, creances_demo.balance_agee et creances_demo.bal_eloficash. Relance avec --confirm pour proceder.');

            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            // creances_demo.* est integralement reserve aux fakes : on peut
            // vider sans filtre. Aucune ligne Progiciel ne vit ici.
            $purgeTiers = $this->connection->executeStatement('DELETE FROM creances_demo.tiers');
            $purgeBal = $this->connection->executeStatement('DELETE FROM creances_demo.balance_agee');
            $purgeEcr = $this->connection->executeStatement('DELETE FROM creances_demo.bal_eloficash');
            $io->writeln(sprintf('Purge : %d tiers, %d balance_agee, %d ecritures.', $purgeTiers, $purgeBal, $purgeEcr));
        }

        $tiers = $this->jeuTiers();
        foreach ($tiers as $t) {
            $this->insererTiers($t);
        }
        $io->writeln(sprintf('%d tiers inseres dans creances_demo.tiers.', count($tiers)));

        $balanceAgee = $this->jeuBalanceAgee();
        foreach ($balanceAgee as $row) {
            $this->insererBalanceAgee($row);
        }
        $io->writeln(sprintf('%d creances inserees dans creances_demo.balance_agee.', count($balanceAgee)));

        $ecritures = $this->jeuEcritures($balanceAgee);
        foreach ($ecritures as $e) {
            $this->insererEcriture($e);
        }
        $io->writeln(sprintf('%d ecritures inserees dans creances_demo.bal_eloficash.', count($ecritures)));

        $io->success('Donnees DEMO posees dans creances_demo. Va sur /creances pour les voir.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jeuTiers(): array
    {
        return [
            ['code' => '394074', 'civilite' => 'Monsieur', 'prenom' => 'Kirdan', 'nom' => 'VELKUR', 'adresse' => '20 RUE DES SYNTHES', 'cp' => '67150', 'ville' => 'OSKNEM', 'email' => 'kirdan.velkur@demonstration.invalid', 'tel' => '0399000051', 'type' => 'EN COMPTE', 'categorie' => 'PART'],
            ['code' => '405128', 'civilite' => 'Madame', 'prenom' => 'Fargil', 'nom' => 'NAUTEG', 'adresse' => '15 AV DE LA FABRIQUE', 'cp' => '67000', 'ville' => 'CAVVOR', 'email' => 'fargil.nauteg@demonstration.invalid', 'tel' => '0399000022', 'type' => 'EN COMPTE', 'categorie' => 'PART'],
            ['code' => '411203', 'civilite' => 'Monsieur', 'prenom' => 'Voltnem', 'nom' => 'DULCEWEN', 'adresse' => '8 RUE DU MONDE SYNTHETIQUE', 'cp' => '68100', 'ville' => 'VOROSK', 'email' => 'voltnem.dulcewen@demonstration.invalid', 'tel' => '0399000099', 'type' => 'EN COMPTE', 'categorie' => 'PRO'],
            ['code' => '418556', 'civilite' => 'Madame', 'prenom' => 'Maevor', 'nom' => 'KRESSNAU', 'adresse' => '3 IMPASSE DES GRAINES', 'cp' => '67400', 'ville' => 'TEGOSK', 'email' => 'maevor.kressnau@demonstration.invalid', 'tel' => '0399000044', 'type' => 'EN COMPTE', 'categorie' => 'PART'],
            ['code' => '420811', 'civilite' => 'Societe', 'prenom' => '', 'nom' => 'NAUTEG TRANSPORT SA', 'adresse' => '42 ZONE SYNTHETIQUE', 'cp' => '67120', 'ville' => 'BRIXDAN', 'email' => 'compta@nauteg-transport.demonstration.invalid', 'tel' => '0399000088', 'type' => 'EN COMPTE', 'categorie' => 'PRO'],
            ['code' => '425102', 'civilite' => 'Societe', 'prenom' => '', 'nom' => 'ASSURANCES ZORLIM SA', 'adresse' => '17 RUE DE LA MESURE', 'cp' => '67500', 'ville' => 'BRIXTAL', 'email' => 'sinistres@zorlim.demonstration.invalid', 'tel' => '0399000011', 'type' => 'EN COMPTE', 'categorie' => 'ASSUR'],
            ['code' => '430445', 'civilite' => 'Madame', 'prenom' => 'Sabfex', 'nom' => 'TRIKAL', 'adresse' => '5 PLACE DU CONTROLE', 'cp' => '67700', 'ville' => 'PELGIL', 'email' => 'sabfex.trikal@demonstration.invalid', 'tel' => '0399000023', 'type' => 'COMPTANT', 'categorie' => 'PART'],
            ['code' => '432918', 'civilite' => 'Societe', 'prenom' => '', 'nom' => 'KALVAL LOCATION FRANCE', 'adresse' => '11 RUE PRINCIPALE', 'cp' => '68000', 'ville' => 'CAVPEL', 'email' => 'flotte@kalval-location.demonstration.invalid', 'tel' => '0399000077', 'type' => 'EN COMPTE', 'categorie' => 'LOUEUR'],
            ['code' => '440032', 'civilite' => 'Societe', 'prenom' => '', 'nom' => 'GARAGE TALGIL SARL', 'adresse' => '28 RUE DES ECRITURES', 'cp' => '67100', 'ville' => 'GILNAU', 'email' => 'contact@garage-talgil.demonstration.invalid', 'tel' => '0399000066', 'type' => 'EN COMPTE', 'categorie' => 'CONCESS'],
            ['code' => '445290', 'civilite' => 'Societe', 'prenom' => '', 'nom' => 'ASSURANCES WENSOL', 'adresse' => '6 ALLEE DES TILLEULS', 'cp' => '67300', 'ville' => 'NEMFERD', 'email' => 'sinistres@wensol.demonstration.invalid', 'tel' => '0399000055', 'type' => 'EN COMPTE', 'categorie' => 'ASSUR'],
        ];
    }

    /**
     * Map service -> compte comptable typique chez les concessionnaires.
     *
     * @var array<string, string>
     */
    private const SERVICES_COMPTE = [
        'VN' => '4116100',  // Vente Neuf
        'VO' => '4116200',  // Vente Occasion
        'GAR' => '4112100', // Garantie constructeur
        'APV' => '4117100', // Apres-Vente (atelier, pieces)
    ];

    /**
     * Map service -> prefixe numero de piece (convention courante Synthauto).
     *
     * @var array<string, string>
     */
    private const SERVICES_PIECE = [
        'VN' => 'VN',
        'VO' => 'VO',
        'GAR' => 'DG',  // Demande de Garantie
        'APV' => 'OR',  // Ordre de Reparation
    ];

    /**
     * @return list<array<string, mixed>>
     */
    private function jeuBalanceAgee(): array
    {
        $marques = ['MARQUE A', 'MARQUE B', 'MARQUE C', 'MARQUE D', 'MARQUE E'];
        $etabs = ['SYNTHAUTO OSKNEM 67 VN', 'SYNTHAUTO CAVVOR 68 VO', 'SYNTHAUTO VOROSK 68 CARR', 'SYNTHAUTO TEGOSK 90 VN'];
        $soc = ['SYNTOSK', 'SYNTCAV', 'SYNTVOR'];
        $vendeurs = [
            ['VELKUR', 'KIRDAN'],
            ['NAUTEG', 'FARGIL'],
            ['TRIKAL', 'SABFEX'],
            ['DULCEWEN', 'MAEVOR'],
        ];
        $tranches = ['<30', '>30', '>60', '>90', '>120', '>180', '>240'];
        // Le service (VN/VO/GAR/APV) reste utilise EN INTERNE au seed pour
        // varier les montants, prefixes de pieces et comptes comptables. Mais
        // on ne le pose PAS comme cle JSONB dans creances_demo.balance_agee :
        // la vraie balance_agee Progiciel n'a pas cette colonne, donc le module ne
        // peut pas la lire. Audit 2026-05-29.
        $services = array_keys(self::SERVICES_COMPTE);

        $tiers = $this->jeuTiers();
        $now = new DateTimeImmutable();
        $rows = [];
        $i = 1;
        foreach ($tiers as $t) {
            $nbEcritures = random_int(2, 5);

            for ($k = 0; $k < $nbEcritures; ++$k) {
                $tranche = $tranches[array_rand($tranches)];
                $joursEcoules = $this->joursPourTranche($tranche);
                $dateEcriture = $now->modify('-'.$joursEcoules.' days');
                $service = $services[array_rand($services)];

                // Le montant et le profil varient selon le service.
                if ('VN' === $service) {
                    $montant = round(random_int(800000, 3500000) / 100, 2);  // 8000 - 35000 EUR
                } elseif ('VO' === $service) {
                    $montant = round(random_int(400000, 1800000) / 100, 2);  // 4000 - 18000 EUR
                } elseif ('GAR' === $service) {
                    $montant = round(random_int(10000, 250000) / 100, 2);    // 100 - 2500 EUR
                } else { // APV
                    $montant = round(random_int(8000, 180000) / 100, 2);     // 80 - 1800 EUR
                }
                $marque = $marques[array_rand($marques)];
                $vendeur = $vendeurs[array_rand($vendeurs)];
                // Numpiece prefixe par le code service (VN, VO, DG, OR).
                $prefixe = self::SERVICES_PIECE[$service];
                $piece = $prefixe.'/'.str_pad((string) ($i + 100), 3, '0', \STR_PAD_LEFT).' 25/0'.random_int(3, 9).'-'.str_pad((string) random_int(10, 99999), 5, '0', \STR_PAD_LEFT);

                $rows[] = [
                    'numero' => '7'.str_pad((string) (1900000 + $i), 7, '0', \STR_PAD_LEFT),
                    'compte' => $t['code'],
                    'nom' => $t['nom'],
                    'prenom' => $t['prenom'],
                    'civilite' => $t['civilite'],
                    'adresse' => $t['adresse'].' '.$t['cp'].' '.$t['ville'],
                    'email' => $t['email'],
                    'numtel2' => $t['tel'],
                    'debit' => $montant,
                    'credit' => 0,
                    'montant' => $montant,
                    'retard' => $tranche,
                    'numpiece' => $piece,
                    'numimmat' => $this->fakePlate(),
                    'numvin' => 'VYS'.strtoupper(bin2hex(random_bytes(7))),
                    'marque' => $marque,
                    'modele' => $marque.' '.['Clio', 'Megane', '208', 'Sandero', '500'][array_rand(['Clio', 'Megane', '208', 'Sandero', '500'])],
                    'codeetab' => $etabs[array_rand($etabs)],
                    'codesoc' => $soc[array_rand($soc)],
                    'dateecriture' => $dateEcriture->format('Y-m-d H:i:s'),
                    'nomvendeur' => $vendeur[0],
                    'prenomvendeur' => $vendeur[1],
                    'codecompte' => self::SERVICES_COMPTE[$service],
                    'nomsecretaire' => 'Secretariat '.$service,
                    'emailsecr' => 'secretariat-'.strtolower($service).'@demonstration.invalid',
                ];
                ++$i;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $balanceAgee
     *
     * @return list<array<string, mixed>>
     */
    private function jeuEcritures(array $balanceAgee): array
    {
        $now = new DateTimeImmutable();
        $rows = [];

        // Reprend les creances ouvertes + ajoute des paiements anciens.
        foreach ($balanceAgee as $b) {
            $rows[] = [
                'numero' => $b['numero'],
                'compte' => $b['compte'],
                'nom' => $b['nom'],
                'prenom' => $b['prenom'],
                'libelle' => 'Facture '.$b['numpiece'],
                'debit' => $b['debit'],
                'credit' => 0,
                'dateecriture' => $b['dateecriture'],
                'numpiece' => $b['numpiece'],
                'numimmat' => $b['numimmat'],
                'numvin' => $b['numvin'],
                'marque' => $b['marque'],
                'modele' => $b['modele'],
                'codeetab' => $b['codeetab'],
                'codesoc' => $b['codesoc'],
                'codecompte' => $b['codecompte'],
            ];
        }

        // Quelques paiements (credits) historiques pour calculer DSO et
        // encaissement du mois.
        $tiers = $this->jeuTiers();
        for ($i = 0; $i < 25; ++$i) {
            $t = $tiers[array_rand($tiers)];
            $joursEcoules = random_int(1, 90);
            $rows[] = [
                'numero' => 'P'.str_pad((string) (8000000 + $i), 7, '0', \STR_PAD_LEFT),
                'compte' => $t['code'],
                'nom' => $t['nom'],
                'prenom' => $t['prenom'],
                'libelle' => 'Reglement client',
                'debit' => 0,
                'credit' => round(random_int(20000, 600000) / 100, 2),
                'dateecriture' => $now->modify('-'.$joursEcoules.' days')->format('Y-m-d H:i:s'),
                'numpiece' => 'REG/'.str_pad((string) ($i + 1), 5, '0', \STR_PAD_LEFT),
                'codecompte' => '4432100',
            ];
        }

        return $rows;
    }

    private function joursPourTranche(string $tranche): int
    {
        // Tranches Progiciel reelles (observees sur mirror.balance_agee). `<30`
        // couvre les non echus + premiers jours echus. Les autres sont
        // exclusivement echus.
        return match ($tranche) {
            '<30' => random_int(-30, 30),
            '>30' => random_int(31, 60),
            '>60' => random_int(61, 90),
            '>90' => random_int(91, 120),
            '>120' => random_int(121, 180),
            '>180' => random_int(181, 240),
            '>240' => random_int(241, 600),
            default => 0,
        };
    }

    private function fakePlate(): string
    {
        $letters = 'ABCDEFGHJKLMNPRSTVWXYZ';
        $a = $letters[random_int(0, strlen($letters) - 1)].$letters[random_int(0, strlen($letters) - 1)];
        $n = str_pad((string) random_int(1, 999), 3, '0', \STR_PAD_LEFT);
        $b = $letters[random_int(0, strlen($letters) - 1)].$letters[random_int(0, strlen($letters) - 1)];

        return $a.'-'.$n.'-'.$b;
    }

    /**
     * @param array<string, mixed> $t
     */
    private function insererTiers(array $t): void
    {
        // Cles alignees sur mirror.tiers (vraies cles Progiciel). `categorie_client`
        // n'existe nulle part dans Progiciel : on ne la pose pas. `TYPE`
        // ('EN COMPTE' / 'COMPTANT') existe dans tiers mais n'est pas exploite
        // par le module (balance_agee ne joint pas avec tiers).
        $donnees = [
            'code' => $t['code'],
            'nom' => $t['nom'],
            'prenom' => $t['prenom'],
            'civilite' => $t['civilite'],
            'Adresse 1' => $t['adresse'],
            'Code postal' => $t['cp'],
            'Ville' => $t['ville'],
            'email' => $t['email'],
            'Téléphone' => $t['tel'],
            'TYPE' => $t['type'],
            'Code statut' => 'OK',
        ];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $hash = 'DEMO_'.substr(md5(serialize($donnees)), 0, 27);
        $this->connection->executeStatement(
            'INSERT INTO creances_demo.tiers (cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le) '
            .'VALUES (:cle, :donnees, :hash, TRUE, :now, :now, :now) '
            .'ON CONFLICT (cle) DO UPDATE SET donnees = EXCLUDED.donnees, content_hash = EXCLUDED.content_hash, modifie_le = EXCLUDED.modifie_le',
            [
                'cle' => 'DEMO_TIERS_'.$t['code'],
                'donnees' => json_encode($donnees, \JSON_THROW_ON_ERROR),
                'hash' => $hash,
                'now' => $now,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insererBalanceAgee(array $row): void
    {
        // Cles alignees sur mirror.balance_agee (vraies cles Progiciel). On ne pose
        // PAS `codeservice`, `mode_paiement`, `type_compte`, `categorie_client`,
        // `compte_payeur` : ces cles n'existent pas dans la vraie balance_agee
        // Progiciel. Audit 2026-05-29 + isolation Progiciel par schema creances_demo.
        $donnees = [
            'numero' => $row['numero'],
            'compte' => $row['compte'],
            'nom' => $row['nom'],
            'prenom' => $row['prenom'],
            'civilite' => $row['civilite'],
            'adresse' => $row['adresse'],
            'email' => $row['email'],
            'numtel2' => $row['numtel2'],
            'debit' => (string) $row['debit'],
            'credit' => (string) $row['credit'],
            'Montant signe' => (string) $row['montant'],
            'Montant (valeur absolue)' => (string) $row['montant'],
            'retard' => $row['retard'],
            'numpiece' => $row['numpiece'],
            'numimmat' => $row['numimmat'],
            'numvin' => $row['numvin'],
            'marque' => $row['marque'],
            'modele' => $row['modele'],
            'codeetab' => $row['codeetab'],
            'codesoc' => $row['codesoc'],
            'dateecriture' => $row['dateecriture'],
            'nomvendeur' => $row['nomvendeur'],
            'prenomvendeur' => $row['prenomvendeur'],
            'codecompte' => $row['codecompte'],
            'nomsecretaire' => $row['nomsecretaire'] ?? null,
            'emailsecr' => $row['emailsecr'] ?? null,
        ];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $hash = 'DEMO_'.substr(md5(serialize($donnees)), 0, 27);
        $this->connection->executeStatement(
            'INSERT INTO creances_demo.balance_agee (cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le) '
            .'VALUES (:cle, :donnees, :hash, TRUE, :now, :now, :now) '
            .'ON CONFLICT (cle) DO UPDATE SET donnees = EXCLUDED.donnees, content_hash = EXCLUDED.content_hash, modifie_le = EXCLUDED.modifie_le',
            [
                'cle' => 'DEMO_BA_'.$row['numero'],
                'donnees' => json_encode($donnees, \JSON_THROW_ON_ERROR),
                'hash' => $hash,
                'now' => $now,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insererEcriture(array $row): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $hash = 'DEMO_'.substr(md5(serialize($row)), 0, 27);
        $this->connection->executeStatement(
            'INSERT INTO creances_demo.bal_eloficash (cle, donnees, content_hash, present_dans_sage, cree_le, vu_le, modifie_le) '
            .'VALUES (:cle, :donnees, :hash, TRUE, :now, :now, :now) '
            .'ON CONFLICT (cle) DO UPDATE SET donnees = EXCLUDED.donnees, content_hash = EXCLUDED.content_hash, modifie_le = EXCLUDED.modifie_le',
            [
                'cle' => 'DEMO_ECR_'.$row['numero'],
                'donnees' => json_encode($row, \JSON_THROW_ON_ERROR),
                'hash' => $hash,
                'now' => $now,
            ],
        );
    }
}
