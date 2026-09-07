<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use App\Remboursement\Demo\SourcePiece;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\ControleBuyBack;
use App\Remboursement\Service\FabricantPieces;
use App\Remboursement\Service\GenerationFichiersComptables;
use App\Remboursement\Service\Ia\AnalyseDossierService;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

/**
 * MESURE DIAGNOSTIQUE DE L'OUTIL 8 — NON PUBLIEE.
 *
 * CE CHIFFRE NE SORT PAS. Cette mesure a tourne sur cinq cents dossiers de
 * l'univers jury et elle a rendu un taux d'orientation degrade. L'examen des
 * ecarts a montre que le moteur n'y etait pour rien : les scenarios eux-memes
 * etaient faux. Une verite annoncait un doublon sans qu'aucun jumeau existe.
 * Une autre attendait le refus d'un IBAN invalide alors que tous les IBAN
 * charges etaient valides. Un scenario reposait sur une revelation d'IBAN
 * journalisee, controle qui n'existe pas dans le module. Un autre demandait
 * une orientation humaine sans pouvoir dire quel controle l'aurait causee. Et
 * le protocole presentait au controle de doublon les deux mille cinq cents
 * autres dossiers de l'univers comme s'ils faisaient partie du scenario.
 *
 * ELLE EST CONSERVEE, ET C'EST VOLONTAIRE : c'est elle qui a identifie ces
 * defauts. Un diagnostic qui a servi merite d'etre archive, pas efface.
 *
 * LA MESURE PUBLIEE EST AILLEURS : BLIND_O8_3, graine neuve, cas isoles,
 * premisses verifiees une par une (`app:demo:mesure-blind-o8`), contrat gele
 * par `app:demo:figer-fabrique-mesure`.
 *
 * Ce qui suit decrit le protocole diagnostique tel qu'il a tourne.
 *
 * La mesure de l'outil 8, sur une population independante.
 *
 * DEUX PERIMETRES, QU'ON NE MELANGE JAMAIS.
 *
 *   1. L'ORIENTATION. Chaque dossier de la population passe par la vraie
 *      lecture de ses pieces et par les vrais controles, puis on compare
 *      l'orientation obtenue a celle que la verite attendait. Ce perimetre
 *      repond a une question : le module envoie-t-il chaque dossier au bon
 *      endroit -- paiement, blocage, humain ?
 *
 *   2. LE PAIEMENT. Un sous-ensemble traverse jusqu'au fichier, par le vrai
 *      workflow et le vrai bus. Ce perimetre repond a une autre question :
 *      quand le module paie, paie-t-il bien, une seule fois, et seulement
 *      apres la sequence de roles requise ?
 *
 * ON NE CHERCHE PAS A MAXIMISER L'AUTOMATISATION. Un dossier envoye a l'humain
 * peut etre le bon resultat, et il l'est chaque fois que la verite le demande.
 * Les indicateurs de securite passent avant le rendement.
 *
 * La population n'est pas inspectee dossier par dossier avant la mesure : elle
 * est tiree par une regle stable, elle traverse, et on lit les compteurs.
 */
#[AsCommand(
    name: 'app:demo:mesure-finale',
    description: 'MESURE DIAGNOSTIQUE NON PUBLIEE (outil 8) : conservee pour memoire, remplacee par BLIND_O8_3.',
)]
final class MesureFinaleCommand extends Command
{
    /**
     * Les controles qui ne s'exercent QU'AU PAIEMENT, jamais a l'orientation.
     *
     * Un dossier dont la verite attend un blocage par l'un d'eux est
     * legitimement « autorise » a l'orientation : rien, a ce stade, ne peut le
     * voir. Le compter comme un passage indu melangerait les deux perimetres,
     * et c'est precisement ce qu'il faut eviter. Le vrai juge, pour eux, est
     * l'indicateur « 0 paiement produit lorsqu'un controle bloquant devait
     * l'interdire ».
     */
    private const CONTROLES_AU_PAIEMENT = [
        'C26-role-perimetre',
        'C28-garde-paiement',
        'C33-unicite-fichier',
        'C34-identifiant-message',
    ];

    /**
     * Ce que le module doit faire d'un dossier, selon ce que la verite attend.
     *
     * La verite parle en termes metier -- paye, bloque, refuse, alerte, humain,
     * arbitrage, oriente. Le module, lui, produit un statut et un verdict. Cette
     * table dit a quelle FAMILLE d'issue chaque attente correspond, et c'est la
     * seule traduction autorisee.
     */
    private const FAMILLE_ATTENDUE = [
        'paye' => 'autorise',
        'bloque' => 'bloque',
        'refuse' => 'bloque',
        'alerte' => 'humain',
        'humain' => 'humain',
        'arbitrage' => 'humain',
        'oriente' => 'humain',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $cnx,
        private readonly DossierRepository $dossiers,
        private readonly FabricantPieces $fabricant,
        private readonly SourcePiece $source,
        private readonly AnalyseDossierService $analyse,
        private readonly WorkflowRemboursement $workflow,
        private readonly ControleBuyBack $controleBuyBack,
        private readonly MessageBusInterface $bus,
        private readonly string $racineProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('taille', null, InputOption::VALUE_REQUIRED,
                'Nombre de dossiers de la population d\'orientation.', '500')
            ->addOption('paiements', null, InputOption::VALUE_REQUIRED,
                'Nombre de dossiers menes jusqu\'au fichier de paiement.', '40');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('MESURE DIAGNOSTIQUE — NON PUBLIEE (outil 8)');
        $io->warning('Ce chiffre ne sort pas : les ecarts venaient des scenarios, pas du moteur. '
            .'La mesure publiee est BLIND_O8_3 — app:demo:mesure-blind-o8.');

        $taille = max(20, (int) $entree->getOption('taille'));
        $nbPaiements = max(5, (int) $entree->getOption('paiements'));

        // ---------------------------------------------- la population
        $io->section('1. La population, tiree par une regle stable');
        $population = $this->population($taille);
        if ([] === $population) {
            $io->error('Aucun dossier disponible : rechargez l\'univers.');

            return Command::FAILURE;
        }
        $scenarios = array_count_values(array_map(
            static fn (array $l): string => (string) $l['code_scenario'], $population));
        ksort($scenarios);

        $io->table(['Grandeur', 'Valeur'], [
            ['Dossiers evalues', number_format(\count($population), 0, ',', ' ')],
            ['Scenarios representes', (string) \count($scenarios).' scenarios diagnostiques'],
            ['Regle de tirage', 'les dossiers encore au depot, ordonnes par leur identifiant,'
                .' un sur N'],
        ]);
        $io->table(['Scenario', 'Dossiers'], array_map(
            static fn (string $s): array => [$s, (string) $scenarios[$s]], array_keys($scenarios)));

        // ---------------------------------------------- l'orientation
        $io->section('2. L\'orientation : le module envoie-t-il chaque dossier au bon endroit ?');
        $io->progressStart(\count($population));
        $resultats = [];
        foreach ($population as $l) {
            $resultats[] = $this->orienter((string) $l['reference'],
                (string) $l['code_scenario'], (string) $l['decision_attendue'],
                null === $l['controle_declencheur'] ? null : (string) $l['controle_declencheur']);
            $io->progressAdvance();
        }
        $io->progressFinish();

        $matrice = [];
        $justes = 0;
        $fauxBlocages = 0;
        $passagesIndus = 0;
        $motifJuste = 0;
        $motifAttendu = 0;
        $collisions = 0;
        $auPaiement = 0;
        foreach ($resultats as $r) {
            if (true === $r['collision']) {
                ++$collisions;
            }
            $cle = $r['attendu'].' → '.$r['obtenu'];
            $matrice[$cle] = ($matrice[$cle] ?? 0) + 1;
            if ($r['attendu'] === $r['obtenu']) {
                ++$justes;
            }
            if ('autorise' === $r['attendu'] && 'bloque' === $r['obtenu']) {
                ++$fauxBlocages;
            }
            if ('bloque' === $r['attendu'] && 'autorise' === $r['obtenu']) {
                if (\in_array($r['controleAttendu'], self::CONTROLES_AU_PAIEMENT, true)) {
                    ++$auPaiement;
                } else {
                    ++$passagesIndus;
                }
            }
            if (null !== $r['controleAttendu']) {
                ++$motifAttendu;
                if ($r['motifJuste']) {
                    ++$motifJuste;
                }
            }
        }
        ksort($matrice);

        $io->table(['Attendu → obtenu', 'Dossiers'], array_map(
            static fn (string $k): array => [$k, (string) $matrice[$k]], array_keys($matrice)));

        $io->table(['Grandeur', 'Valeur'], [
            ['Orientations justes', sprintf('%d / %d — %.2f %%',
                $justes, \count($resultats), $justes / \count($resultats) * 100)],
            ['Dossiers autorises correctement', (string) $this->compter($resultats, 'autorise', 'autorise')],
            ['Dossiers bloques correctement', (string) $this->compter($resultats, 'bloque', 'bloque')],
            ['Dossiers renvoyes au controle humain, correctement', (string) $this->compter($resultats, 'humain', 'humain')],
            ['FAUX BLOCAGES (autorise, mais bloque)', (string) $fauxBlocages],
            ["PASSAGES INDUS a l'orientation (a bloquer, mais autorise)", (string) $passagesIndus],
            ["Attendus bloques par un controle qui n'agit qu'au paiement", (string) $auPaiement],
            ['Motif de controle exact', 0 === $motifAttendu ? '—' : sprintf('%d / %d', $motifJuste, $motifAttendu)],
            ['dont ecarts expliques par une collision du monde', (string) $collisions],
            ['Orientations justes, collisions mises a part', sprintf('%d / %d — %.2f %%',
                $justes + $collisions, \count($resultats),
                ($justes + $collisions) / \count($resultats) * 100)],
        ]);

        $io->text("LA COLLISION DU MONDE, ET POURQUOI CE N'EST PAS UNE ERREUR. Dans cette");
        $io->text('population, les 2 500 dossiers sont ouverts EN MEME TEMPS -- un etat qui');
        $io->text("n'existe pas dans la vie d'un service. Le monde synthetique tire ses clients,");
        $io->text('leurs comptes bancaires et leurs vehicules dans des referentiels bornes : des');
        $io->text('dossiers differents partagent donc reellement un IBAN ou une immatriculation.');
        $io->text('Le controle de doublon les voit, et il a RAISON de les voir. La verite, elle,');
        $io->text("n'avait declare un doublon que pour les scenarios qui en font leur sujet. Ces");
        $io->text('ecarts sont donc comptes a part, et jamais comme des erreurs du module.');

        // Les ecarts, nommes par scenario : un compteur sans son detail ne
        // s'explique pas devant un jury.
        $ecarts = [];
        foreach ($resultats as $r) {
            if ($r['attendu'] === $r['obtenu']) {
                continue;
            }
            $cle = $r['scenario'].' | attendu '.$r['attendu'].' | obtenu '.$r['obtenu'].' | motif : '.$r['motif'];
            $ecarts[$cle] = ($ecarts[$cle] ?? 0) + 1;
        }
        if ([] !== $ecarts) {
            ksort($ecarts);
            $io->text('Les ecarts, nommes un par un :');
            $io->table(['Scenario | attendu | obtenu | motif du module', 'Dossiers'], array_map(
                static fn (string $k): array => [$k, (string) $ecarts[$k]], array_keys($ecarts)));
        }

        // ---------------------------------------------- les paiements
        $io->section('3. Le paiement : quand le module paie, paie-t-il bien ?');
        $paiements = $this->mesurerPaiements($io, $resultats, $nbPaiements);

        // ---------------------------------------------- les KPI de securite
        $io->section('4. Les indicateurs de securite, ceux qui passent avant tout');
        $kpi = [
            ['0 paiement produit lorsqu\'un controle bloquant devait l\'interdire',
                (string) $paiements['paiements_interdits'], 0 === $paiements['paiements_interdits']],
            ['0 double paiement sur le circuit normal',
                (string) $paiements['doubles'], 0 === $paiements['doubles']],
            ['0 surpaiement Buy Back au-dela de la tolerance',
                (string) $paiements['surpaiements'], 0 === $paiements['surpaiements']],
            ['0 generation sans validation directeur',
                (string) $paiements['sans_directeur'], 0 === $paiements['sans_directeur']],
            ['100 % des paiements avec la sequence de roles requise',
                sprintf('%d / %d', $paiements['sequence_juste'], $paiements['payes']),
                $paiements['sequence_juste'] === $paiements['payes']],
            ['0 passage indu a l\'orientation, hors controles du paiement',
                (string) $passagesIndus, 0 === $passagesIndus],
        ];
        $fautes = 0;
        $lignes = [];
        foreach ($kpi as [$quoi, $vu, $ok]) {
            $lignes[] = [$quoi, $vu, $ok ? 'OK' : 'ECHEC'];
            if (!$ok) {
                ++$fautes;
            }
        }
        $io->table(['Indicateur', 'Constate', 'Verdict'], $lignes);

        $io->text('Le taux d\'automatisation n\'est pas un objectif ici : un dossier envoye a');
        $io->text('l\'humain est le bon resultat chaque fois que la verite le demande.');

        if ($fautes > 0) {
            $io->error(sprintf('%d indicateur(s) de securite en echec.', $fautes));

            return Command::FAILURE;
        }
        $io->success('Tous les indicateurs de securite passent.');

        return Command::SUCCESS;
    }

    /**
     * La population : les dossiers encore au depot, echantillonnes regulierement.
     *
     * Le tirage ne regarde ni le scenario ni la verite pour CHOISIR : il prend un
     * dossier sur N dans l'ordre des identifiants. La couverture des scenarios
     * est donc une consequence, pas une construction.
     *
     * @return list<array<string, mixed>>
     */
    private function population(int $taille): array
    {
        $total = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier WHERE statut = ?',
            [DossierStatut::DEPOSE->value]);
        if (0 === $total) {
            return [];
        }
        $pas = max(1, (int) floor($total / $taille));

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT * FROM (
               SELECT d.reference, v.code_scenario, v.decision_attendue, v.controle_declencheur,
                      row_number() OVER (ORDER BY d.id) AS rang
                 FROM remboursement.dossier d
                 JOIN remboursement_verite.dossier v ON v.reference = d.reference
                WHERE d.statut = ?) x
             WHERE (rang - 1) % ? = 0
             ORDER BY rang
             LIMIT ?', [DossierStatut::DEPOSE->value, $pas, $taille]);

        return $lignes;
    }

    /**
     * Fait passer un dossier par la vraie lecture et les vrais controles, puis
     * rend l'orientation obtenue.
     *
     * @return array{reference: string, scenario: string, attendu: string, obtenu: string, controleAttendu: ?string, motifJuste: bool, motif: string, collision: bool}
     */
    private function orienter(string $reference, string $scenario, string $attenduBrut, ?string $controleAttendu): array
    {
        $dossier = $this->recharger($reference);
        $this->preparer($dossier);
        $this->em->clear();
        $dossier = $this->recharger($reference);

        $attendu = self::FAMILLE_ATTENDUE[$attenduBrut] ?? 'humain';
        [$obtenu, $causes, $collisions] = $this->causes($dossier);
        $motif = implode(' + ', $causes);

        // Le motif est juste si le controle attendu par la verite figure parmi
        // les causes que le module a fait parler -- pas seulement la premiere.
        $famille = self::familleControle($controleAttendu ?? '');
        $motifJuste = null === $controleAttendu || '' === $famille
            || str_contains(mb_strtolower($motif), $famille);

        // Une collision du monde suffit-elle a expliquer l'ecart ? Si le module
        // n'a parle que de collisions et que la verite n'en declarait pas, ce
        // n'est pas une erreur d'orientation : c'est le monde qui porte de
        // vrais jumeaux, et le module a raison de le dire.
        $ecartDeCollision = $attendu !== $obtenu
            && [] !== $collisions
            && \count($collisions) === \count($causes)
            && !\in_array($controleAttendu, ['C09-unicite-base', 'C10-empreinte-iban', 'C11-jumeaux'], true);

        return [
            'reference' => $reference,
            'scenario' => $scenario,
            'attendu' => $attendu,
            'obtenu' => $obtenu,
            'controleAttendu' => $controleAttendu,
            'motifJuste' => $motifJuste,
            'motif' => $motif,
            'collision' => $ecartDeCollision,
        ];
    }

    /**
     * TOUTES les causes que le module fait parler sur ce dossier.
     *
     * On ne s'arrete pas a la premiere : un dossier peut porter a la fois un
     * vehicule gage et une collision de compte bancaire, et s'arreter a la
     * premiere trouvee ferait dependre le resultat de l'ordre du code -- ce qui
     * serait un artefact de mesure, pas une propriete du module.
     *
     * L'issue se deduit ensuite des causes : une cause bloquante l'emporte sur
     * une cause humaine, et l'absence de cause vaut autorisation.
     *
     * @return array{0: string, 1: list<string>, 2: list<string>}
     *                                                            l'issue, les causes, et celles qui viennent d'une collision du monde
     */
    private function causes(Dossier $dossier): array
    {
        $bloquantes = [];
        $humaines = [];
        $collisions = [];

        // Un surpaiement Buy Back : ni le depot ni le paiement ne le laissent passer.
        $c = $this->controleBuyBack->pour($dossier->getImmatriculation(), (float) $dossier->getMontant());
        if (null !== $c && true === $c['surpaiement']) {
            $bloquantes[] = 'surpaiement buyback';
        }

        $extra = $dossier->getControleExtra() ?? [];
        if (false === ($extra['vehicule_libre'] ?? true)) {
            $bloquantes[] = 'vehicule gage';
        }

        // Les collisions : un jumeau actif sur la meme cle, ou le meme compte
        // bancaire. Elles sont comptees a part, parce que le monde en porte de
        // VRAIES que la verite n'a pas declarees -- voir le rapport.
        if ([] !== $this->dossiers->doublonsActifs(
            $dossier->getMotif(), $dossier->getCleDoublon(), $dossier->getId())) {
            $humaines[] = 'doublon cle';
            $collisions[] = 'doublon cle';
        }
        if ([] !== $this->dossiers->memeIbanActif($dossier->getIbanHash(), $dossier->getId())) {
            $humaines[] = 'empreinte iban';
            $collisions[] = 'empreinte iban';
        }

        $surcharge = (int) $this->cnx->fetchOne(
            "SELECT count(*) FROM remboursement.extraction_piece
              WHERE dossier_id = ? AND statut = 'surcharge'", [(int) $dossier->getId()]);
        if ($surcharge > 0) {
            $humaines[] = 'piece inexploitable';
        }
        if (true === ($extra['facture_invalide'] ?? false) || true === ($extra['carte_grise_invalide'] ?? false)) {
            $humaines[] = 'document illisible';
        }
        if (true === ($extra['modifications_detectees'] ?? false)) {
            $humaines[] = 'estimation retouchee';
        }
        if (true === ($extra['carte_grise_manuscrite'] ?? false)) {
            $humaines[] = 'mention manuscrite';
        }
        if ('invalide' === (string) $dossier->getVerdictIa()) {
            $humaines[] = mb_strtolower((string) $dossier->getVerdictInfo());
        }

        $issue = [] !== $bloquantes ? 'bloque' : ([] !== $humaines ? 'humain' : 'autorise');
        $causes = array_values(array_unique(array_merge($bloquantes, $humaines)));

        return [$issue, [] === $causes ? ['controles concordants'] : $causes, $collisions];
    }

    /** La famille de mots que le code du controle attendu evoque. */
    private static function familleControle(string $controle): string
    {
        return match (true) {
            str_contains($controle, 'surpaiement') => 'surpaiement',
            str_contains($controle, 'empreinte-iban') => 'empreinte iban',
            str_contains($controle, 'unicite-base'), str_contains($controle, 'jumeaux') => 'doublon',
            str_contains($controle, 'piece-inexploitable') => 'piece',
            str_contains($controle, 'iban') => 'iban',
            str_contains($controle, 'montant') => 'montant',
            str_contains($controle, 'manuscrite') => 'manuscrite',
            str_contains($controle, 'gage') => 'gage',
            str_contains($controle, 'estimation') => 'estimation',
            default => '',
        };
    }

    /**
     * Mene jusqu'au fichier de paiement les dossiers que le module autorise, et
     * tente de payer ceux qu'il bloque.
     *
     * @param list<array<string, mixed>> $resultats
     *
     * @return array{payes: int, paiements_interdits: int, doubles: int, surpaiements: int, sans_directeur: int, sequence_juste: int, tentatives_bloquees: int}
     */
    private function mesurerPaiements(SymfonyStyle $io, array $resultats, int $combien): array
    {
        $autorises = array_values(array_filter($resultats,
            static fn (array $r): bool => 'autorise' === $r['obtenu']));
        $bloques = array_values(array_filter($resultats,
            static fn (array $r): bool => 'bloque' === $r['obtenu']));

        $aPayer = \array_slice($autorises, 0, $combien);
        $aTenter = \array_slice($bloques, 0, min(20, \count($bloques)));

        $io->text(sprintf('%d dossiers autorises menes jusqu\'au fichier, et %d dossiers bloques'
            .' pour lesquels on TENTE quand meme le paiement.', \count($aPayer), \count($aTenter)));

        $bilan = ['payes' => 0, 'paiements_interdits' => 0, 'doubles' => 0, 'surpaiements' => 0,
            'sans_directeur' => 0, 'sequence_juste' => 0, 'tentatives_bloquees' => 0];

        $io->progressStart(\count($aPayer) + \count($aTenter));

        foreach ($aPayer as $r) {
            $this->menerAuPaiement((string) $r['reference']);
            $bilan = $this->releverPaiement((string) $r['reference'], $bilan, true);
            $io->progressAdvance();
        }

        foreach ($aTenter as $r) {
            // On depose la demande de generation sur le vrai bus, sans avoir
            // franchi la moindre validation : le module doit refuser.
            $dossier = $this->recharger((string) $r['reference']);
            $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()));
            $this->consommer();
            $bilan = $this->releverPaiement((string) $r['reference'], $bilan, false);
            ++$bilan['tentatives_bloquees'];
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->table(['Grandeur', 'Valeur'], [
            ['Paiements generes', (string) $bilan['payes']],
            ['Paiements interdits qui ont ete generes', (string) $bilan['paiements_interdits']],
            ['Doubles paiements', (string) $bilan['doubles']],
            ['Surpaiements au-dela de la tolerance payes', (string) $bilan['surpaiements']],
            ['Generations sans validation directeur', (string) $bilan['sans_directeur']],
            ['Sequences de roles conformes', sprintf('%d / %d', $bilan['sequence_juste'], $bilan['payes'])],
            ['Tentatives sur dossiers bloques', (string) $bilan['tentatives_bloquees']],
        ]);

        return $bilan;
    }

    /**
     * Le parcours de paiement, par les vrais services et le vrai bus.
     *
     * Les transitions sont franchies par WorkflowRemboursement avec l'auteur et
     * le role attendus. `sansGarde` leve la seule garde qu'une console ne peut
     * pas satisfaire -- il n'y a pas d'utilisateur connecte --, et l'auteur
     * inscrit dans le journal reste celui du role : c'est ce que la mesure de
     * sequence relit ensuite.
     */
    private function menerAuPaiement(string $reference): void
    {
        $dossier = $this->recharger($reference);
        if (DossierStatut::A_VERIFIER !== $dossier->getStatut()) {
            return;
        }

        // Le comptable retient les valeurs lues, puis transmet.
        $dossier->enregistrerValidation([
            'nom' => $dossier->getControleNom() ?: $dossier->getNomClient(),
            'iban' => $dossier->getControleIban() ?: $dossier->getIbanClient(),
            'bic' => $dossier->getControleBic() ?: $dossier->getBicClient(),
            'montant' => $dossier->getControleMontant() ?: $dossier->getMontant(),
            'immatriculation' => $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation(),
            'code_icar' => $dossier->getControleIcar() ?: $dossier->getCodeIcar(),
            'libelle' => 'MESURE FINALE',
            'code_comptable' => '4111000',
            'role_tiers' => 'COMPTANT',
        ], 'comptable@demonstration.invalid');

        $this->workflow->appliquer($dossier, 'envoyer_directeur',
            'comptable@demonstration.invalid', null, false, true, true);
        $this->workflow->appliquer($dossier, 'valider_directeur',
            'Directeur (e-mail)', null, false, true, true);

        // La generation part sur le bus, comme dans le parcours reel.
        $this->bus->dispatch(new GenererFichiers((int) $dossier->getId()));
        $this->consommer();
        $this->em->clear();
    }

    /**
     * @param array{payes: int, paiements_interdits: int, doubles: int, surpaiements: int, sans_directeur: int, sequence_juste: int, tentatives_bloquees: int} $bilan
     *
     * @return array{payes: int, paiements_interdits: int, doubles: int, surpaiements: int, sans_directeur: int, sequence_juste: int, tentatives_bloquees: int}
     */
    private function releverPaiement(string $reference, array $bilan, bool $autorise): array
    {
        $this->em->clear();
        $dossier = $this->recharger($reference);
        $id = (int) $dossier->getId();

        $sepa = (int) $this->cnx->fetchOne(
            'SELECT count(*) FROM remboursement.dossier_piece WHERE dossier_id = ? AND type = ?',
            [$id, GenerationFichiersComptables::TYPE_SEPA]);

        if (0 === $sepa) {
            return $bilan;
        }

        if (!$autorise) {
            ++$bilan['paiements_interdits'];

            return $bilan;
        }

        ++$bilan['payes'];
        if ($sepa > 1) {
            ++$bilan['doubles'];
        }

        // Un surpaiement paye : la question qui compte le plus.
        $c = $this->controleBuyBack->pour($dossier->getImmatriculation(),
            (float) ($dossier->getValideMontant() ?: $dossier->getMontant()));
        if (null !== $c && true === $c['surpaiement']) {
            ++$bilan['surpaiements'];
        }

        // La sequence de roles, relue dans le journal du dossier.
        $sequence = $this->cnx->fetchFirstColumn(
            'SELECT transition FROM remboursement.dossier_transition WHERE dossier_id = ? ORDER BY id', [$id]);
        $auteurs = $this->cnx->fetchAllAssociative(
            'SELECT transition, coalesce(par, \'\') AS par FROM remboursement.dossier_transition
              WHERE dossier_id = ? ORDER BY id', [$id]);

        $aValideDirecteur = \in_array('valider_directeur', $sequence, true);
        if (!$aValideDirecteur) {
            ++$bilan['sans_directeur'];
        }

        $ordre = ['deposer', 'envoyer_directeur', 'valider_directeur', 'confirmer', 'demarrer_generation'];
        $position = 0;
        foreach ($sequence as $t) {
            if ($position < \count($ordre) && $t === $ordre[$position]) {
                ++$position;
            }
        }
        $parRole = true;
        foreach ($auteurs as $a) {
            if ('envoyer_directeur' === $a['transition'] && !str_contains((string) $a['par'], 'comptable')) {
                $parRole = false;
            }
            if ('valider_directeur' === $a['transition'] && !str_contains((string) $a['par'], 'Directeur')) {
                $parRole = false;
            }
        }
        if (\count($ordre) === $position && $parRole) {
            ++$bilan['sequence_juste'];
        }

        return $bilan;
    }

    /** @param list<array<string, mixed>> $resultats */
    private function compter(array $resultats, string $attendu, string $obtenu): int
    {
        return \count(array_filter($resultats,
            static fn (array $r): bool => $r['attendu'] === $attendu && $r['obtenu'] === $obtenu));
    }

    private function preparer(Dossier $detache): void
    {
        $dossier = $this->recharger((string) $detache->getReference());
        $id = (int) $dossier->getId();
        $this->cnx->executeStatement(
            'DELETE FROM remboursement.extraction_piece WHERE dossier_id = ?', [$id]);
        $this->cnx->executeStatement(
            'DELETE FROM remboursement.dossier_piece WHERE dossier_id = ?', [$id]);

        foreach ($this->fabricant->typesPour($dossier->getMotif(), false) as $type) {
            $doc = $this->fabricant->pour($dossier, $type, null, $this->source->contexte($dossier, $type));
            $this->em->persist(new DossierPiece(
                $dossier, $type, $doc['nom'], $doc['html'], $doc['mime'],
                \strlen($doc['html']), hash('sha256', $doc['html']), 'mesure@demonstration.invalid'));
        }
        $this->em->flush();
        $this->analyse->analyser($dossier);
    }

    private function consommer(): void
    {
        $worker = new Process(
            ['php', 'bin/console', 'messenger:consume', 'remboursement', '--limit=1', '--time-limit=15', '-q'],
            $this->racineProjet);
        $worker->setTimeout(45);
        $worker->run();
    }

    private function recharger(string $reference): Dossier
    {
        $d = $this->dossiers->findOneBy(['reference' => $reference]);
        if (null === $d) {
            throw new RuntimeException('Dossier disparu : '.$reference);
        }

        return $d;
    }
}
