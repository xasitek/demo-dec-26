<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;

/**
 * Calcule la cle anti-doublon d'un dossier, alignee sur le comportement N8N
 * (arbitrage PO 2026-08-11) :
 *   - RACHAT SEC  : immatriculation normalisee (alphanumerique majuscule) ;
 *   - TROP-PERCU  : code ICAR normalise (chiffres, sans zeros de tete).
 * On prefere les valeurs CONTROLEES (IA/comptable) aux brutes. Vide => pas de cle
 * (pas de blocage anti-doublon possible tant que la donnee cle manque).
 */
final class CleDoublon
{
    public static function pour(Dossier $dossier): ?string
    {
        if (DossierMotif::RACHAT_SEC === $dossier->getMotif()) {
            $immat = self::normaliserImmatriculation((string) ($dossier->getControleImmatriculation() ?? $dossier->getImmatriculation() ?? ''));

            return '' === $immat ? null : $immat;
        }

        $icar = self::normaliserIcar((string) ($dossier->getControleIcar() ?? $dossier->getCodeIcar() ?? ''));

        return '' === $icar ? null : $icar;
    }

    public static function normaliserImmatriculation(string $valeur): string
    {
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $valeur) ?? '');

        return 'ERREUR' === $v ? '' : $v;
    }

    public static function normaliserIcar(string $valeur): string
    {
        $chiffres = preg_replace('/\D+/', '', $valeur) ?? '';

        return ltrim($chiffres, '0');
    }
}
