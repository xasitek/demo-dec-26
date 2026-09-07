<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\ContratsMesure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * LE GEL DE LA FABRIQUE DE MESURE.
 *
 * Ce que la mesure diagnostique a coute est simple a nommer : elle annoncait
 * des verites dont la cause n'existait pas. Un doublon attendu sans jumeau. Un
 * IBAN invalide alors que tous les IBAN charges etaient valides. Un controle
 * de revelation d'IBAN qui n'a jamais existe dans le module. Le chiffre sorti
 * de la ne mesurait pas le moteur, il mesurait la fabrique.
 *
 * Cette commande verifie le contrat AVANT toute mesure, et refuse de geler si
 * l'une des sept conditions manque. Elle ne mesure rien : elle interdit de
 * mesurer a vide.
 *
 *   1. Chaque classe de cas est unique et numerotee sans trou.
 *   2. Chaque attente appartient aux quatre orientations possibles.
 *   3. Chaque classe s'adosse a un controle QUI EXISTE dans le registre --
 *      les 54 herites ou les 3 renforcements -- ou declare explicitement
 *      qu'aucun controle n'existe, ce qui est alors le sujet du cas.
 *   4. Chaque classe declare au moins une premisse.
 *   5. Chaque premisse declaree est CONNUE de la fabrique.
 *   6. Chaque premisse implementee par la fabrique sert a au moins une classe :
 *      pas de cause qui dort, pas de verification decorative.
 *   7. Chaque classe dit sa preuve et l'action attendue.
 *
 * Puis elle publie un condensat du contrat. Un contrat modifie change de
 * condensat, et la mesure publiee cesse de correspondre : c'est le seul gel
 * qui vaille.
 */
#[AsCommand(
    name: 'app:demo:figer-fabrique-mesure',
    description: 'Verifie et gele le contrat de mesure de l\'outil 8 (invariant de premisse).',
)]
final class FigerFabriqueMesureCommand extends Command
{
    /** Les quatre orientations qu'un cas peut attendre, et aucune autre. */
    private const ORIENTATIONS = ['autorise', 'bloque', 'humain', 'refus_entree'];

    /** La phrase que la fabrique doit porter, mot pour mot. */
    private const INVARIANT = 'Une verite ne peut pas exister sans sa cause';

    /** Les scenarios retires, qui ne doivent plus apparaitre nulle part. */
    private const RETIRES = ['SC-08-19', 'SC-08-20'];

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Gel de la fabrique de mesure — outil 8');

        $racine = \dirname(__DIR__, 3);
        $fabrique = (string) file_get_contents($racine.'/src/Remboursement/Demo/FabriqueMesure.php');
        $classes = ContratsMesure::toutes();

        $connus = self::premissesDeLaFabrique($fabrique);
        $registre = array_merge(
            array_keys(RegistreControlesCommand::REGISTRE),
            array_keys(RegistreControlesCommand::RENFORCEMENTS));

        $manques = [];
        $lignes = [];
        $vus = [];
        $servies = [];

        foreach ($classes as $rang => $cas) {
            $attendu = sprintf('CAS-%02d', $rang + 1);
            if ($cas->classe !== $attendu) {
                $manques[] = sprintf('1. numerotation : « %s » attendu la ou « %s » est declare.',
                    $attendu, $cas->classe);
            }
            if (isset($vus[$cas->classe])) {
                $manques[] = sprintf('1. classe en double : « %s ».', $cas->classe);
            }
            $vus[$cas->classe] = true;

            if (!\in_array($cas->attendu, self::ORIENTATIONS, true)) {
                $manques[] = sprintf('2. %s : orientation « %s » hors des quatre possibles.',
                    $cas->classe, $cas->attendu);
            }

            $adosse = 'oui';
            if ('aucun' === $cas->controle) {
                // Un cas peut porter sur l'ABSENCE d'un controle -- c'est alors
                // son sujet, et il doit le dire en clair dans sa preuve.
                $adosse = 'aucun (trou assume)';
                if (!str_contains(mb_strtolower($cas->preuve), 'aucun controle')) {
                    $manques[] = sprintf('3. %s : declare « aucun » controle sans le dire dans sa preuve.',
                        $cas->classe);
                }
            } elseif (!\in_array($cas->controle, $registre, true)) {
                $manques[] = sprintf('3. %s : le controle « %s » n\'existe pas dans le registre.',
                    $cas->classe, $cas->controle);
                $adosse = 'INTROUVABLE';
            }

            if ([] === $cas->premisses) {
                $manques[] = sprintf('4. %s : aucune premisse declaree.', $cas->classe);
            }
            foreach ($cas->premisses as $premisse) {
                $servies[$premisse] = true;
                if (!\in_array($premisse, $connus, true)) {
                    $manques[] = sprintf('5. %s : la premisse « %s » est inconnue de la fabrique.',
                        $cas->classe, $premisse);
                }
            }

            if ('' === trim($cas->preuve) || '' === trim($cas->action)) {
                $manques[] = sprintf('7. %s : preuve ou action attendue manquante.', $cas->classe);
            }

            $lignes[] = [$cas->classe, $cas->controle.' — '.$adosse, $cas->attendu,
                (string) \count($cas->premisses), implode(', ', $cas->premisses)];
        }

        foreach ($connus as $premisse) {
            if (!isset($servies[$premisse])) {
                $manques[] = sprintf('6. la premisse « %s » est implementee mais ne sert a aucun cas.',
                    $premisse);
            }
        }

        if (!str_contains($fabrique, self::INVARIANT)) {
            $manques[] = 'L\'invariant de premisse a disparu du code de la fabrique.';
        }

        foreach (self::RETIRES as $retire) {
            if (str_contains($fabrique, $retire)
                || str_contains((string) file_get_contents($racine.'/src/Remboursement/Demo/ContratsMesure.php'), $retire)) {
                $manques[] = sprintf('Le scenario retire « %s » subsiste dans le code de mesure.', $retire);
            }
        }

        $io->section('Le contrat, classe par classe');
        $io->table(['Classe', 'Controle adosse', 'Attendu', 'Premisses', 'Lesquelles'], $lignes);

        $io->section('Ce que le gel verifie');
        $io->table(['Condition', 'Etat'], [
            ['1. classes uniques et numerotees sans trou', sprintf('%d classes', \count($classes))],
            ['2. orientations dans les quatre possibles', 'verifie'],
            ['3. controles adosses au registre reel', sprintf('%d codes au registre', \count($registre))],
            ['4. chaque classe declare sa premisse', 'verifie'],
            ['5. premisses connues de la fabrique', sprintf('%d premisses implementees', \count($connus))],
            ['6. aucune premisse implementee inutilisee', sprintf('%d servies', \count($servies))],
            ['7. preuve et action attendue presentes', 'verifie'],
            ['L\'invariant est dans le code', str_contains($fabrique, self::INVARIANT) ? 'present' : 'ABSENT'],
            ['Scenarios retires', implode(' et ', self::RETIRES).' : retires du contrat'],
        ]);

        if ([] !== $manques) {
            $io->section('Ce qui empeche le gel');
            $io->listing($manques);
            $io->error(sprintf('%d condition(s) non tenue(s) : la fabrique n\'est pas gelee.', \count($manques)));

            return Command::FAILURE;
        }

        $condensat = self::condensat($classes);
        $io->section('Le condensat du contrat');
        $io->table(['Grandeur', 'Valeur'], [
            ['Classes de cas', (string) \count($classes)],
            ['Premisses implementees', (string) \count($connus)],
            ['Condensat SHA-256 du contrat', $condensat],
        ]);

        $io->success('Fabrique de mesure gelee. Le contrat tient : chaque verite a sa cause materielle.');

        return Command::SUCCESS;
    }

    /**
     * Les premisses que la fabrique sait verifier, lues dans son propre code.
     *
     * On lit le fichier plutot que de tenir une liste a cote : une liste a cote
     * se desynchronise en silence, un fichier non.
     *
     * @return list<string>
     */
    private static function premissesDeLaFabrique(string $source): array
    {
        $debut = mb_strpos($source, 'public function verifierPremisse');
        if (false === $debut) {
            return [];
        }
        $corps = mb_substr($source, $debut);
        preg_match_all('/^\s{16}\'([a-z_]+)\' =>/m', $corps, $trouves);

        /** @var list<string> $liste */
        $liste = array_values(array_unique($trouves[1]));

        return $liste;
    }

    /**
     * Le condensat du contrat : classe, attente, controle, premisses.
     *
     * @param list<\App\Remboursement\Demo\CasMesure> $classes
     */
    private static function condensat(array $classes): string
    {
        $lignes = array_map(
            static fn (\App\Remboursement\Demo\CasMesure $c): string => implode('|', [
                $c->classe, $c->attendu, $c->controle, implode(',', $c->premisses),
            ]),
            $classes);

        return hash('sha256', implode("\n", $lignes));
    }
}
