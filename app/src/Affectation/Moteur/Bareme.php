<?php

declare(strict_types=1);

namespace App\Affectation\Moteur;

/**
 * Le bareme : poids des signaux et seuils de decision.
 *
 * Ce sont des DONNEES, pas du code enfoui. Elles se lisent, se discutent et se
 * changent en un endroit. C'est la condition pour qu'un confrere puisse
 * reprendre la methode, et pour qu'un examinateur puisse l'auditer.
 *
 * CALIBRATION : ces valeurs ont ete reglees sur la seule population
 * CALIBRATION. Ni VALIDATION ni BLIND_TEST n'ont servi a les fixer.
 */
final class Bareme
{
    /**
     * Poids des signaux, en points.
     *
     * Un signal absent ne retire rien : il ne prouve rien. Seul un signal
     * CONTRADICTOIRE retire des points, et c'est ce qui permet a l'outil
     * d'ecarter un candidat par ailleurs plausible.
     *
     * @var array<string, array{poids: int, libelle: string, origine: string}>
     */
    public const SIGNAUX = [
        // Calibres le 06/09/2026 sur la seule population CALIBRATION.
        // Ni VALIDATION ni BLIND_TEST n'ont servi a fixer ces valeurs.
        'iban_connu' => ['poids' => 40, 'libelle' => 'IBAN déjà rattaché à ce payeur', 'origine' => 'SRC-O4-S03'],
        // Compte bancaire partage par plusieurs codes clients. Il dit QUI paie,
        // il ne dit pas QUEL compte crediter : il pese donc beaucoup moins, et
        // il ne compte jamais comme preuve du compte.
        'iban_partage' => ['poids' => 22, 'libelle' => 'IBAN connu, mais partagé par plusieurs comptes clients', 'origine' => 'SRC-O4-S20'],
        'iban_frequence' => ['poids' => 6, 'libelle' => 'Historique de règlements sur cet IBAN', 'origine' => 'SRC-O4-S04'],
        'reference_bordereau' => ['poids' => 25, 'libelle' => 'Référence de bout en bout reconnue', 'origine' => 'SRC-O4-S05'],
        'reference_facture' => ['poids' => 30, 'libelle' => 'Numéro de facture présent dans le libellé', 'origine' => 'SRC-O4-S06'],
        'montant_exact' => ['poids' => 30, 'libelle' => 'Montant expliqué exactement', 'origine' => 'SRC-O4-S07'],
        'montant_combinaison' => ['poids' => 28, 'libelle' => 'Combinaison de factures dont la somme tombe juste', 'origine' => 'SRC-O4-S08'],
        'serie_concordante' => ['poids' => 15, 'libelle' => 'Numéros de série concordants', 'origine' => 'SRC-O4-S09'],
        'immat_concordante' => ['poids' => 12, 'libelle' => 'Immatriculation concordante', 'origine' => 'SRC-O4-S10'],
        'societes_habituelles' => ['poids' => 5, 'libelle' => 'Sociétés déjà réglées par ce payeur', 'origine' => 'SRC-O4-S11'],
        'montant_dans_habitudes' => ['poids' => 4, 'libelle' => 'Montant conforme aux habitudes du payeur', 'origine' => 'SRC-O4-S12'],
        'nom_exact' => ['poids' => 16, 'libelle' => 'Nom du donneur d\'ordre identique au client facturé', 'origine' => 'SRC-O4-S13'],
        'nom_proche' => ['poids' => 7, 'libelle' => 'Nom du donneur d\'ordre proche du client facturé', 'origine' => 'SRC-O4-S13'],
        'siren_concordant' => ['poids' => 20, 'libelle' => 'Identifiant d\'entreprise concordant', 'origine' => 'SRC-O4-S14'],
        // Signaux NEGATIFS : ce sont eux qui font refuser.
        'nom_different' => ['poids' => -4, 'libelle' => 'Nom du donneur d\'ordre différent du client facturé', 'origine' => 'SRC-O4-S15'],
        'aucun_historique' => ['poids' => -8, 'libelle' => 'Aucun règlement antérieur de ce payeur', 'origine' => 'SRC-O4-S16'],
        'montant_inexplique' => ['poids' => -25, 'libelle' => 'Aucune combinaison de factures n\'explique le montant', 'origine' => 'SRC-O4-S17'],
        'serie_contradictoire' => ['poids' => -30, 'libelle' => 'Numéro de série contradictoire', 'origine' => 'SRC-O4-S18'],
        'hors_habitudes' => ['poids' => -6, 'libelle' => 'Montant très éloigné des habitudes du payeur', 'origine' => 'SRC-O4-S19'],
    ];

    /**
     * Les signaux qui prouvent QUEL COMPTE CLIENT doit etre credite.
     *
     * La distinction est le coeur de la reprise du 07/09/2026. Un IBAN, un nom
     * de donneur d'ordre, un historique de reglements disent qui paie. Ils ne
     * disent pas laquelle des creances ouvertes est reglee, ni sous quel code
     * client. Une affectation comptable a besoin des deux.
     *
     * @var list<string>
     */
    public const SIGNAUX_DE_COMPTE = [
        'reference_facture',
        'reference_bordereau',
        'montant_exact',
        'montant_combinaison',
        'serie_concordante',
        'immat_concordante',
        'siren_concordant',
        'societes_habituelles',
    ];

    /**
     * Les signaux qui, presents, interdisent l'affectation automatique.
     *
     * Ce ne sont pas des penalites de score : ce sont des arrets. Un score
     * eleve par ailleurs ne les efface pas.
     *
     * Un nom de donneur d'ordre different du compte facture n'en fait PAS
     * partie, et c'est un choix. Un conjoint, un employeur, un tiers payeur
     * reglent tous les jours pour quelqu'un d'autre : la difference de nom est
     * la regle, pas l'anomalie. Elle coute quelques points, elle n'arrete rien.
     *
     * @var list<string>
     */
    /**
     * Les signaux qui etablissent QUI paie, de facon non equivoque.
     *
     * Sans l'un d'eux au moins, un montant qui tombe juste n'est qu'une
     * coincidence arithmetique : sur soixante mille factures ouvertes, il s'en
     * trouve toujours une du bon montant. L'identite du payeur doit etre
     * ancree ailleurs que dans le montant.
     *
     * @var list<string>
     */
    public const SIGNAUX_D_IDENTITE = [
        'iban_connu',
        'reference_facture',
        'reference_bordereau',
        'siren_concordant',
        'nom_exact',
    ];

    public const SIGNAUX_CONTRADICTOIRES = [
        'serie_contradictoire',
        'montant_inexplique',
    ];

    /**
     * Au-dessus : affectation automatique. Origine SRC-O4-S01.
     *
     * Le seuil a ete abaisse de 95 a 75 le 07/09/2026, et ce n'est pas un
     * relachement : c'est un deplacement. Ce qui protege l'affectation n'est
     * pas la hauteur de l'indice, c'est la MARGE sur le second candidat,
     * l'absence de contradiction, une identite de payeur etablie et une
     * composition qui tombe juste. Un indice de 95 sans marge est dangereux ;
     * un indice de 78 avec cinquante points d'ecart et une facture qui tombe
     * a l'euro ne l'est pas.
     */
    public const SEUIL_AUTOMATIQUE = 75;

    /** Entre les deux : proposition soumise au comptable. */
    public const SEUIL_VALIDATION = 70;

    /** En dessous : aucune affectation proposée. */
    public const SEUIL_REJET = 40;

    /**
     * Ecart minimal entre les deux meilleurs candidats.
     *
     * En dessous, l'outil ne tranche pas. C'est une regle de prudence, pas une
     * limite technique : deux hypotheses qui se tiennent doivent revenir au
     * professionnel. Origine SRC-O4-S02.
     */
    public const ECART_MINIMAL_CANDIDATS = 3;

    /**
     * Marge exigee pour affecter SANS main humaine.
     *
     * Trois points suffisent a dire que deux hypotheses ne sont pas a egalite.
     * Ils ne suffisent pas a se passer d'un comptable. L'ecart demande pour
     * une affectation automatique est donc bien plus large : il doit
     * correspondre a au moins un signal fort que le second candidat n'a pas.
     */
    public const MARGE_AUTOMATIQUE = 14;

    /**
     * Marge exigee quand le compte bancaire est partage par plusieurs comptes
     * clients. Elle vaut au moins le poids d'un signal decisif -- un numero de
     * facture cite, une combinaison qui tombe juste sur un seul des comptes.
     */
    public const MARGE_IBAN_PARTAGE = 24;

    /** Tolerance de rapprochement d'un montant, en euros. */
    public const TOLERANCE_MONTANT = 0.01;

    /** Au-dela, on ne cherche plus de combinaison : le temps de calcul explose. */
    public const COMBINAISON_MAX_FACTURES = 9;
    public const COMBINAISON_BUDGET_MS = 120;

    /** Score borne a 100 : un score de 130 n'aurait aucun sens a l'affichage. */
    public static function borner(int $points): int
    {
        return max(0, min(100, $points));
    }

    /**
     * La decision, et ce qui la retient.
     *
     * L'indice de confiance ne decide plus seul. Trois grandeurs entrent :
     * l'indice du premier candidat, la MARGE qui le separe du second, et la
     * presence d'une contradiction ou d'une preuve du compte a crediter.
     *
     * L'ordre des regles n'est pas indifferent : les arrets passent avant les
     * seuils. Un indice de 100 ne rattrape pas un montant que rien n'explique.
     *
     * @param array{
     *   composition: bool,
     *   composition_unique: bool,
     *   preuve_compte: bool,
     *   identite: bool,
     *   contradiction: bool,
     *   iban_partage: bool
     * } $contexte
     *
     * @return array{decision: string, motif: string}
     */
    public static function decider(int $score, ?int $scoreSuivant, array $contexte, ?int $marge = null): array
    {
        // La marge se mesure sur les points BRUTS, avant le plafond de 100.
        // Sans cela, le plafond ecraserait justement le signal decisif : deux
        // candidats a 130 et 83 points se retrouvaient a 100 et 83, et un ecart
        // de quarante-sept points se lisait dix-sept.
        $marge ??= null === $scoreSuivant ? $score : $score - $scoreSuivant;

        // 1. Rien n'explique le montant : on ne propose pas de creance.
        if (!$contexte['composition']) {
            return [
                'decision' => Decision::REFUS,
                'motif' => 'Aucun sous-ensemble de factures ouvertes ne compose ce montant.',
            ];
        }

        // 2. Un signal contradictoire arrete l'automatisation, quel que soit l'indice.
        if ($contexte['contradiction']) {
            return [
                'decision' => $score >= self::SEUIL_REJET ? Decision::EXCEPTION : Decision::REFUS,
                'motif' => "Un signal contradictoire s'oppose à cette hypothèse.",
            ];
        }

        // 3. Deux hypotheses a egalite : l'outil ne tranche jamais seul.
        if (null !== $scoreSuivant && $marge < self::ECART_MINIMAL_CANDIDATS) {
            return [
                'decision' => Decision::EXCEPTION,
                'motif' => sprintf('Deux candidats séparés par %d point%s : la règle interdit de trancher.',
                    $marge, abs($marge) > 1 ? 's' : ''),
            ];
        }

        // 4. Compte bancaire partage : il identifie le payeur, pas le compte a
        //    crediter. Sans ecart franc apporte par un signal propre au compte,
        //    la decision revient au comptable.
        if ($contexte['iban_partage'] && $marge < self::MARGE_IBAN_PARTAGE) {
            return [
                'decision' => Decision::VALIDATION,
                'motif' => 'Compte bancaire partagé par plusieurs comptes clients : '
                    ."l'IBAN identifie le payeur, il ne prouve pas le compte à créditer.",
            ];
        }

        // 5. Plusieurs jeux de factures differents composent exactement le
        //    meme montant. Le payeur est connu, mais laquelle de ses creances
        //    est reglee ne l'est pas. Lettrer au hasard produirait un compte
        //    juste au total et faux ligne a ligne.
        if (!$contexte['composition_unique']) {
            return [
                'decision' => Decision::VALIDATION,
                'motif' => 'Plusieurs jeux de factures composent exactement ce montant : '
                    .'le payeur est identifié, le choix des créances revient au comptable.',
            ];
        }

        // 6. Affectation automatique. Quatre conditions, toutes necessaires :
        //    l'indice, la marge, une identite de payeur etablie, et un signal
        //    qui designe le compte a crediter.
        if ($score >= self::SEUIL_AUTOMATIQUE
            && $marge >= self::MARGE_AUTOMATIQUE
            && $contexte['identite']
            && $contexte['preuve_compte']) {
            return [
                'decision' => Decision::AUTOMATIQUE,
                'motif' => sprintf('Indice %d sur 100, marge de %d points, aucune contradiction.', $score, $marge),
            ];
        }

        if ($score >= self::SEUIL_VALIDATION) {
            if ($score >= self::SEUIL_AUTOMATIQUE && !$contexte['identite']) {
                $motif = "Le montant tombe juste, mais rien n'établit l'identité du payeur.";
            } elseif ($score >= self::SEUIL_AUTOMATIQUE && $marge < self::MARGE_AUTOMATIQUE) {
                $motif = sprintf('Indice élevé mais marge de %d points seulement au second candidat.', $marge);
            } else {
                $motif = "L'hypothèse tient, sans emporter la conviction seule.";
            }

            return ['decision' => Decision::VALIDATION, 'motif' => $motif];
        }

        if ($score >= self::SEUIL_REJET) {
            return ['decision' => Decision::EXCEPTION, 'motif' => 'Indice insuffisant pour proposer une affectation.'];
        }

        return ['decision' => Decision::REFUS, 'motif' => 'Aucune hypothèse plausible.'];
    }
}
