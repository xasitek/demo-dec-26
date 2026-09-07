<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Shared\Repository\EtablissementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DEV : genere des dossiers de remboursement de test pour eprouver la vue comptable
 * avec beaucoup de donnees. 80 dossiers "a verifier" (A_VERIFIER) + 130 dossiers dans
 * les autres etats (section "Tous les dossiers"). N'a de sens qu'en local.
 */
#[AsCommand(name: 'app:remboursement:seed-test', description: 'DEV : cree 80 dossiers a verifier + 130 autres (donnees de test).')]
final class SeedTestCommand extends Command
{
    /**
     * Noms SYNTHETIQUES, batis sur les syllabes de la fabrique.
     *
     * Ils sont volontairement reconnaissables comme inventes : un jury doit voir
     * du premier coup d'oeil qu'aucune personne reelle n'est nommee.
     */
    private const NOMS = [
        'Velkur Fargetri', 'Nauteg Fargil', 'Dulcewen Maevor', 'Kressnau Sabfex',
        'Trikal Kirdan', 'Voltnem Osklim', 'Brixdan Talgil', 'Cavvor Pelnau',
        'Vorosk Zorlim', 'Tegosk Wensol', 'Gilnau Ryndan', 'Kalval Brytal',
        'Sabrin Danfex', 'Nemferd Kaltri', 'Pelgil Vorwen', 'Cavpel Murbrix',
        'Talkur Zornau', 'Wenzor Solkir', 'Brytal Nemsab', 'Ryndan Gilkur',
        'Osklim Cavtri', 'Murbrix Danvor', 'Zortal Kirsol', 'Fargil Wenkal',
    ];

    /** @var list<DossierStatut> */
    private const AUTRES_STATUTS = [
        DossierStatut::DEPOSE, DossierStatut::EXTRACTION_IA, DossierStatut::COMPLEMENT_REQUIS,
        DossierStatut::A_VALIDER_DIRECTEUR, DossierStatut::VALIDE_DIRECTEUR, DossierStatut::CONFIRME,
        DossierStatut::GENERATION_EN_COURS, DossierStatut::PAYE, DossierStatut::LETTRE,
        DossierStatut::REFUSE, DossierStatut::DOUBLON, DossierStatut::FRAUDE, DossierStatut::ERREUR_GENERATION,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EtablissementRepository $etablissements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $codes = [];
        foreach ($this->etablissements->rechercher() as $etab) {
            $codes[] = $etab->getCodeEtab();
        }
        if ([] === $codes) {
            $codes = ['9001', '9002', '9003', '9004', '9005'];
        }

        $n = 0;
        for ($i = 0; $i < 80; ++$i) {
            $this->creer(DossierStatut::A_VERIFIER, $codes);
            ++$n;
        }
        foreach ($this->repartir(130) as $statut) {
            $this->creer($statut, $codes);
            ++$n;
        }
        $this->em->flush();

        $io->success(sprintf('%d dossiers de test crees (80 a verifier + 130 autres).', $n));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $codes
     */
    private function creer(DossierStatut $statut, array $codes): void
    {
        $rachat = 0 === random_int(0, 1);
        $motif = $rachat ? DossierMotif::RACHAT_SEC : DossierMotif::TROP_PERCU;

        $d = new Dossier($motif, 'relances@demonstration.invalid');
        $nom = self::NOMS[array_rand(self::NOMS)];
        $d->setNomClient($nom);
        $montant = number_format(random_int(1000, 1500000) / 100, 2, '.', '');
        $d->setMontant($montant);
        $d->setEtablissementCode($codes[array_rand($codes)]);
        $d->definirStatut($statut);
        $d->setDeposeLe(new DateTimeImmutable(sprintf('-%d days', random_int(0, 60))));

        // Controles IA (pour un rendu realiste : point vert/ambre, valeurs remplies).
        $d->setControleNom(mb_strtoupper($nom));
        $d->setControleMontant($montant);
        $d->setControleIban('FR76'.str_pad((string) random_int(0, 99999999), 8, '0', \STR_PAD_LEFT).'0000000000');
        $d->setControleBic('AGRIFRPP889');
        $d->setVerdictIa(0 === random_int(0, 2) ? 'invalide' : 'valide');

        if ($rachat) {
            $immat = sprintf('%s-%03d-%s', self::lettres(2), random_int(0, 999), self::lettres(2));
            $d->setImmatriculation($immat);
            $d->setControleImmatriculation($immat);
        } else {
            $icar = (string) random_int(10000, 999999);
            $d->setCodeIcar($icar);
            $d->setControleIcar($icar);
        }

        $this->em->persist($d);
    }

    /**
     * Repartit N dossiers sur les autres statuts (round-robin).
     *
     * @return list<DossierStatut>
     */
    private function repartir(int $total): array
    {
        $out = [];
        for ($i = 0; $i < $total; ++$i) {
            $out[] = self::AUTRES_STATUTS[$i % \count(self::AUTRES_STATUTS)];
        }

        return $out;
    }

    private static function lettres(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; ++$i) {
            $s .= \chr(random_int(65, 90));
        }

        return $s;
    }
}
