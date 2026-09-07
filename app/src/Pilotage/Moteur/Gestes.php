<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les gestes ouverts depuis le poste de travail, et ce qu'ils font vraiment.
 *
 * L'outil 6 ORCHESTRE le travail : il dit quoi traiter, dans quel ordre, et
 * il envoie au module qui execute. Il ne duplique aucun moteur, et il
 * n'inscrit jamais une decision metier qu'il n'a pas realisee. Un journal qui
 * dirait « affecte » alors que rien n'a ete affecte serait pire qu'un journal
 * vide : il ferait croire au controle qu'un travail a eu lieu.
 *
 * Quatre natures, et la distinction est la doctrine :
 *
 *   EXECUTION      le module cible existe. Le geste y ouvre l'objet EXACT,
 *                  et c'est la-bas que la decision est prise et journalisee.
 *                  Affecter -> outil 4 sur ce virement. Lettrer -> outil 5
 *                  sur ce lot.
 *   PREPAREE       le module cible n'existe pas encore. Le lien est en place
 *                  et le dit : rien n'est simule, rien n'est journalise comme
 *                  fait. Demander une piece (outil 7), passer en comite
 *                  (outil 9), relancer (outil 10).
 *   NAVIGATION     regarder. Tracable comme consultation, jamais comme
 *                  decision.
 *   ANNOTATION     la note. C'est le seul acte propre a l'outil 6, et il est
 *                  ecrit ici parce que c'est ici qu'il a lieu.
 */
final class Gestes
{
    public const EXECUTION = 'execution';
    public const PREPAREE = 'preparee';
    public const NAVIGATION = 'navigation';
    public const ANNOTATION = 'annotation';

    /**
     * Le catalogue.
     *
     * @var array<string, array{
     *     libelle: string, effet: string, nature: string, module?: string,
     *     teinte: string, niveaux?: list<string>
     * }>
     */
    public const CATALOGUE = [
        // ------------------------------------------------------- executions
        'affecter' => [
            'libelle' => "Affecter dans l'outil 4",
            'effet' => "Ouvre CE virement dans l'outil 4 : les candidats, les signaux, la marge, "
                ."et le bouton qui affecte. La decision est prise et journalisee la-bas — l'outil 6 "
                .'ne duplique pas le moteur.',
            'nature' => self::EXECUTION, 'module' => 'outil-4', 'teinte' => 'positive',
        ],
        'lettrer' => [
            'libelle' => "Lettrer dans l'outil 5",
            'effet' => "Ouvre CE lot dans l'outil 5 : la cascade des 22 methodes, les indices "
                .'retenus, le solde du groupe. Le lettrage est execute et journalise la-bas.',
            'nature' => self::EXECUTION, 'module' => 'outil-5', 'teinte' => 'positive',
        ],
        'ouvrir_compte_outil_5' => [
            'libelle' => "Ouvrir ce compte dans l'outil 5",
            'effet' => "Cette ligne n'appartient a aucun lot : l'outil 5 s'ouvre sur le compte du "
                .'client, ou le rapprochement se construit.',
            'nature' => self::EXECUTION, 'module' => 'outil-5', 'teinte' => 'positive',
        ],
        'ouvrir_remboursement' => [
            'libelle' => "Ouvrir le remboursement dans l'outil 8",
            'effet' => 'Un credit qui dort sur un compte client peut etre un trop-percu, et un '
                .'trop-percu se rend. Ce geste ouvre le dossier de remboursement correspondant '
                ."dans l'outil 8 -- son parcours, ses pieces, ses controles. Si aucun dossier "
                ."n'existe encore, il ouvre le formulaire de depot. La decision et le paiement "
                .'sont pris et journalises la-bas.',
            'nature' => self::EXECUTION, 'module' => 'outil-8', 'teinte' => 'positive',
        ],
        'ouvrir_exception' => [
            'libelle' => "Traiter l'exception dans son module",
            'effet' => "Ouvre l'objet dans l'outil d'origine, a la regle d'arret qui a rendu la main.",
            'nature' => self::EXECUTION, 'teinte' => 'navy',
        ],
        // --------------------------------------------------------- preparees
        'demander_piece' => [
            'libelle' => 'Demander une pièce',
            'effet' => 'Nommera la pièce absente et adressera la demande au site. '
                ."Le dossier grand compte reste bloque jusqu'a reception.",
            'nature' => self::PREPAREE, 'module' => 'outil-7', 'teinte' => 'gold',
        ],
        'passer_en_comite' => [
            'libelle' => 'Passer en comité',
            'effet' => "Inscrira la creance a l'ordre du jour du comite de creances, avec son motif. "
                .'La decision appartient a la concession.',
            'nature' => self::PREPAREE, 'module' => 'outil-9', 'teinte' => 'gold',
        ],
        'relancer' => [
            'libelle' => 'Relancer',
            'effet' => 'Declenchera un palier de relance, adresse au bon interlocuteur. '
                .'Une creance deja payee, bloquee par une piece ou due par un financeur ne doit '
                ."jamais arriver la : c'est tout l'objet des files precedentes.",
            'nature' => self::PREPAREE, 'module' => 'outil-10', 'teinte' => 'warning',
            'niveaux' => ['N1', 'N2', 'MED'],
        ],
        // -------------------------------------------------------- navigation
        'ouvrir_dossier' => [
            'libelle' => 'Ouvrir le dossier',
            'effet' => "Identite, situation du compte, ecritures, cause d'ouverture, historique.",
            'nature' => self::NAVIGATION, 'teinte' => 'navy',
        ],
        'voir_compte' => [
            'libelle' => 'Voir le compte',
            'effet' => 'Le compte client : ses creances ouvertes, tous etablissements confondus.',
            'nature' => self::NAVIGATION, 'teinte' => 'ink',
        ],
        'voir_ecritures' => [
            'libelle' => 'Voir les écritures',
            'effet' => "Descend jusqu'aux lignes d'ecriture, debit par debit et credit par credit.",
            'nature' => self::NAVIGATION, 'teinte' => 'ink',
        ],
        // -------------------------------------------------------- annotation
        'note' => [
            'libelle' => 'Ajouter une note',
            'effet' => 'Consigne une observation datee sur le dossier, lisible par le comite et par '
                ."le prochain comptable qui l'ouvrira. C'est le seul acte que l'outil 6 ecrit "
                .'lui-meme, parce que c\'est ici qu\'il a lieu.',
            'nature' => self::ANNOTATION, 'teinte' => 'ink',
        ],
    ];

    /** Ce que chaque palier de relance signifiera, dans l'outil 10. */
    public const NIVEAUX = [
        'N1' => 'Premier rappel, ton neutre, adressé au service comptable du client.',
        'N2' => 'Second rappel, adressé au responsable du compte, avec relevé des pièces.',
        'MED' => 'Mise en demeure, avec conséquences énoncées. Passage obligé avant contentieux.',
    ];

    /** Les modules qui existent et executent reellement. */
    public const MODULES_LIVRES = ['outil-4', 'outil-5', 'outil-8'];

    /** Ce que l'outil 6 s'autorise a ecrire lui-meme. */
    private const TYPES_ECRITS = ['note', 'consultation'];

    public function __construct(
        private readonly Connection $cnx,
        private readonly Security $securite,
        private readonly UrlGeneratorInterface $routes,
    ) {
    }

    /**
     * Les gestes ouverts sur un objet, avec l'adresse reelle de chacun.
     *
     * L'ecran ne calcule aucune cible : il affiche ce que cette methode dit,
     * et le lien mene la ou le travail se fait.
     *
     * @param array<string, mixed> $objet
     *
     * @return list<array{code: string, libelle: string, effet: string, nature: string,
     *                    module: ?string, disponible: bool, url: ?string, teinte: string,
     *                    niveaux: list<string>}>
     */
    public function pour(string $objetType, array $objet, string $file = ''): array
    {
        $cause = isset($objet['cause']) ? (string) $objet['cause'] : '';
        $codes = match ($objetType) {
            'virement' => ['affecter', 'voir_compte', 'note'],
            'lot' => ['lettrer', 'voir_ecritures', 'note'],
            // Un credit de compte client : il se lettre dans l'outil 5, et s'il
            // s'agit d'un trop-percu, il se rend dans l'outil 8. Les deux gestes
            // sont offerts, et c'est le comptable qui sait lequel s'applique.
            'ecriture' => ['ouvrir_compte_outil_5', 'ouvrir_remboursement', 'voir_ecritures', 'note'],
            'compte' => ['ouvrir_compte_outil_5', 'voir_ecritures', 'note'],
            'facture' => match ($cause) {
                'piece_manquante' => ['demander_piece', 'passer_en_comite', 'voir_compte', 'note'],
                'decision_concession' => ['passer_en_comite', 'voir_compte', 'note'],
                'financeur' => ['voir_compte', 'note'],
                'exception_comptable' => ['ouvrir_compte_outil_5', 'voir_compte', 'note'],
                default => ['relancer', 'passer_en_comite', 'voir_compte', 'note'],
            },
            default => ['note'],
        };

        $out = [];
        foreach ($codes as $code) {
            $g = self::CATALOGUE[$code];
            $module = $g['module'] ?? null;
            $out[] = [
                'code' => $code,
                'libelle' => $g['libelle'],
                'effet' => $g['effet'],
                'nature' => $g['nature'],
                'module' => $module,
                'disponible' => null === $module || \in_array($module, self::MODULES_LIVRES, true),
                'url' => $this->cible($code, $objetType, $objet, $file),
                'teinte' => $g['teinte'],
                'niveaux' => $g['niveaux'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * L'adresse exacte d'un geste.
     *
     * « Ouvrir le lot exact » n'est pas une formule : quand la ligne n'est
     * rattachee a aucun lot -- un credit qui dort dans un compte, par
     * exemple -- il n'y a pas de lot a ouvrir, et l'outil 5 s'ouvre alors sur
     * le COMPTE. Le libelle du geste le dit, plutot que de promettre un objet
     * qui n'existe pas.
     *
     * @param array<string, mixed> $objet
     */
    private function cible(string $code, string $objetType, array $objet, string $file): ?string
    {
        $id = isset($objet['id']) ? (string) $objet['id'] : '';
        $client = isset($objet['client_id']) ? (string) $objet['client_id'] : '';
        $lot = isset($objet['lot_demo']) ? (string) $objet['lot_demo'] : '';
        $dossierRbc = isset($objet['rbc_dossier_id']) ? (string) $objet['rbc_dossier_id'] : '';

        return match ($code) {
            // --- l'objet exact, dans le module qui l'execute
            'affecter' => 'virement' === $objetType && '' !== $id
                ? $this->routes->generate('app_affectation_virement', ['id' => $id])
                : null,
            'lettrer' => 'lot' === $objetType && '' !== $id
                ? $this->routes->generate('app_lettrage_lot', ['id' => $id])
                : ('' !== $lot ? $this->routes->generate('app_lettrage_lot', ['id' => $lot]) : null),
            'ouvrir_compte_outil_5' => '' !== $lot
                ? $this->routes->generate('app_lettrage_lot', ['id' => $lot])
                : ('' !== $client ? $this->routes->generate('app_lettrage_file', ['q' => $client]) : null),
            // Le pont vers l'outil 8. Un dossier relie s'ouvre a sa fiche ; sans
            // dossier, c'est le formulaire de depot qui s'ouvre -- le geste
            // s'appelle « ouvrir le remboursement », il ne promet pas un dossier
            // qui n'existe pas.
            'ouvrir_remboursement' => '' !== $dossierRbc
                ? $this->routes->generate('app_remboursement_dossier', ['id' => $dossierRbc])
                : $this->routes->generate('app_remboursement_deposer_formulaire'),
            'ouvrir_exception' => match ($objetType) {
                'virement' => $this->routes->generate('app_affectation_virement', ['id' => $id]),
                'lot' => $this->routes->generate('app_lettrage_lot', ['id' => $id]),
                default => null,
            },
            // --- la navigation, dans le poste de travail
            'ouvrir_dossier' => $this->routes->generate('app_travail_dossier',
                ['type' => $objetType, 'id' => $id, 'file' => $file]),
            'voir_compte' => '' !== $client
                ? $this->routes->generate('app_travail_dossier',
                    ['type' => 'compte', 'id' => $client, 'file' => $file])
                : null,
            'voir_ecritures' => '' !== $client
                ? $this->routes->generate('app_cockpit_detail',
                    ['niveau' => 'ecriture', 'client_id' => $client])
                : null,
            // --- les modules a venir : le lien est prepare, pas actif
            default => null,
        };
    }

    /** L'outil 6 s'autorise-t-il a ecrire ce type ? */
    public function ecritLuiMeme(string $type): bool
    {
        return \in_array($type, self::TYPES_ECRITS, true);
    }

    /**
     * Inscrit une note, ou une consultation.
     *
     * Rien d'autre. Une decision metier est ecrite par le module qui la
     * realise, et l'outil 6 n'a pas a s'en attribuer la trace.
     */
    public function poser(
        string $type,
        string $objetType,
        string $objetId,
        ?string $clientId,
        ?string $etablissementId,
        ?float $montant,
        ?string $note,
        ?string $file,
        ?string $module = null,
    ): void {
        if (!$this->ecritLuiMeme($type)) {
            throw new InvalidArgumentException("L'outil 6 n'ecrit que des notes et des consultations. Type refuse : ".$type);
        }
        $utilisateur = $this->securite->getUser();

        $this->cnx->insert('pilotage.geste', [
            'type' => $type,
            'objet_type' => $objetType,
            'objet_id' => $objetId,
            'client_id' => $clientId,
            'etablissement_id' => $etablissementId,
            'montant' => $montant,
            'module' => $module,
            'note' => null !== $note && '' !== $note ? mb_substr($note, 0, 400) : null,
            'auteur' => $utilisateur?->getUserIdentifier() ?? 'inconnu',
            'file' => $file,
        ]);
    }

    /**
     * L'historique d'un objet, du plus recent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function historique(string $objetType, string $objetId): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT * FROM pilotage.geste WHERE objet_type = ? AND objet_id = ?
              ORDER BY fait_le DESC, id DESC LIMIT 40',
            [$objetType, $objetId]);

        return $lignes;
    }

    /**
     * Ce que l'utilisateur connecte a inscrit ici, et ou il a envoye du travail.
     *
     * @return array{gestes: list<array<string, mixed>>, par_type: list<array<string, mixed>>,
     *               total: int, montant: float, auteur: string}
     */
    public function journal(?string $auteur = null): array
    {
        $auteur ??= $this->securite->getUser()?->getUserIdentifier() ?? 'inconnu';

        /** @var list<array<string, mixed>> $gestes */
        $gestes = $this->cnx->fetchAllAssociative(
            'SELECT * FROM pilotage.geste WHERE auteur = ? ORDER BY fait_le DESC, id DESC LIMIT 80',
            [$auteur]);
        /** @var list<array<string, mixed>> $parType */
        $parType = $this->cnx->fetchAllAssociative(
            'SELECT type, module, count(*) nb, coalesce(sum(montant),0) montant
               FROM pilotage.geste WHERE auteur = ? GROUP BY 1,2 ORDER BY 4 DESC',
            [$auteur]);
        $totaux = $this->cnx->fetchAssociative(
            'SELECT count(*) nb, coalesce(sum(montant),0) montant FROM pilotage.geste WHERE auteur = ?',
            [$auteur]) ?: [];

        return [
            'gestes' => $gestes,
            'par_type' => $parType,
            'total' => (int) ($totaux['nb'] ?? 0),
            'montant' => (float) ($totaux['montant'] ?? 0),
            'auteur' => $auteur,
        ];
    }
}
