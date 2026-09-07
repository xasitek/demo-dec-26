<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Service\Ia\NormalisateurControle;

/**
 * Detecte si l'IBAN ou le MONTANT d'un dossier a ete MODIFIE entre les trois etapes :
 * saisie secretaire (brut) -> extraction IA (controle) -> validation comptable (valide).
 *
 * Sert au poste Paiements (manager) : si une etape a change une de ces deux valeurs
 * sensibles, il doit le voir. La comparaison porte sur les formes NORMALISEES
 * (NormalisateurControle) ; seules les etapes RENSEIGNEES entrent en compte (une IA ou
 * une validation encore absente ne compte pas comme un ecart). Les valeurs affichees
 * (tooltip) sont les IBAN MASQUES (RGPD) et le montant formate.
 */
final class EcartControle
{
    /**
     * @return array{
     *     diverge: bool,
     *     iban: array{diverge: bool, saisie: ?string, ia: ?string, valide: ?string},
     *     montant: array{diverge: bool, saisie: ?string, ia: ?string, valide: ?string},
     * }
     */
    public static function pour(Dossier $d): array
    {
        $ibanDiverge = self::ibanDiverge($d->getIbanClient(), $d->getControleIban(), $d->getValideIban());
        $montantDiverge = self::montantDiverge($d->getMontant(), $d->getControleMontant(), $d->getValideMontant());

        return [
            'diverge' => $ibanDiverge || $montantDiverge,
            'iban' => [
                'diverge' => $ibanDiverge,
                'saisie' => null !== $d->getIbanClient() ? $d->getIbanClientMasque() : null,
                'ia' => null !== $d->getControleIban() ? $d->getControleIbanMasque() : null,
                'valide' => null !== $d->getValideIban() ? $d->getValideIbanMasque() : null,
            ],
            'montant' => [
                'diverge' => $montantDiverge,
                'saisie' => self::montantAffiche($d->getMontant()),
                'ia' => self::montantAffiche($d->getControleMontant()),
                'valide' => self::montantAffiche($d->getValideMontant()),
            ],
        ];
    }

    public static function ibanDiverge(?string $saisie, ?string $ia, ?string $valide): bool
    {
        return self::divergent([
            NormalisateurControle::iban($saisie),
            NormalisateurControle::iban($ia),
            NormalisateurControle::iban($valide),
        ]);
    }

    public static function montantDiverge(?string $saisie, ?string $ia, ?string $valide): bool
    {
        return self::divergent([
            NormalisateurControle::montant($saisie),
            NormalisateurControle::montant($ia),
            NormalisateurControle::montant($valide),
        ]);
    }

    /**
     * Ecart s'il existe, parmi les valeurs RENSEIGNEES (non vides), au moins deux
     * valeurs distinctes.
     *
     * @param list<?string> $valeurs
     */
    private static function divergent(array $valeurs): bool
    {
        $presents = array_unique(array_filter($valeurs, static fn (?string $v): bool => null !== $v && '' !== $v));

        return \count($presents) > 1;
    }

    private static function montantAffiche(?string $valeur): ?string
    {
        $n = NormalisateurControle::montant($valeur);

        return null === $n ? null : number_format((float) $n, 2, ',', ' ').' €';
    }
}
