<?php

declare(strict_types=1);

namespace App\Pilotage\Command;

use App\Pilotage\Moteur\Cockpit;
use App\Pilotage\Moteur\Conventions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Les controles de reconciliation du cockpit.
 *
 * Un cockpit ne predit rien : il consolide. Un test en aveugle n'aurait donc
 * aucun sens ici -- on ne mesure pas la justesse d'une somme en la cachant. Ce
 * qui remplace le test aveugle, c'est la reconciliation : un total qui ne se
 * referme pas est une faute, pas une approximation.
 *
 * Huit controles, et chacun fait ECHOUER la commande. Un cockpit dont les
 * totaux ne bouclent pas ne doit pas etre publiable, et il ne l'est pas.
 */
#[AsCommand(
    name: 'app:pilotage:reconcilier',
    description: 'Verifie que tous les totaux du cockpit se referment. Echoue en cas d\'ecart.',
)]
final class ReconcilierCommand extends Command
{
    /** Un centime de tolerance : les arrondis d'affichage, rien de plus. */
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly Connection $cnx,
        private readonly Cockpit $cockpit,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Reconciliation du cockpit');

        $controles = [];
        $echecs = [];

        $noter = function (string $nom, float $attendu, float $obtenu, string $sens) use (&$controles, &$echecs): void {
            $ecart = round(abs($attendu - $obtenu), 2);
            $ok = $ecart <= self::TOLERANCE;
            $controles[] = [$nom, $this->eur($attendu), $this->eur($obtenu), $this->eur($ecart), $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                $echecs[] = sprintf('%s : ecart de %s. %s', $nom, $this->eur($ecart), $sens);
            }
        };

        // ---- 1. la somme des etablissements fait le groupe
        $groupe = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(montant), 0) FROM affectation.facture WHERE statut <> 'soldee'");
        $parEtab = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(montant), 0) FROM affectation.facture
              WHERE statut <> 'soldee' AND etablissement_id IS NOT NULL");
        $noter('Somme des etablissements = groupe', $groupe, $parEtab,
            'Des factures ouvertes ne sont rattachees a aucun etablissement : elles disparaitraient de la vue multi-sites.');

        // ---- 2. la somme des societes fait le groupe
        $parSociete = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(s.montant), 0) FROM (
                SELECT societe_id, sum(montant) AS montant FROM affectation.facture
                 WHERE statut <> 'soldee' GROUP BY 1) s");
        $noter('Somme des societes = groupe', $groupe, $parSociete,
            'Une societe manque a la consolidation.');

        // ---- 3. la somme des tranches d'anciennete fait le total
        $tranches = $this->cockpit->anciennete();
        $sommeTranches = array_sum(array_map(static fn (array $t): float => $t['montant'], $tranches));
        $noter("Somme des tranches d'anciennete = encours brut", $groupe, $sommeTranches,
            'Les bornes des tranches se chevauchent ou laissent un trou.');

        // ---- 4. l'encours comptable = la somme des creances ouvertes recensees
        $causes = (float) $this->cnx->fetchOne(
            'SELECT coalesce(sum(montant), 0) FROM pilotage.cause_ouverture');
        $noter('Encours comptable = creances ouvertes recensees', $groupe, $causes,
            "Une creance ouverte n'a pas de cause d'ouverture, ou l'inverse : le cockpit et le "
            .'poste client ne parleraient pas du meme perimetre.');

        // ---- 5. encours brut moins retraitements = encours retraite
        $encours = $this->cockpit->encours();
        $noter('Encours brut - retraitements = encours retraite',
            $encours['retraite'], max(0.0, $encours['brut'] - $encours['total_retraitements']),
            "La composition de l'ecart ne referme pas la difference entre les deux lectures.");

        // ---- 6. la somme des retraitements fait l'ecart affiche
        $noter('Somme des retraitements = ecart affiche',
            $encours['ecart'], array_sum($encours['retraitements']),
            "Un retraitement est compte dans l'ecart sans figurer dans sa composition.");

        // ---- 7. aucune facture comptee deux fois dans les causes d'ouverture
        $doublons = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM (SELECT facture_id FROM pilotage.cause_ouverture
                GROUP BY 1 HAVING count(*) > 1) d');
        $controles[] = ['Aucune facture comptee deux fois', '0', (string) $doublons,
            (string) $doublons, 0 === $doublons ? 'OK' : 'ECHEC'];
        if (0 !== $doublons) {
            $echecs[] = sprintf('%d facture(s) portent deux causes d\'ouverture : elles seraient comptees deux fois.', $doublons);
        }

        // ---- 8. aucun credit compte a la fois comme cash et comme exposition
        //
        // C'est le controle le plus important du lot, et il porte la signature
        // de la suite. Le cash encaisse, l'encours fiabilise et l'exposition a
        // traiter sont trois grandeurs de nature differente. Les additionner
        // reviendrait a compter deux fois un argent deja recu.
        $cash = $encours['natures']['cash'];
        $fiabilise = $encours['natures']['fiabilise'];
        $exposition = $encours['natures']['exposition'];
        $chevauchement = (float) $this->cnx->fetchOne(
            "SELECT coalesce(sum(v.montant), 0)
               FROM affectation.decision d
               JOIN affectation.virement v ON v.id = d.virement_id
               JOIN pilotage.cause_ouverture c ON c.client_id = d.client_propose
              WHERE d.decision = 'automatique' AND c.cause = 'reellement_due'
                AND c.facture_id = ANY(string_to_array(d.factures, '|'))
                AND NOT c.soldee_par_suite");
        $controles[] = ['Aucun credit compte comme cash ET comme exposition', '0',
            $this->eur($chevauchement), $this->eur($chevauchement), $chevauchement <= self::TOLERANCE ? 'OK' : 'ECHEC'];
        if ($chevauchement > self::TOLERANCE) {
            $echecs[] = sprintf('%s figurent a la fois dans le cash encaisse et dans l\'exposition a traiter.',
                $this->eur($chevauchement));
        }

        $io->table(['Controle', 'Attendu', 'Obtenu', 'Ecart', 'Verdict'], $controles);

        // ---- les trois natures, cote a cote, jamais additionnees
        $io->section('Les trois natures, qui ne s\'additionnent pas');
        $io->table(['Nature', 'Montant', 'Ce que c\'est'], [
            ['Cash encaisse', $this->eur($cash), Conventions::NATURES['cash']['definition']],
            ['Encours fiabilise', $this->eur($fiabilise), Conventions::NATURES['fiabilise']['definition']],
            ['Exposition a traiter', $this->eur($exposition), Conventions::NATURES['exposition']['definition']],
        ]);
        $io->text(sprintf(
            'Leur somme vaudrait %s, et ce nombre ne veut RIEN dire : il compterait deux fois '
            ."l'argent deja recu. Le cockpit ne l'affiche jamais.", $this->eur($cash + $fiabilise + $exposition)));

        if ([] !== $echecs) {
            $io->error($echecs);

            return Command::FAILURE;
        }

        $io->success('Les huit controles de reconciliation passent. Tous les totaux se referment.');

        return Command::SUCCESS;
    }

    private function eur(float $v): string
    {
        return number_format($v, 2, ',', ' ').' €';
    }
}
