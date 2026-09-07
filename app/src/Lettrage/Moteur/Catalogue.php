<?php

declare(strict_types=1);

namespace App\Lettrage\Moteur;

/**
 * Le catalogue des vingt-huit methodes de lettrage.
 *
 * C'est une DONNEE, pas du code enfoui : l'objet de chaque methode, les
 * donnees qu'elle lit, ses conditions d'entree, sa regle, sa tolerance, son
 * garde-fou, ce qu'elle peut decider, le cas ou elle doit refuser, et son rang
 * dans la cascade. Un examinateur doit pouvoir lire la methode avant de lire le
 * resultat.
 *
 * Le principe directeur est le CRESCENDO : on lettre du plus sur au plus
 * permissif, et chaque methode ne voit que les lignes que les precedentes n'ont
 * pas consommees. L'ordre n'est pas cosmetique -- il decide quelle methode
 * recupere quelle ligne.
 *
 * Quatre methodes ne sont PAS executees dans la demonstration, et le catalogue
 * le dit. Annoncer vingt-huit methodes executees quand quatre ne le sont pas
 * serait la premiere chose qu'un examinateur verifierait.
 */
final class Catalogue
{
    /** @return list<array<string, mixed>> */
    public static function toutes(): array
    {
        return [
            self::m('M14', 'Petit compte rapproché de sa fiche de dossier', 1, false, 'SRC-O5-M14',
                'Réimputer un paiement ou une reprise vers la facture de vente du même dossier.',
                'Petit compte client, fiche de dossier de vente externe.',
                'Le dossier de vente est soldé.',
                'La jambe cible reste ouverte : déplacer pour solder, pas pour déplacer.',
                'Aucune : appariement de dossier, pas d\'écart.',
                'Refus si le dossier n\'est pas soldé.',
                'Réimputation.', 'Dossier non soldé.',
                "Elle lit une source de dossiers extérieure à l'application ; la démonstration ne sort pas du réseau."),

            self::m('M15', 'Reprise depuis le référentiel de dossiers', 2, false, 'SRC-O5-M15',
                'Réconcilier un dossier de vente bouclé par lien immatriculation ou numéro de série.',
                'Référentiel de dossiers de vente.',
                'Dossier bouclé, lien véhicule établi.',
                'Même logique que M14, sur une autre source.',
                'Aucune.', 'Refus si le lien véhicule est absent.',
                'Réimputation.', 'Lien véhicule absent.',
                'Même raison que M14 : source externe.'),

            self::m('M13', 'Même tiers sur deux comptes comptables', 3, true, 'SRC-O5-M13',
                'Apparier un débit et un crédit du même tiers portés sur deux comptes différents.',
                'Écritures du même code client, comptes 4111 et 4114.',
                'Deux comptes comptables distincts, un seul tiers.',
                'Appariement un pour un, puis écriture de reclassement.',
                'Barème par ancienneté.',
                'Tiers strictement identique exigé.',
                'Lettrage avec reclassement.', 'Tiers différents.'),

            self::m('M01', 'Montant exact sur clé forte', 4, true, 'SRC-O5-M01',
                'Le cœur du dispositif : un débit contre un crédit, une clé forte et un montant quasi exact.',
                'Numéro de série, immatriculation, ordre de réparation, référence de pièce, montant, date.',
                'Au moins une clé forte commune aux deux lignes.',
                'Appariement un pour un ; écart accepté selon l\'ancienneté.',
                '5 € à moins de 2 mois, jusqu\'à 500 € au-delà de 2 ans.',
                'Aucune clé forte, aucun lettrage.',
                'Lettrage, ou proposition.', 'Aucune clé forte, ou écart hors barème.'),

            self::m('M00', 'Nettoyage des comptes d\'attente', 5, true, 'SRC-O5-M00',
                "Vider les comptes d'attente anciens dont le solde est négligeable.",
                'Comptes techniques d\'attente, solde, date de la dernière ligne.',
                'Compte d\'attente, plus de six mois, solde au plus 20 €.',
                'Lettrage du solde et écriture de perte ou de profit.',
                '20 € de solde résiduel.',
                'Un compte d\'attente ne peut jamais être apparié à un autre compte d\'attente.',
                'Apurement.', 'Solde au-dessus du seuil, ou compte récent.'),

            self::m('M02', 'Référence partagée, groupes transitifs', 6, true, 'SRC-O5-M02',
                'Former des groupes par transitivité : si A partage une clé avec B et B avec C, les trois se tiennent.',
                'Références de pièce, toutes sociétés et tous comptes.',
                'Une clé partagée par au moins deux lignes.',
                'Union de proche en proche, puis recherche d\'un sous-ensemble équilibré.',
                '10 € à moins de 2 mois, jusqu\'à 500 € au-delà de 2 ans.',
                'Une clé portée par plus de 10 lignes est écartée ; un groupe de plus de 15 lignes est écarté.',
                'Lettrage de groupe.', 'Clé générique, ou groupe démesuré.'),

            self::m('M03', 'Numéro de série', 7, true, 'SRC-O5-M03',
                'Apparier par numéro de série commun, un pour un et plusieurs pour plusieurs.',
                'Numéro de série sur les huit derniers caractères.',
                'Numéro de série présent des deux côtés.',
                'Appariement sur clé véhicule, écart selon l\'ancienneté.',
                'Barème par ancienneté.',
                'Un numéro de série rattaché à un autre compte client arrête tout.',
                'Lettrage.', 'Numéro de série contradictoire.'),

            self::m('M04', 'Immatriculation', 8, true, 'SRC-O5-M04',
                'Même logique que M03, sur immatriculation normalisée.',
                'Immatriculation, format en vigueur ou ancien.',
                'Immatriculation présente des deux côtés.',
                'Appariement sur clé véhicule.',
                'Barème par ancienneté.',
                'Immatriculation appartenant à un autre compte.',
                'Lettrage.', 'Immatriculation contradictoire.'),

            self::m('M05', 'Numéro d\'ordre de réparation', 9, true, 'SRC-O5-M05',
                'Même logique, sur le numéro d\'ordre de réparation.',
                'Numéro officiel, ou contexte du libellé.',
                'Numéro présent des deux côtés, hors valeurs nulles.',
                'Appariement sur clé atelier.',
                'Barème par ancienneté.',
                'Les valeurs 0, 00, 000 sont exclues : elles ne prouvent rien.',
                'Lettrage.', 'Numéro exclu ou absent.'),

            self::m('M06', 'Sous-ensembles équilibrés', 10, true, 'SRC-O5-M06',
                "Une ligne égale la somme d'un sous-ensemble de l'autre sens, dans le même compte client.",
                'Montants en centimes, sens, dates, clés véhicule.',
                'Au moins deux lignes de sens opposé sur le même compte.',
                'Recherche combinatoire bornée : 10 lignes par côté, budget de temps.',
                '5 € sur le solde du groupe.',
                'Si les deux côtés portent des numéros de série, leur concordance devient obligatoire.',
                'Lettrage de groupe, ou renvoi à l\'humain.',
                'Numéro de série contradictoire, budget épuisé, ou deux sous-ensembles équilibrent sans signal discriminant.'),

            self::m('M07', 'Solde net entre sociétés', 11, true, 'SRC-O5-M07',
                'Solder la position nette entre deux sociétés du groupe.',
                'Comptes de liaison, sociétés, montants.',
                'Au moins deux sociétés, solde net au plus 5 €.',
                'Jeu d\'écritures sur les comptes de liaison.',
                '5 € de solde net.',
                'Le compte de liaison technique reste volontairement ouvert.',
                'Lettrage avec écritures de liaison.', 'Solde net au-dessus du seuil.'),

            self::m('M08', 'Écarts selon l\'ancienneté', 12, true, 'SRC-O5-M08',
                'Apurer un solde résiduel quand il tient dans le barème de retard, sinon apparier partiellement.',
                'Solde du compte, code de retard, dates.',
                'Solde non nul, compte identifié.',
                'Apurement si le solde tient dans le barème, sinon appariement partiel des paires les plus serrées.',
                'Codes A à C 5 €, D 10 €, E 50 €, F 200 €, G 500 €.',
                'Jamais au-delà du barème du code de retard constaté.',
                'Apurement, appariement partiel, ou refus.',
                'Solde hors barème.'),

            self::m('M09', 'Acomptes dormants', 13, true, 'SRC-O5-M09',
                'Solder les comptes dont le solde égale un acompte connu et dormant.',
                'Montants d\'acompte du secteur, date de la dernière ligne.',
                'Solde égal à un acompte connu, dernière ligne de plus de quatre mois.',
                'Lettrage du solde.',
                'Montant strictement égal à l\'acompte.',
                'Un acompte récent n\'est pas dormant.',
                'Apurement.', 'Montant différent, ou compte actif.'),

            self::m('M10', 'Ligne mal affectée et comptes opposés', 14, true, 'SRC-O5-M10',
                "Retrouver la ligne d'un autre compte qui compense le solde, ou deux comptes à soldes opposés.",
                'Soldes par compte, noms de clients, dates.',
                'Un solde compensable par une ligne d\'un compte voisin.',
                'Score sur trois niveaux : au-dessus de 85 automatique, de 50 à 84 assistance, en dessous rejet.',
                '2 € pour une ligne mal affectée, 5 € pour deux comptes opposés.',
                'Un prénom seul et une date ne suffisent jamais.',
                'Réaffectation, assistance, ou rejet.', 'Score inférieur à 50.'),

            self::m('M12', 'Doublons de comptes clients', 15, true, 'SRC-O5-M12',
                'Rapprocher deux comptes clients ouverts pour la même personne.',
                'Soldes opposés, noms, clés, préfixes de code.',
                'Deux comptes d\'une même société, soldes opposés à 2 € près.',
                'Lettrage croisé des deux comptes.',
                '2 € sur la somme des deux soldes.',
                'Un renforcement par clé ou par préfixe de code est obligatoire.',
                'Lettrage croisé.', 'Aucun renforcement disponible.'),

            self::m('M17', 'Écritures anciennes', 16, true, 'SRC-O5-M17',
                'Apurer les résidus portés par des lignes de plus de deux ans.',
                'Dates, soldes, proportions.',
                'Ligne la plus ancienne au-delà de 730 jours.',
                'Apurement si l\'écart reste cohérent.',
                'Moins de 20 % et au plus 1 000 €.',
                'Exception assumée à la borne de prescription : elle est déclarée, pas cachée.',
                'Apurement.', 'Écart disproportionné.'),

            self::m('M18', 'Comptes clients fermés', 17, true, 'SRC-O5-M18',
                'Apurer les comptes clos et transférer leur solde vers le compte vivant.',
                'Préfixe de compte fermé, comptes du même client.',
                'Compte marqué comme fermé.',
                'Un croisement entre compte fermé et compte vivant transfère la totalité du côté fermé.',
                'Aucune : transfert intégral.',
                'Le compte fermé doit se vider entièrement, sinon rien.',
                'Transfert et apurement.', 'Transfert partiel impossible.'),

            self::m('M19', 'Liaisons entre établissements', 18, true, 'SRC-O5-M19',
                'Lettrer les écritures de liaison entre deux établissements d\'une même société.',
                'Comptes de liaison, établissements.',
                'Deux établissements, une société.',
                'Appariement des deux jambes de liaison.',
                '5 € de solde net.',
                'Jamais entre sociétés différentes : ce serait M07.',
                'Lettrage.', 'Sociétés différentes.'),

            self::m('M20', 'Nom de client unique', 19, true, 'SRC-O5-M20',
                "Apurer en s'appuyant sur l'unicité d'un nom dans une société.",
                'Noms de clients, soldes.',
                'Le nom n\'apparaît qu\'une fois dans la société.',
                'Appariement des lignes du même nom.',
                'Barème par ancienneté.',
                'Un nom porté par plusieurs comptes disqualifie la méthode.',
                'Apurement.', 'Nom non unique.'),

            self::m('M21', 'Réaffectation d\'un paiement unique', 20, true, 'SRC-O5-M21',
                'Réaffecter un paiement orphelin vers une facture du même nom de client.',
                'Paiements sans contrepartie, factures ouvertes.',
                'Un seul paiement orphelin, une seule facture candidate.',
                'Réaffectation puis lettrage.',
                'Barème par ancienneté.',
                'Plusieurs factures candidates renvoient à l\'humain.',
                'Réaffectation.', 'Plusieurs candidats.'),

            self::m('M22', 'Solde client nul', 21, true, 'SRC-O5-M22',
                'Lettrer les comptes dont le solde net est nul.',
                'Soldes par compte client.',
                'Solde net strictement nul.',
                'Lettrage de la totalité des lignes du compte.',
                'Zéro : le solde doit être nul.',
                'Exclut les comptes d\'attente.',
                'Lettrage global.', 'Solde non nul.'),

            self::m('M23', 'Garanties constructeur', 22, true, 'SRC-O5-M23',
                'Apparier une facture de garantie et le règlement du constructeur.',
                'Compte de garantie, ordre de réparation, numéro de série.',
                'Clé véhicule ou atelier commune.',
                'Lettrage, petit écart en charge, gain en produit avec taxe collectée.',
                'Écart au plus 500 € sur le compte de garantie.',
                "Au-delà de 500 €, c'est une anomalie à instruire, pas un lettrage.",
                'Lettrage avec écriture d\'écart.', 'Écart au-delà de 500 €.'),

            self::m('M24', 'Fusion des résiduels par client', 23, true, 'SRC-O5-M24',
                'Nettoyage final : lettrer en bloc les faibles résidus par société, compte et client.',
                'Résidus par triplet société, compte, client.',
                'Solde résiduel faible.',
                'Lettrage en bloc, barème progressif selon l\'ancienneté.',
                '20 € à 3 mois, 30 € à 6 mois, 50 € à 12 mois, 200 € à 2 ans, 500 € au-delà.',
                'Exclut les comptes d\'attente et les comptes fermés.',
                'Apurement en bloc.', 'Résidu au-dessus du barème.'),

            self::m('M26', 'Dossier comptant soldé', 24, true, 'SRC-O5-M26',
                'Lettrer un dossier client comptant qui solde exactement.',
                'Dossier, écritures de vente et de règlement.',
                'Dossier comptant, solde exact.',
                'Lettrage du dossier entier.',
                'Solde exact exigé.',
                'Un dossier partiellement soldé n\'entre pas.',
                'Lettrage de dossier.', 'Solde inexact.'),

            self::m('M11', 'Reclassement financeur', 25, true, 'SRC-O5-M11',
                'Reclasser les lignes de financement restantes vers le compte fournisseur du financeur.',
                'Journal de financement, référentiel de financeurs, libellés.',
                'Ligne de financement de plus de dix jours, non lettrée.',
                'Reclassement mono-ligne ; la jambe fournisseur reste ouverte.',
                'Aucune : ce n\'est pas un appariement.',
                "Placée en dernier, et c'est un choix : avant, elle priverait toutes les autres méthodes de lignes lettrables.",
                'Reclassement.', 'Financeur non identifiable.'),

            self::m('M25', 'Garantie regroupée par véhicule', null, true, 'SRC-O5-M25',
                'Regrouper les dossiers de garantie par véhicule, sur une clé unique.',
                'Colonnes officielles de numéro de série, immatriculation, ordre de réparation.',
                'Une clé véhicule unique identifiable.',
                "Clé prioritaire : numéro de série, puis immatriculation, puis chiffres d'ordre de réparation seulement si ni l'un ni l'autre. Deux passes : par société, puis sur le résidu.",
                'Écart au plus 500 € sur le compte de garantie.',
                "L'union par transitivité de plusieurs clés est ABANDONNEE : elle enchaînait des dossiers différents. L'ordre de réparation seul se réutilise entre constructeurs et sur-fusionne.",
                'Lettrage par véhicule.', 'Aucune clé unique disponible.'),

            self::m('M27', 'Garantie, contrepartie contre règlements', null, true, 'SRC-O5-M27',
                'Apparier les débits de contrepartie de garantie avec les règlements reçus.',
                'Débits de contrepartie, règlements bancaires, même tiers.',
                'Même tiers, sens opposés.',
                'Sous-ensembles bidirectionnels : un débit peut correspondre à plusieurs règlements, et l\'inverse.',
                '50 € sur le solde du groupe.',
                "Le un contre un exact n'en attrape qu'une fraction : les montants sont des lots différents de chaque côté.",
                'Lettrage de groupe.', 'Aucun sous-ensemble équilibré.'),

            self::m('M16', 'Assistance à la validation', null, false, 'SRC-O5-M16',
                'Relire les groupes marqués à valider et trancher.',
                'Groupes en attente de validation.',
                'Groupe marqué à valider par une méthode précédente.',
                'Soumission de chaque groupe à un modèle pour décision finale.',
                'Sans objet.',
                'Sans clé d\'accès au modèle, la méthode est sautée : elle ne devine pas.',
                'Validation, ou renvoi à l\'humain.', 'Modèle indisponible.',
                "Elle appelle un service distant. L'environnement de démonstration ne sort pas du réseau : son rôle est tenu par la file d'attente humaine."),
        ];
    }

    /**
     * Les methodes de la cascade, dans l'ordre d'execution.
     *
     * @return list<array<string, mixed>>
     */
    public static function cascade(): array
    {
        $c = array_values(array_filter(self::toutes(), static fn (array $m): bool => null !== $m['rang'] && $m['executee']));
        usort($c, static fn (array $a, array $b): int => $a['rang'] <=> $b['rang']);

        return $c;
    }

    /** @return array<string, mixed>|null */
    public static function parCode(string $code): ?array
    {
        foreach (self::toutes() as $m) {
            if ($m['code'] === $code) {
                return $m;
            }
        }

        return null;
    }

    /** @return array{executees: int, declarees: int} */
    public static function compte(): array
    {
        $t = self::toutes();

        return [
            'declarees' => \count($t),
            'executees' => \count(array_filter($t, static fn (array $m): bool => $m['executee'])),
        ];
    }

    /** @return array<string, mixed> */
    private static function m(
        string $code, string $nom, ?int $rang, bool $executee, string $origine,
        string $objet, string $donnees, string $entree, string $regle,
        string $tolerance, string $gardeFou, string $decisions, string $refus,
        string $raisonNonExecutee = '',
    ): array {
        return [
            'code' => $code, 'nom' => $nom, 'rang' => $rang, 'executee' => $executee,
            'origine' => $origine, 'objet' => $objet, 'donnees' => $donnees,
            'entree' => $entree, 'regle' => $regle, 'tolerance' => $tolerance,
            'garde_fou' => $gardeFou, 'decisions' => $decisions, 'refus' => $refus,
            'raison_non_executee' => $raisonNonExecutee,
        ];
    }
}
