<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Produit postal PAPIER utilise pour le vecteur COURRIER (comptes SANS e-mail :
 * on ne connait que leur adresse postale -> envoi physique obligatoire).
 *
 * - LETTRE : lettre simple, aucune preuve.
 * - SUIVI : lettre suivie -> preuve de DISTRIBUTION (numerique), pas de signature.
 * - RECOMMANDE : recommande papier avec AR (LRAR) -> preuve de RECEPTION signee ;
 *   l'AR est renvoye en fichier numerique par le prestataire (stockable en BDD).
 *
 * NB : pas de recommande electronique (LRE) ici -> il faudrait un canal electronique
 * vers le destinataire, qu'on n'a pas (sinon le vecteur serait EMAIL).
 */
enum TypeCourrier: string
{
    case LETTRE = 'lettre';
    case SUIVI = 'suivi';
    case RECOMMANDE = 'recommande';

    public function libelle(): string
    {
        return match ($this) {
            self::LETTRE => 'Lettre simple',
            self::SUIVI => 'Lettre suivie',
            self::RECOMMANDE => 'Recommandé avec AR',
        };
    }

    /**
     * Vrai si le produit fournit une PREUVE DE RECEPTION signee (recommande), a
     * conserver comme preuve juridique. La lettre suivie ne compte pas (preuve de
     * distribution, pas de signature).
     */
    public function avecAccuseReception(): bool
    {
        return self::RECOMMANDE === $this;
    }
}
