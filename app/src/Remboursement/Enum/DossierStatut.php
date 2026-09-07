<?php

declare(strict_types=1);

namespace App\Remboursement\Enum;

/**
 * Etats d'un dossier de remboursement (machine a etats). Remplace la colonne
 * "Statut" du Google Sheet actuel. Les transitions et leurs gardes par role sont
 * portees par WorkflowRemboursement. Voir docs/MODULE_REMBOURSEMENT.md (4.1).
 *
 * Chemin nominal :
 *   brouillon -> depose -> extraction_ia -> a_verifier -> a_valider_directeur
 *   -> valide_directeur -> confirme -> generation_en_cours -> paye -> lettre
 * Branches : complement_requis, cas_icar, refuse, doublon, fraude, erreur_generation.
 */
enum DossierStatut: string
{
    case BROUILLON = 'brouillon';
    case DEPOSE = 'depose';
    case EXTRACTION_IA = 'extraction_ia';
    case A_VERIFIER = 'a_verifier';
    case COMPLEMENT_REQUIS = 'complement_requis';
    case CAS_ICAR = 'cas_icar';
    case A_VALIDER_DIRECTEUR = 'a_valider_directeur';
    case VALIDE_DIRECTEUR = 'valide_directeur';
    case CONFIRME = 'confirme';
    case GENERATION_EN_COURS = 'generation_en_cours';
    case PAYE = 'paye';
    case LETTRE = 'lettre';
    case REFUSE = 'refuse';
    case DOUBLON = 'doublon';
    case FRAUDE = 'fraude';
    case ERREUR_GENERATION = 'erreur_generation';

    public function libelle(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::DEPOSE => 'Déposé',
            self::EXTRACTION_IA => 'Extraction IA en cours',
            self::A_VERIFIER => 'À vérifier (comptable)',
            self::COMPLEMENT_REQUIS => 'Correction requise',
            self::CAS_ICAR => 'Cas ICAR',
            self::A_VALIDER_DIRECTEUR => 'À valider (directeur)',
            self::VALIDE_DIRECTEUR => 'Validé par le directeur',
            self::CONFIRME => 'Confirmé (comptable)',
            self::GENERATION_EN_COURS => 'Génération des fichiers',
            self::PAYE => 'Payé',
            self::LETTRE => 'Lettré',
            self::REFUSE => 'Refusé',
            self::DOUBLON => 'Doublon',
            self::FRAUDE => 'Fraude',
            self::ERREUR_GENERATION => 'Erreur de génération',
        };
    }

    /**
     * Etat terminal : plus aucune transition metier normale n'en part (hors
     * reouverture explicite gardee).
     */
    public function estFinal(): bool
    {
        return match ($this) {
            self::LETTRE, self::REFUSE, self::DOUBLON, self::FRAUDE => true,
            default => false,
        };
    }

    /**
     * Etat "verrouille" : le dossier a passe le point de non-retour (confirme) ou
     * plus, on interdit toute (re)saisie/annulation non gardee. Reprend la logique
     * "statutsBloques" du systeme N8N actuel.
     */
    public function estVerrouille(): bool
    {
        return match ($this) {
            self::CONFIRME,
            self::GENERATION_EN_COURS,
            self::PAYE,
            self::LETTRE,
            self::FRAUDE,
            self::ERREUR_GENERATION => true,
            default => false,
        };
    }

    /** Le paiement a ete effectue (fichiers generes). */
    public function estPaye(): bool
    {
        return self::PAYE === $this || self::LETTRE === $this;
    }

    /** Vue simplifiee (secretaire) de ce statut. */
    public function statutSecretaire(): StatutSecretaire
    {
        return StatutSecretaire::pour($this);
    }
}
