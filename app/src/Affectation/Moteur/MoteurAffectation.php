<?php

declare(strict_types=1);

namespace App\Affectation\Moteur;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Le moteur d'identification du payeur et d'affectation des factures.
 *
 * Quatre phases, chronometrees separement :
 *   1. ENRICHISSEMENT   ce que la suite retrouve ailleurs a partir d'un libelle
 *   2. CANDIDATS        les payeurs possibles, et pourquoi
 *   3. COMBINAISONS     quelles factures expliquent le montant
 *   4. SCORE ET DECISION la conviction, et ce qu'on en fait
 *
 * Le moteur ne lit JAMAIS le schema `affectation_verite`. Il traite le monde
 * sans connaitre la bonne reponse : c'est la seule facon de mesurer honnetement
 * ce qu'il vaut.
 *
 * Deux modes. En BRUT, il ne dispose que de la date, du montant et du libelle,
 * comme un rapprochement bancaire ordinaire. En ENRICHI, il dispose du contrat
 * de donnees complet. L'ecart entre les deux est le message de l'outil.
 */
final class MoteurAffectation
{
    public const MODE_BRUT = 'RAW';
    public const MODE_ENRICHI = 'ENRICHED';

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * @return array{
     *   virement: array<string, mixed>,
     *   enrichissement: array<string, mixed>,
     *   candidats: list<array<string,mixed>>,
     *   retenu: array<string,mixed>|null,
     *   decision: string,
     *   motif: string,
     *   contexte: array{composition: bool, composition_unique: bool, preuve_compte: bool, identite: bool, contradiction: bool, iban_partage: bool},
     *   score: int,
     *   score_suivant: int|null,
     *   marge: int,
     *   preuves: list<array{signal:string,libelle:string,constat:string,poids:int,origine:string}>,
     *   chrono: array<string, float>,
     *   combinaison: array<string, mixed>
     * }
     */
    public function analyser(string $virementId, string $mode = self::MODE_ENRICHI): array
    {
        $chrono = [];
        $t = microtime(true);

        $virement = $this->cnx->fetchAssociative(
            'SELECT * FROM affectation.virement WHERE id = ?', [$virementId]);
        if (false === $virement) {
            throw new InvalidArgumentException("Virement inconnu : {$virementId}");
        }
        $enrichi = self::MODE_ENRICHI === $mode;

        // ------------------------------------------------ 1. enrichissement
        $t0 = microtime(true);
        $enrichissement = $this->enrichir($virement, $enrichi);
        $chrono['enrichissement'] = (microtime(true) - $t0) * 1000;

        // ------------------------------------------------ 2. candidats
        $t0 = microtime(true);
        $candidats = $this->candidats($virement, $enrichissement, $enrichi);
        $chrono['candidats'] = (microtime(true) - $t0) * 1000;

        // ------------------------------- 3. combinaisons et 4. score
        $t0 = microtime(true);
        $combinaisonTotale = ['ms' => 0.0, 'noeuds' => 0, 'solutions' => 0, 'tronquee' => false];
        $evalues = [];
        foreach ($candidats as $candidat) {
            $evaluation = $this->evaluer($virement, $enrichissement, $candidat, $enrichi);
            $combinaisonTotale['ms'] += $evaluation['combinaison']['ms'];
            $combinaisonTotale['noeuds'] += $evaluation['combinaison']['noeuds'];
            $combinaisonTotale['solutions'] += \count($evaluation['combinaison']['solutions']);
            $combinaisonTotale['tronquee'] = $combinaisonTotale['tronquee'] || $evaluation['combinaison']['tronquee'];
            $evalues[] = $evaluation;
        }
        usort($evalues, static fn (array $a, array $b): int => $b['points_bruts'] <=> $a['points_bruts']);
        $chrono['combinaison'] = $combinaisonTotale['ms'];
        $chrono['score'] = max(0.0, (microtime(true) - $t0) * 1000 - $combinaisonTotale['ms']);

        $retenu = $evalues[0] ?? null;
        $score = $retenu['score'] ?? 0;
        $scoreSuivant = isset($evalues[1]) ? $evalues[1]['score'] : null;

        // La marge se lit sur les points bruts : le plafond d'affichage ne doit
        // pas rapprocher artificiellement deux candidats que les preuves
        // separent largement.
        $brut = $retenu['points_bruts'] ?? 0;
        $brutSuivant = $evalues[1]['points_bruts'] ?? null;
        $marge = null === $brutSuivant ? $score : max(0, $brut - $brutSuivant);

        if (null === $retenu) {
            $decision = Decision::REFUS;
            $motif = 'Aucun payeur candidat.';
            $contexte = ['composition' => false, 'composition_unique' => true, 'preuve_compte' => false,
                'identite' => false, 'contradiction' => false, 'iban_partage' => false];
        } else {
            $contexte = $this->contexte($retenu, $enrichissement);
            $verdict = Bareme::decider($score, $scoreSuivant, $contexte, $marge);
            $decision = $verdict['decision'];
            $motif = $verdict['motif'];
        }

        $chrono['total'] = (microtime(true) - $t) * 1000;

        return [
            'virement' => $virement,
            'mode' => $mode,
            'enrichissement' => $enrichissement,
            'candidats' => $evalues,
            'retenu' => $retenu,
            'decision' => $decision,
            'motif' => $motif,
            'contexte' => $contexte,
            'score' => $score,
            'score_suivant' => $scoreSuivant,
            'marge' => $marge,
            'preuves' => $retenu['preuves'] ?? [],
            'combinaison' => $combinaisonTotale,
            'chrono' => $chrono,
        ];
    }

    // =================================================== 1. enrichissement

    /**
     * Ce que la suite retrouve ailleurs a partir d'un libelle bancaire.
     *
     * En mode BRUT, cette phase ne rend presque rien : c'est tout le propos.
     *
     * @param array<string, mixed> $v
     *
     * @return array<string, mixed>
     */
    private function enrichir(array $v, bool $enrichi): array
    {
        $texte = strtoupper((string) $v['libelle'].' '.(string) $v['reference_bout_en_bout']);
        $e = [
            'texte_analyse' => $texte,
            'references' => Normalisation::referencesDansTexte($texte),
            'series_citees' => Normalisation::seriesDansTexte($texte),
            'nom_normalise' => Normalisation::nom((string) $v['nom_donneur_ordre']),
            'iban' => null,
            'iban_occurrences' => 0,
            'iban_premiere_apparition' => null,
            'factures_par_numero' => [],
            'motif_reference' => null,
            // Combien de comptes clients reglent depuis ce compte bancaire ?
            'iban_comptes' => 0,
            'iban_partage' => false,
        ];

        if (!$enrichi) {
            // Mode BRUT : ni IBAN, ni referentiel, ni historique. Seul le
            // montant et le texte du libelle restent exploitables.
            $e['references'] = [];
            $e['series_citees'] = [];
            $e['nom_normalise'] = '';

            return $e;
        }

        if (null !== $v['iban_emetteur_id']) {
            $iban = $this->cnx->fetchAssociative(
                'SELECT * FROM affectation.iban WHERE id = ?', [$v['iban_emetteur_id']]);
            if (false !== $iban) {
                $e['iban'] = $iban;
                $e['iban_occurrences'] = (int) $iban['occurrences'];
                $e['iban_premiere_apparition'] = $iban['premiere_apparition'];
                // Une meme enseigne porte souvent plusieurs codes clients et
                // regle depuis un compte unique. On le mesure avant de scorer,
                // parce que cela change la nature du signal.
                $e['iban_comptes'] = (int) $this->cnx->fetchOne(
                    'SELECT count(*) FROM affectation.client c
                       JOIN affectation.iban i ON i.id = c.iban_id
                      WHERE i.empreinte = ?', [$iban['empreinte']]);
                $e['iban_partage'] = $e['iban_comptes'] > 1;
            }
        }

        // Une reference du libelle correspond-elle a un numero de facture ?
        if ([] !== $e['references']) {
            $e['factures_par_numero'] = $this->cnx->fetchAllAssociative(
                'SELECT id, numero, client_id, societe_id, montant, statut
                   FROM affectation.facture WHERE numero IN (?)',
                [$e['references']], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        }

        return $e;
    }

    // ======================================================= 2. candidats

    /**
     * Les payeurs possibles. Chacun arrive avec la raison qui l'a fait entrer.
     *
     * @param array<string, mixed> $v
     * @param array<string, mixed> $e
     *
     * @return list<array{client: array<string,mixed>, entrees: list<string>}>
     */
    private function candidats(array $v, array $e, bool $enrichi): array
    {
        $trouves = [];
        $ajouter = function (array $client, string $entree) use (&$trouves): void {
            $id = (string) $client['id'];
            if (!isset($trouves[$id])) {
                $trouves[$id] = ['client' => $client, 'entrees' => []];
            }
            $trouves[$id]['entrees'][] = $entree;
        };

        // a) par l'IBAN emetteur : le signal le plus fort
        if ($enrichi && null !== $e['iban'] && null !== $e['iban']['titulaire_client_id']) {
            $c = $this->client((string) $e['iban']['titulaire_client_id']);
            if (null !== $c) {
                $ajouter($c, 'iban');
            }
        }

        // b) par l'empreinte d'IBAN : le meme compte peut porter plusieurs comptes clients
        if ($enrichi && null !== $e['iban']) {
            foreach ($this->cnx->fetchAllAssociative(
                'SELECT c.* FROM affectation.client c
                   JOIN affectation.iban i ON i.id = c.iban_id
                  WHERE i.empreinte = ? LIMIT 6', [$e['iban']['empreinte']]) as $c) {
                $ajouter($c, 'empreinte');
            }
        }

        // c) par une facture retrouvee dans le libelle
        foreach ($e['factures_par_numero'] as $f) {
            $c = $this->client((string) $f['client_id']);
            if (null !== $c) {
                $ajouter($c, 'facture');
            }
        }

        // d) par le nom du donneur d'ordre
        if ($enrichi && '' !== $e['nom_normalise'] && \strlen($e['nom_normalise']) >= 5) {
            foreach ($this->cnx->fetchAllAssociative(
                'SELECT * FROM affectation.client WHERE nom_normalise = ? LIMIT 6',
                [$e['nom_normalise']]) as $c) {
                $ajouter($c, 'nom_exact');
            }
            if ([] === $trouves) {
                // Aucun nom exact : on tente les debuts de nom, puis on
                // departagera par similarite. Une recherche large ne conclut
                // rien a elle seule.
                foreach ($this->cnx->fetchAllAssociative(
                    'SELECT * FROM affectation.client
                      WHERE nom_normalise LIKE ? LIMIT 8',
                    [substr($e['nom_normalise'], 0, 6).'%']) as $c) {
                    $ajouter($c, 'nom_approche');
                }
            }
        }

        // e) en mode BRUT : le montant est le seul point d'entree
        if (!$enrichi) {
            foreach ($this->cnx->fetchAllAssociative(
                'SELECT c.* FROM affectation.facture f
                   JOIN affectation.client c ON c.id = f.client_id
                  WHERE NOT f.affectee AND abs(f.montant - ?) < 0.01 LIMIT 8',
                [$v['montant']]) as $c) {
                $ajouter($c, 'montant_seul');
            }
        }

        return array_values($trouves);
    }

    /** @return array<string, mixed>|null */
    private function client(string $id): ?array
    {
        $c = $this->cnx->fetchAssociative('SELECT * FROM affectation.client WHERE id = ?', [$id]);

        return false === $c ? null : $c;
    }

    // ============================================ 3 et 4. score et preuves

    /**
     * Ce que les preuves du candidat retenu autorisent.
     *
     * On ne regarde pas le total des points : on regarde leur NATURE. Un
     * candidat peut atteindre un indice eleve sans qu'aucun signal ne designe
     * le compte a crediter -- c'est exactement le cas d'un compte bancaire
     * partage entre deux codes clients de la meme enseigne.
     *
     * @param array<string, mixed> $retenu
     * @param array<string, mixed> $e
     *
     * @return array{composition: bool, composition_unique: bool, preuve_compte: bool, identite: bool, contradiction: bool, iban_partage: bool}
     */
    private function contexte(array $retenu, array $e): array
    {
        $signaux = array_map(static fn (array $p): string => $p['signal'], $retenu['preuves']);

        // Combien de jeux de factures DIFFERENTS expliquent ce montant ? Un
        // seul est une explication ; deux sont une question.
        $distinctes = [];
        foreach ($retenu['combinaison']['solutions'] as $solution) {
            $cle = $solution;
            sort($cle);
            $distinctes[implode('|', $cle)] = true;
        }

        return [
            'composition' => [] !== $retenu['factures'],
            'composition_unique' => \count($distinctes) <= 1,
            'preuve_compte' => [] !== array_intersect($signaux, Bareme::SIGNAUX_DE_COMPTE),
            'identite' => [] !== array_intersect($signaux, Bareme::SIGNAUX_D_IDENTITE),
            'contradiction' => [] !== array_intersect($signaux, Bareme::SIGNAUX_CONTRADICTOIRES),
            'iban_partage' => true === $e['iban_partage'],
        ];
    }

    /** Date de premiere apparition, ou rien : une date illisible ne devient pas 1970. */
    private function depuisLe(mixed $date): string
    {
        if (!\is_string($date) || '' === $date) {
            return '';
        }
        $t = strtotime($date);

        return false === $t ? '' : ', depuis le '.date('d/m/Y', $t);
    }

    /**
     * @param array<string, mixed>                                      $v
     * @param array<string, mixed>                                      $e
     * @param array{client: array<string,mixed>, entrees: list<string>} $candidat
     *
     * @return array<string, mixed>
     */
    private function evaluer(array $v, array $e, array $candidat, bool $enrichi): array
    {
        $client = $candidat['client'];
        $preuves = [];
        $points = 0;

        $poser = static function (string $signal, string $constat) use (&$preuves, &$points): void {
            $d = Bareme::SIGNAUX[$signal];
            $preuves[] = ['signal' => $signal, 'libelle' => $d['libelle'],
                'constat' => $constat, 'poids' => $d['poids'], 'origine' => $d['origine']];
            $points += $d['poids'];
        };

        // --- identite du payeur
        //
        // Quand le compte bancaire est partage, les deux signaux d'IBAN sont
        // poses A L'IDENTIQUE sur tous les comptes du groupe. C'est volontaire :
        // designer comme titulaire l'un des codes clients releve d'un choix de
        // parametrage, pas d'une preuve. Les laisser departager reviendrait a
        // faire trancher le moteur sur un hasard de referentiel.
        $vuParIban = \in_array('iban', $candidat['entrees'], true)
            || \in_array('empreinte', $candidat['entrees'], true);
        if ($vuParIban && $e['iban_partage']) {
            $poser('iban_partage', sprintf('%d comptes clients règlent depuis ce compte bancaire', $e['iban_comptes']));
            if ($e['iban_occurrences'] >= 3) {
                $poser('iban_frequence', sprintf('déjà observé %d fois%s',
                    $e['iban_occurrences'], $this->depuisLe($e['iban_premiere_apparition'])));
            }
        } elseif (\in_array('iban', $candidat['entrees'], true)) {
            $poser('iban_connu', 'IBAN émetteur rattaché à ce compte client');
            if ($e['iban_occurrences'] >= 3) {
                $poser('iban_frequence', sprintf('déjà observé %d fois%s',
                    $e['iban_occurrences'], $this->depuisLe($e['iban_premiere_apparition'])));
            }
        } elseif (\in_array('empreinte', $candidat['entrees'], true)) {
            $poser('iban_connu', 'même compte bancaire, sous un autre code client');
        }

        // --- nom du donneur d'ordre
        $nomOrdre = $e['nom_normalise'];
        $nomClient = (string) $client['nom_normalise'];
        if ($enrichi && '' !== $nomOrdre && '' !== $nomClient) {
            if ($nomOrdre === $nomClient) {
                $poser('nom_exact', $client['nom']);
            } else {
                $similarite = Normalisation::jaroWinkler($nomOrdre, $nomClient);
                if ($similarite >= Normalisation::NOM_SIMILARITE_MIN) {
                    $poser('nom_proche', sprintf('similarité %.0f %% avec « %s »', $similarite * 100, $client['nom']));
                } else {
                    $poser('nom_different', sprintf('« %s » au libellé, « %s » au compte',
                        $v['nom_donneur_ordre'], $client['nom']));
                }
            }
        }

        // --- identifiant d'entreprise trouve dans le libelle
        if ($enrichi && null !== $client['siren'] && str_contains($e['texte_analyse'], (string) $client['siren'])) {
            $poser('siren_concordant', (string) $client['siren']);
        }

        // --- reference : facture nommee, ou motif de bordereau du payeur
        $profil = $enrichi ? $this->cnx->fetchAssociative(
            'SELECT * FROM affectation.payeur_profil WHERE client_id = ?', [$client['id']]) : false;

        foreach ($e['factures_par_numero'] as $f) {
            if ($f['client_id'] === $client['id']) {
                $poser('reference_facture', 'facture '.$f['numero'].' citée dans le libellé');
                break;
            }
        }
        if (false !== $profil && null !== $profil['motif_reference']
            && str_contains($e['texte_analyse'], (string) $profil['motif_reference'])) {
            $poser('reference_bordereau', sprintf('motif « %s » habituel de ce payeur, reconnu dans la référence de bout en bout',
                $profil['motif_reference']));
        }

        // --- historique
        if ($enrichi) {
            if (false === $profil) {
                $poser('aucun_historique', 'aucun règlement antérieur connu de ce payeur');
            } else {
                $moyen = (float) $profil['montant_moyen'];
                $montant = (float) $v['montant'];
                if ($moyen > 0 && $montant >= $moyen * 0.4 && $montant <= $moyen * 12) {
                    $poser('montant_dans_habitudes', sprintf('règlement moyen %s €, %d règlements observés',
                        number_format($moyen, 0, ',', ' '), (int) $profil['nb_reglements']));
                } elseif ($moyen > 0 && ($montant > $moyen * 60 || $montant < $moyen * 0.02)) {
                    $poser('hors_habitudes', sprintf('règlement moyen %s €, ici %s €',
                        number_format($moyen, 0, ',', ' '), number_format($montant, 0, ',', ' ')));
                }
            }
        }

        // --- numero de serie cite au libelle et rattache a un AUTRE compte
        //
        // Un vehicule rattache aujourd'hui a un autre compte n'est PAS une
        // contradiction : un vehicule d'occasion change de mains, et la facture
        // d'hier reste due par le client d'hier. La vraie contradiction est
        // ailleurs, et elle est dure : un numero de serie CITE DANS LE LIBELLE
        // qui appartient a quelqu'un d'autre.
        //
        // Ce controle se fait ici, avant la recherche de combinaison, et non
        // plus a l'interieur du bloc des vehicules des factures retenues. Il ne
        // doit pas dependre du fait que la facture retenue porte un vehicule :
        // c'est ce qui le rendait aveugle une fois sur vingt.
        if ($enrichi && [] !== $e['series_citees']) {
            $etrangers = $this->cnx->fetchOne(
                'SELECT count(*) FROM affectation.vehicule
                  WHERE serie8 IN (?) AND client_id IS NOT NULL AND client_id <> ?',
                [$e['series_citees'], $client['id']],
                [\Doctrine\DBAL\ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING]);
            if ((int) $etrangers > 0) {
                $poser('serie_contradictoire', sprintf(
                    '%d numéro de série cité au libellé appartient à un autre compte client',
                    (int) $etrangers));
            }
        }

        // --- combinaison de factures : le coeur du travail
        $ouvertes = $this->cnx->fetchAllAssociative(
            'SELECT id, numero, montant, societe_id, etablissement_id, vehicule_id, date_facture
               FROM affectation.facture
              WHERE client_id = ? AND NOT affectee AND montant <= ?
              ORDER BY montant DESC LIMIT 60',
            [$client['id'], (float) $v['montant'] + 1]);

        $recherche = Combinaisons::chercher(
            array_map(static fn (array $f): array => ['id' => (string) $f['id'], 'montant' => (float) $f['montant']], $ouvertes),
            (float) $v['montant']);

        $meilleure = $recherche['solutions'][0] ?? null;
        $societes = [];
        $series = [];
        $facturesRetenues = [];

        if (null !== $meilleure) {
            $parId = [];
            foreach ($ouvertes as $f) {
                $parId[(string) $f['id']] = $f;
            }
            foreach ($meilleure as $idFacture) {
                $f = $parId[$idFacture];
                $facturesRetenues[] = $f;
                $societes[(string) $f['societe_id']] = true;
            }
            if (1 === \count($meilleure)) {
                $poser('montant_exact', sprintf('facture %s de %s €',
                    $facturesRetenues[0]['numero'], number_format((float) $facturesRetenues[0]['montant'], 2, ',', ' ')));
            } else {
                $poser('montant_combinaison', sprintf('somme de %d factures = %s €, sur %d société%s',
                    \count($meilleure), number_format((float) $v['montant'], 2, ',', ' '),
                    \count($societes), \count($societes) > 1 ? 's' : ''));
            }

            // Corroboration par les vehicules : les factures retenues portent-elles
            // des vehicules de ce payeur ?
            if ($enrichi) {
                $ids = array_values(array_filter(array_map(
                    static fn (array $f): ?string => null !== $f['vehicule_id'] ? (string) $f['vehicule_id'] : null,
                    $facturesRetenues)));
                if ([] !== $ids) {
                    $series = $this->cnx->fetchAllAssociative(
                        'SELECT id, serie, serie8, immatriculation, client_id
                           FROM affectation.vehicule WHERE id IN (?)',
                        [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING]);
                    $concordants = array_filter($series, static fn (array $s): bool => $s['client_id'] === $client['id']);

                    if (\count($concordants) > 0) {
                        $poser('serie_concordante', sprintf('%d numéro%s de série rattaché%s à ce payeur',
                            \count($concordants), \count($concordants) > 1 ? 's' : '', \count($concordants) > 1 ? 's' : ''));
                    }
                }
            }

            // Sociétés habituelles du payeur
            if (false !== $profil && null !== $profil['societes_reglees']) {
                $habituelles = explode('|', (string) $profil['societes_reglees']);
                $communes = array_intersect(array_keys($societes), $habituelles);
                if (\count($communes) === \count($societes) && \count($societes) > 0) {
                    $poser('societes_habituelles', sprintf('%d société%s déjà réglée%s par ce payeur',
                        \count($communes), \count($communes) > 1 ? 's' : '', \count($communes) > 1 ? 's' : ''));
                }
            }
        } else {
            $poser('montant_inexplique', sprintf('%d factures ouvertes examinées, aucune combinaison jusqu\'à %d factures',
                \count($ouvertes), Bareme::COMBINAISON_MAX_FACTURES));
        }

        return [
            'client' => $client,
            'entrees' => $candidat['entrees'],
            'score' => Bareme::borner($points),
            'points_bruts' => $points,
            'preuves' => $preuves,
            'factures' => $facturesRetenues,
            'societes' => array_keys($societes),
            'vehicules' => $series,
            'combinaison' => $recherche,
            'profil' => false === $profil ? null : $profil,
            'nb_factures_ouvertes' => \count($ouvertes),
        ];
    }
}
