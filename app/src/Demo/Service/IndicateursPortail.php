<?php

declare(strict_types=1);

namespace App\Demo\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Les indicateurs affiches sur le portail des dix outils.
 *
 * Ils sont CALCULES a l'affichage, sur la base de demonstration. Aucun n'est
 * ecrit en dur : un chiffre du portail qui ne bougerait pas quand la donnee
 * bouge serait une affirmation, pas une mesure.
 */
final class IndicateursPortail
{
    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * @return list<array{valeur: string, libelle: string}>
     */
    public function pour(string $indicateur): array
    {
        try {
            return match ($indicateur) {
                'remboursements' => [
                    $this->i($this->compte('remboursement.dossier'), 'demandes déposées'),
                    $this->i($this->compte('remboursement.dossier', "statut IN ('refuse','doublon','fraude')"), 'arrêtées'),
                ],
                'relance' => [
                    $this->i($this->compte('creances_demo.balance_agee'), 'créances au portefeuille'),
                    $this->euro($this->somme('creances_demo.balance_agee', 'montant_solde'), 'encours suivi'),
                ],
                'lettrage' => [
                    $this->i($this->compte('lettrage.ecriture'), 'écritures au journal'),
                    $this->i($this->compte('lettrage.ecriture', 'lettrage IS NULL'), 'non lettrées'),
                ],
                'pilotage' => [
                    $this->euro($this->somme('pilotage.cause_ouverture', 'montant'), 'encours comptable'),
                    $this->i($this->compte('pilotage.cause_ouverture', 'NOT soldee_par_suite'), 'créances encore ouvertes'),
                ],
                'grands-comptes' => [
                    $this->i($this->compte('livraison.dossier'), 'dossiers de livraison'),
                ],
                'affectation' => [
                    $this->i($this->compte('affectation.virement'), 'virements à identifier'),
                    // La periode est CALCULEE : un flux annonce sans sa duree se
                    // lit comme un stock, et le chiffre devient faux a l'oreille.
                    $this->euro($this->somme('affectation.virement', 'montant'), 'reçus sur '.$this->mois().' mois'),
                ],
                'comites' => [
                    $this->i(42, 'motifs de non-encaissement'),
                    $this->i(6, 'familles'),
                ],
                'maturite' => [$this->i(7, 'axes mesurés')],
                'cartographie' => [$this->i(3, 'cycles'), $this->i(5, 'ruptures de responsabilité')],
                'cadrage' => [$this->i($this->compte('shared.etablissement'), 'établissements au périmètre')],
                default => [],
            };
        } catch (Throwable) {
            // Une table pas encore peuplee ne doit jamais casser le portail.
            return [];
        }
    }

    /** Nombre de mois couverts par les virements de la demonstration. */
    private function mois(): int
    {
        $bornes = $this->cnx->fetchAssociative(
            'SELECT min(date_operation) d1, max(date_operation) d2 FROM affectation.virement'
        );
        if (!\is_array($bornes) || null === $bornes['d1']) {
            return 0;
        }
        $d1 = new DateTimeImmutable((string) $bornes['d1']);
        $d2 = new DateTimeImmutable((string) $bornes['d2']);
        $ecart = $d1->diff($d2);

        return $ecart->y * 12 + $ecart->m + ($ecart->d > 15 ? 1 : 0);
    }

    private function compte(string $table, ?string $ou = null): int
    {
        $sql = "SELECT count(*) FROM {$table}".(null !== $ou ? " WHERE {$ou}" : '');

        return (int) $this->cnx->fetchOne($sql);
    }

    private function somme(string $table, string $colonne): float
    {
        return (float) $this->cnx->fetchOne("SELECT coalesce(sum({$colonne}), 0) FROM {$table}");
    }

    /** @return array{valeur: string, libelle: string} */
    private function i(int $n, string $libelle): array
    {
        return ['valeur' => number_format($n, 0, ',', ' '), 'libelle' => $libelle];
    }

    /** @return array{valeur: string, libelle: string} */
    private function euro(float $v, string $libelle): array
    {
        $valeur = abs($v) >= 1000000
            ? number_format($v / 1000000, 1, ',', ' ').' M€'
            : number_format($v / 1000, 0, ',', ' ').' k€';

        return ['valeur' => $valeur, 'libelle' => $libelle];
    }
}
