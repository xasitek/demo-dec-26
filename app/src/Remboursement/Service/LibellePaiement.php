<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;

/**
 * Libelle de paiement STRUCTURE et deterministe, appose sur les fichiers generes
 * (SEPA + OD) : `{RC|TP}-{ETABLISSEMENT}-{MONTANT}-{CLIENT}`. Deux dossiers avec le
 * meme libelle = doublon quasi-certain (meme type + etablissement + montant + client),
 * ce qui rend les doublons et anomalies detectables au coup d'oeil et automatiquement.
 *
 * Utilise les valeurs RETENUES (valide_*). Composants nettoyes (MAJUSCULES, sans accents
 * ni separateurs parasites) pour un libelle stable et comparable.
 */
final class LibellePaiement
{
    public static function pour(Dossier $dossier, string $etablissement): string
    {
        $rachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();
        $type = $rachat ? 'RC' : 'TP';
        $etab = self::nettoyer($etablissement);
        $montant = number_format((float) ($dossier->getValideMontant() ?: $dossier->getControleMontant() ?: $dossier->getMontant()), 2, '.', '');
        $client = self::nettoyer((string) ($dossier->getValideNom() ?: $dossier->getControleNom() ?: $dossier->getNomClient()));
        $cle = self::nettoyer((string) ($rachat
            ? ($dossier->getValideImmatriculation() ?: $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation())
            : ($dossier->getValideIcar() ?: $dossier->getControleIcar() ?: $dossier->getCodeIcar())));

        return sprintf('%s-%s-%s-%s-%s', $type, $etab, $montant, $client, $cle);
    }

    /** MAJUSCULES, sans accents ni caracteres speciaux (les tirets deviennent espaces). */
    private static function nettoyer(string $valeur): string
    {
        $valeur = trim($valeur);
        $translit = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur);
        if (false !== $translit) {
            $valeur = $translit;
        }
        $valeur = preg_replace('/[^A-Za-z0-9 ]+/', ' ', strtoupper($valeur)) ?? $valeur;

        return trim(preg_replace('/\s+/', ' ', $valeur) ?? $valeur);
    }
}
