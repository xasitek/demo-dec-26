<?php

declare(strict_types=1);

namespace App\GrandsComptes\Moteur;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Le controle de conformite d'un dossier grand compte.
 *
 * Un moteur de regles deterministe et local. Chaque anomalie produite porte
 * trois choses, et c'est ce qui la rend defendable devant le site qui a depose
 * le dossier : ce qui etait ATTENDU, ce qui a ete TROUVE, et la regle qui
 * conclut. C'est le triptyque de l'ecran de controle.
 *
 * Deux principes gouvernent l'ordre des regles :
 *
 *   1. la GRILLE DU LOUEUR commande. Une piece que ce loueur ne demande pas
 *      n'est jamais reclamee, et son absence ne produit aucune anomalie ;
 *   2. on ne reproche pas deux fois la meme absence. Si le prix de la batterie
 *      manque, on ne reproche pas en plus son montant HT et son montant TTC :
 *      c'est le meme fait, et l'empiler ferait trois anomalies la ou il y en a
 *      une.
 *
 * Et une doctrine, qui vaut refus : une anomalie qui ne peut pas etre ETABLIE
 * -- piece exigee absente, valeur illisible -- ne rend pas le dossier non
 * conforme. Elle le rend incomplet. Le doute ne se tranche pas, il se leve.
 */
final class Controle
{
    /**
     * Les quatre variantes degradees, pour la contre-epreuve.
     *
     * Un taux de reussite obtenu sur une population construite par les memes
     * regles que le moteur ne demontre rien tout seul : il faut prouver que les
     * pieges existent. Ces quatre variantes sont des implementations NAIVES,
     * chacune privee d'une seule regle de doctrine, et on les fait tourner sur
     * la meme population. Ce qu'elles cassent mesure ce que la doctrine apporte.
     *
     * @var array<string, string>
     */
    public const VARIANTES = [
        'strict' => 'Le moteur tel qu\'il est livre.',
        'sans_grille' => 'Ignore la grille du loueur : toute piece du referentiel est reclamee '
            .'a tout le monde.',
        'sans_tolerance' => 'Ignore la tolerance declaree du loueur : le moindre centime d\'ecart '
            .'devient une anomalie.',
        'doute_tranche' => 'Tranche le doute : une piece absente ou illisible rend le dossier '
            .'non conforme au lieu de l\'instruire.',
        'cumul_batterie' => 'Empile les anomalies de batterie : reproche aussi le HT et le TTC '
            .'quand le prix lui-meme est absent.',
    ];

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Controle un dossier et rend son verdict, ses anomalies et son detail.
     *
     * @return array{
     *   dossier: array<string, mixed>, loueur: array<string, mixed>,
     *   pieces: list<array<string, mixed>>, anomalies: list<array<string, mixed>>,
     *   verdict: string, nb_bloquantes: int, pieces_attendues: int,
     *   pieces_presentes: int, arret: ?string, duree_ms: float
     * }
     */
    public function analyser(string $dossierId, string $variante = 'strict'): array
    {
        $t0 = microtime(true);
        if (!isset(self::VARIANTES[$variante])) {
            throw new InvalidArgumentException('Variante inconnue : '.$variante);
        }

        $dossier = $this->cnx->fetchAssociative(
            'SELECT * FROM grands_comptes.dossier WHERE id = ?', [$dossierId]) ?: [];
        if ([] === $dossier) {
            throw new InvalidArgumentException('Dossier inconnu : '.$dossierId);
        }
        $loueur = $this->cnx->fetchAssociative(
            'SELECT * FROM grands_comptes.loueur WHERE loueur_id = ?', [$dossier['loueur_id']]) ?: [];
        /** @var list<array<string, mixed>> $pieces */
        $pieces = $this->cnx->fetchAllAssociative(
            'SELECT p.*, t.libelle AS type_libelle, t.rang
               FROM grands_comptes.piece p
               JOIN grands_comptes.type_piece t ON t.code = p.type_piece
              WHERE p.dossier_id = ? ORDER BY t.rang', [$dossierId]);

        $parType = [];
        foreach ($pieces as $p) {
            $parType[(string) $p['type_piece']] = $p;
        }

        $electrifie = \in_array($dossier['energie'], ['electrique', 'hybride'], true);
        $tolerance = 'sans_tolerance' === $variante
            ? 0
            : (int) ($loueur['tolerance_centimes'] ?? 0);
        $exigeTampon = (bool) ($loueur['exige_tampon'] ?? false);

        $anomalies = [];
        $ajouter = function (string $code, string $attendu, string $trouve, string $motif) use (&$anomalies): void {
            $anomalies[$code] = ['code' => $code, 'attendu' => $attendu, 'trouve' => $trouve, 'motif' => $motif];
        };

        // ---------------------------------------------------- 1. la grille
        //
        // Une piece exigee et absente arrete le controle sur cette piece : on
        // ne reproche rien a un document qu'on n'a pas.
        $attendues = 0;
        $presentes = 0;
        $manquantes = [];
        $illisibles = [];
        foreach ($pieces as $p) {
            $bloquante = 'sans_grille' === $variante
                ? true
                : (bool) (Referentiel::EXIGENCES[(string) $p['exigence']]['bloquant'] ?? false);
            if ($bloquante) {
                ++$attendues;
            }
            if ($p['presente']) {
                ++$presentes;
            } elseif ($bloquante) {
                $manquantes[] = (string) $p['type_piece'];
            }
            if ($p['presente'] && !$p['lisible']) {
                $illisibles[] = (string) $p['type_piece'];
            }
        }

        foreach ($manquantes as $type) {
            if ('CPI' === $type) {
                $ajouter('CPI_ABSENT', 'CPI présent au dossier', 'pièce absente',
                    "La grille de ce loueur exige le certificat provisoire d'immatriculation.");
            } elseif ('PVL' === $type) {
                $ajouter('DOSSIER_NON_DECLARE', 'PV de livraison signé', 'aucun PV au dossier',
                    'Un véhicule sans PV de livraison équivaut à un véhicule non livré aux yeux du loueur.');
            }
        }
        foreach ($illisibles as $type) {
            if ('BDC' === $type) {
                $ajouter('BDC_NUM_ILLISIBLE', 'numéro de commande lisible', 'numéro illisible ou incomplet',
                    'Le rapprochement avec la facture ne peut pas être établi : le doute ne se tranche pas.');
            }
        }

        // ------------------------------------- 2. bon de commande contre facture
        $bdc = $parType['BDC'] ?? null;
        $f1 = $parType['F1'] ?? null;
        if (null !== $bdc && null !== $f1 && $bdc['presente'] && $f1['presente'] && $bdc['lisible']) {
            $numeroBdc = $bdc['numero_lu'];
            $surFacture = $f1['numero_commande_lu'];

            if (null === $surFacture || '' === $surFacture) {
                $ajouter('BDC_NUM_ABSENT', (string) $numeroBdc, 'aucun numéro de commande sur la facture',
                    'Le loueur rapproche la facture du bon de commande par ce numéro. Sans lui, il ne paie pas.');
            } elseif ((string) $surFacture !== (string) $numeroBdc) {
                $ajouter('BDC_NUM_DIVERGENT', (string) $numeroBdc, (string) $surFacture,
                    'La facture porte un autre numéro de commande que le bon de commande du dossier.');
            }

            if (null !== $bdc['montant_lu']) {
                $ecart = abs($this->cent($bdc['montant_lu']) - $this->cent($dossier['montant_facture']));
                if ($ecart > $tolerance) {
                    $ajouter('BDC_MONTANT_DIVERGENT',
                        $this->euros($dossier['montant_facture']),
                        $this->euros($bdc['montant_lu']),
                        sprintf('Écart de %s, au-delà de la tolérance de %d centime%s déclarée par ce loueur.',
                            $this->euros($ecart / 100), $tolerance, $tolerance > 1 ? 's' : ''));
                }
            }
        }

        // ------------------------------------------- 3. la batterie, si electrifie
        if ($electrifie && null !== $f1 && $f1['presente'] && $f1['lisible'] && $f1['ligne_batterie']) {
            if (null === $f1['prix_batterie']) {
                // Le prix absent est le fait parent : on ne reproche pas en plus
                // ses declinaisons HT et TTC.
                $ajouter('BAT_PRIX_ABSENT', 'prix de la batterie sur la facture', 'aucun prix',
                    'Sur un véhicule électrique ou hybride, le loueur refacture la batterie à part : '
                    .'sans son prix, il ne peut pas établir son propre décompte.');
                if ('cumul_batterie' === $variante) {
                    $ajouter('BAT_HT_MANQUANT', 'montant HT', 'absent', 'Variante naïve : empilement.');
                    $ajouter('BAT_TTC_MANQUANT', 'montant TTC', 'absent', 'Variante naïve : empilement.');
                }
            } else {
                if (null === $f1['prix_batterie_ht']) {
                    $ajouter('BAT_HT_MANQUANT', 'montant HT de la batterie',
                        'seul le prix global figure',
                        'Le loueur récupère la TVA : il lui faut le montant hors taxes.');
                }
                if (null === $f1['prix_batterie_ttc']) {
                    $ajouter('BAT_TTC_MANQUANT', 'montant TTC de la batterie',
                        'seul le prix global figure',
                        'Le montant TTC est celui que le conducteur voit sur son décompte.');
                }
                if (false === $f1['mention_devise']) {
                    $ajouter('BAT_MENTION_MANQUANTE', 'mention HT ou TTC après le prix',
                        'prix sans mention',
                        'Un prix sans mention de devise se lit de deux façons : le loueur rejette la ligne.');
                }
            }
        }

        // ------------------------------------------------ 4. le PV de livraison
        $pvl = $parType['PVL'] ?? null;
        if (null !== $pvl && $pvl['presente'] && $pvl['lisible']) {
            if (null === $pvl['date_lue'] && false === $pvl['signe']) {
                $ajouter('PVL_NON_DATE_NON_SIGNE', 'PV daté et signé par le client',
                    'ni date ni signature',
                    'Un PV ni daté ni signé ne prouve pas la livraison.');
            }
            if ($exigeTampon && false === $pvl['tampon']) {
                $ajouter('PVL_NON_TAMPONNE', 'PV tamponné', 'pas de tampon',
                    'Ce loueur exige le tampon du site en plus de la signature.');
            }
            if (null !== $pvl['immatriculation_lue'] && null !== $dossier['immatriculation']
                && (string) $pvl['immatriculation_lue'] !== (string) $dossier['immatriculation']) {
                $ajouter('PVL_IMMAT_DIVERGENTE', (string) $dossier['immatriculation'],
                    (string) $pvl['immatriculation_lue'],
                    "Le PV porte l'immatriculation d'un autre véhicule : le dossier ne concerne pas ce PV.");
            }
        }

        // ------------------------------------------------ 5. l'adresse de facturation
        if (null !== $f1 && $f1['presente'] && $f1['lisible'] && 'client' === $f1['adresse_facturation']) {
            $ajouter('FACT_ADRESSE_CLIENT', (string) ($loueur['nom'] ?? 'le loueur'),
                'le conducteur',
                "La facture d'un véhicule loué s'adresse au loueur. Adressée au conducteur, elle "
                .'ne sera jamais payée par le payeur du dossier.');
        }

        // ------------------------------------------------------------- le verdict
        $arret = null;
        if ('doute_tranche' === $variante && ([] !== $manquantes || [] !== $illisibles)) {
            // La variante naive : elle conclut sur ce qu'elle n'a pas lu.
            $verdict = 'non_conforme';
        } elseif ([] !== $manquantes) {
            $verdict = 'incomplet';
            $arret = 'piece_exigee_absente';
        } elseif ([] !== $illisibles) {
            $verdict = 'incomplet';
            $arret = 'valeur_illisible';
        } elseif ([] !== $anomalies) {
            $verdict = 'non_conforme';
        } else {
            $verdict = 'conforme';
        }

        // Enrichit chaque anomalie de son referentiel.
        $ref = $this->referentiel();
        $sorties = [];
        foreach ($anomalies as $code => $a) {
            $def = $ref[$code] ?? null;
            $sorties[] = $a + [
                'libelle' => $def['libelle'] ?? $code,
                'gravite' => $def['gravite'] ?? 'majeure',
                'type_piece' => $def['type_piece'] ?? '',
                'suite' => $def['suite'] ?? 'redeposer',
            ];
        }
        usort($sorties, static fn (array $x, array $y): int => ['bloquante' === $y['gravite'] ? 1 : 0]
            <=> ['bloquante' === $x['gravite'] ? 1 : 0]);

        $bloquantes = \count(array_filter($sorties, static fn (array $a): bool => 'bloquante' === $a['gravite']));

        return [
            'dossier' => $dossier,
            'loueur' => $loueur,
            'pieces' => $pieces,
            'anomalies' => $sorties,
            'verdict' => $verdict,
            'nb_bloquantes' => $bloquantes,
            'pieces_attendues' => $attendues,
            'pieces_presentes' => $presentes,
            'arret' => $arret,
            'duree_ms' => (microtime(true) - $t0) * 1000,
        ];
    }

    /**
     * Le referentiel des anomalies, indexe par code.
     *
     * @return array<string, array<string, mixed>>
     */
    public function referentiel(): array
    {
        /** @var array<string, array<string, mixed>> $ref */
        $ref = $this->cnx->fetchAllAssociativeIndexed(
            'SELECT code, libelle, gravite, type_piece, suite, rang
               FROM grands_comptes.anomalie_ref ORDER BY rang');

        return $ref;
    }

    private function cent(mixed $v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private function euros(mixed $v): string
    {
        return number_format((float) $v, 2, ',', ' ').' €';
    }
}
