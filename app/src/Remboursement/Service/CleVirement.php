<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;

/**
 * Cle de detection de DOUBLON DE VIREMENT (eviter un double-paiement), distincte de la
 * cle anti-doublon d'ingestion (CleDoublon) :
 *   - rachat sec : immatriculation SEULE (un buy-back par vehicule) ;
 *   - trop-percu : code ICAR + CLIENT (le code ICAR se repete souvent -> on ajoute le
 *     client pour ne pas sur-signaler).
 *
 * L'etablissement et le montant n'entrent PAS dans la cle (on signale large ; c'est un
 * avertissement, pas un blocage). Valeurs RETENUES (valide ?: controle ?: saisie).
 * Vide si la donnee cle manque (aucun doublon possible).
 */
final class CleVirement
{
    public static function pour(Dossier $dossier): string
    {
        if (DossierMotif::RACHAT_SEC === $dossier->getMotif()) {
            return self::alnum($dossier->getValideImmatriculation() ?: $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation());
        }

        $icar = self::alnum($dossier->getValideIcar() ?: $dossier->getControleIcar() ?: $dossier->getCodeIcar());
        if ('' === $icar) {
            return '';
        }

        return $icar.'|'.self::alnum($dossier->getValideNom() ?: $dossier->getControleNom() ?: $dossier->getNomClient());
    }

    private static function alnum(?string $valeur): string
    {
        $valeur = strtoupper(trim((string) $valeur));
        $translit = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur);
        if (false !== $translit) {
            $valeur = $translit;
        }

        return preg_replace('/[^A-Z0-9]/', '', $valeur) ?? '';
    }
}
