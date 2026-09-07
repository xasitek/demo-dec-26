<?php

declare(strict_types=1);

namespace App\Remboursement\Enum;

/**
 * Vue SIMPLIFIEE des statuts pour la secretaire : regroupe les 16 etats internes
 * (DossierStatut) en quelques libelles clairs, orientes "ou en est mon dossier".
 * Sert aux badges de couleur et au filtre de "Mes dossiers".
 */
enum StatutSecretaire: string
{
    case DEPOSE = 'depose';
    case ANALYSE = 'analyse';
    case VERIFICATION = 'verification';
    case CORRECTION = 'correction';
    case ATTENTE_DIRECTEUR = 'attente_directeur';
    case VALIDE = 'valide';
    case PAIEMENT = 'paiement';
    case PAYE = 'paye';
    case REFUSE = 'refuse';
    case BROUILLON = 'brouillon';

    public function libelle(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::DEPOSE => 'Déposé',
            self::ANALYSE => 'Analyse IA en cours',
            self::VERIFICATION => 'Vérification comptable',
            self::CORRECTION => 'Correction requise',
            self::ATTENTE_DIRECTEUR => 'En attente de validation du directeur',
            self::VALIDE => 'Dossier validé',
            self::PAIEMENT => 'En cours de paiement',
            self::PAYE => 'Payé',
            self::REFUSE => 'Refusé',
        };
    }

    /** Cle de couleur du badge (mappee en classes dans le template). */
    public function couleur(): string
    {
        return match ($this) {
            self::BROUILLON, self::DEPOSE => 'neutre',
            self::ANALYSE => 'ia',
            self::VERIFICATION => 'comptable',
            self::CORRECTION => 'correction',
            self::ATTENTE_DIRECTEUR => 'directeur',
            self::VALIDE => 'valide',
            self::PAIEMENT => 'paiement',
            self::PAYE => 'paye',
            self::REFUSE => 'refuse',
        };
    }

    /**
     * Etats internes regroupes sous ce statut secretaire (pour le filtre).
     *
     * @return list<DossierStatut>
     */
    public function statuts(): array
    {
        return match ($this) {
            self::BROUILLON => [DossierStatut::BROUILLON],
            self::DEPOSE => [DossierStatut::DEPOSE],
            self::ANALYSE => [DossierStatut::EXTRACTION_IA],
            self::VERIFICATION => [DossierStatut::A_VERIFIER, DossierStatut::CAS_ICAR],
            self::CORRECTION => [DossierStatut::COMPLEMENT_REQUIS],
            self::ATTENTE_DIRECTEUR => [DossierStatut::A_VALIDER_DIRECTEUR],
            self::VALIDE => [DossierStatut::VALIDE_DIRECTEUR, DossierStatut::CONFIRME],
            self::PAIEMENT => [DossierStatut::GENERATION_EN_COURS, DossierStatut::ERREUR_GENERATION],
            self::PAYE => [DossierStatut::PAYE, DossierStatut::LETTRE],
            self::REFUSE => [DossierStatut::REFUSE, DossierStatut::DOUBLON, DossierStatut::FRAUDE],
        };
    }

    public static function pour(DossierStatut $statut): self
    {
        return match ($statut) {
            DossierStatut::BROUILLON => self::BROUILLON,
            DossierStatut::DEPOSE => self::DEPOSE,
            DossierStatut::EXTRACTION_IA => self::ANALYSE,
            DossierStatut::A_VERIFIER, DossierStatut::CAS_ICAR => self::VERIFICATION,
            DossierStatut::COMPLEMENT_REQUIS => self::CORRECTION,
            DossierStatut::A_VALIDER_DIRECTEUR => self::ATTENTE_DIRECTEUR,
            DossierStatut::VALIDE_DIRECTEUR, DossierStatut::CONFIRME => self::VALIDE,
            DossierStatut::GENERATION_EN_COURS, DossierStatut::ERREUR_GENERATION => self::PAIEMENT,
            DossierStatut::PAYE, DossierStatut::LETTRE => self::PAYE,
            DossierStatut::REFUSE, DossierStatut::DOUBLON, DossierStatut::FRAUDE => self::REFUSE,
        };
    }

    /**
     * Options offertes au filtre secretaire (ordre logique du cycle de vie).
     *
     * @return list<self>
     */
    public static function pourFiltre(): array
    {
        return [
            self::DEPOSE, self::ANALYSE, self::VERIFICATION, self::CORRECTION,
            self::ATTENTE_DIRECTEUR, self::VALIDE, self::PAIEMENT, self::PAYE, self::REFUSE,
        ];
    }
}
