<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;

/**
 * Calcul de l'imputation comptable d'un dossier de remboursement (libelle, code
 * comptable, role tiers), a l'identique de l'ancien systeme N8N. Valeurs proposees
 * par defaut a la comptable (champs restant editables) :
 *
 *   - Libelle : "RACHAT SEC // {immat} - {ref}" ou "TROP PERCU // {code ICAR} - {ref}"
 *     ({ref} = reference SANS le prefixe "REMB-").
 *   - Code comptable : compte client 4111000 (fixe).
 *   - Role tiers : COMPTANT (fixe).
 */
final class ImputationComptable
{
    public const CODE_COMPTABLE = '4111000';
    public const ROLE_TIERS = 'COMPTANT';

    /**
     * @return array{libelle: string, codeComptable: string, roleTiers: string}
     */
    public function pour(Dossier $dossier): array
    {
        return [
            'libelle' => $this->libelle($dossier),
            'codeComptable' => self::CODE_COMPTABLE,
            'roleTiers' => self::ROLE_TIERS,
        ];
    }

    private function libelle(Dossier $dossier): string
    {
        $ref = preg_replace('/^REMB-/i', '', $dossier->getReference()) ?? $dossier->getReference();

        if (DossierMotif::RACHAT_SEC === $dossier->getMotif()) {
            $immat = trim((string) ($dossier->getValideImmatriculation() ?: $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation()));

            return sprintf('RACHAT SEC // %s - %s', $immat, $ref);
        }

        // Code ICAR : valeur validee ou detectee par l'IA — JAMAIS la saisie secretaire
        // (si l'IA ne l'a pas trouve, le libelle reste sans cle jusqu'a saisie comptable).
        $icar = trim((string) ($dossier->getValideIcar() ?: $dossier->getControleIcar()));

        return sprintf('TROP PERCU // %s - %s', $icar, $ref);
    }
}
