<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/**
 * Gabarits (prompt + schema JSON) par type de piece, repris du mapping N8N
 * (docs/MODULE_REMBOURSEMENT.md 4.4.4). Principe "extraire sans normaliser" : Gemini
 * ne fait que lire et rendre du JSON brut ; la normalisation/comparaison est en PHP.
 */
final class GabaritRegistry
{
    public function gabarit(string $typePiece): ?GabaritExtraction
    {
        return match ($typePiece) {
            'rib' => $this->rib(),
            'facture_achat_vo' => $this->factureVo(),
            'estimation_salesforce' => $this->estimation(),
            'carte_grise' => $this->carteGrise(),
            'certificat_situation' => $this->certificat(),
            'releve_icar' => $this->releveIcar(),
            'petits_comptes' => $this->petitsComptes(),
            default => null,
        };
    }

    private function rib(): GabaritExtraction
    {
        return new GabaritExtraction('rib.v1',
            "Ce document est un RIB (releve d'identite bancaire). Extrais fidelement, sans reformuler : "
            ."le NOM du titulaire du compte tel qu'ecrit, l'IBAN (sans espaces) et le BIC/SWIFT. "
            .'Champ absent = chaine vide.',
            $this->obj(['nom' => $this->str(), 'iban' => $this->str(), 'bic' => $this->str()]));
    }

    private function factureVo(): GabaritExtraction
    {
        return new GabaritExtraction('facture_achat_vo.v2',
            "Ce document est une facture d'achat de vehicule d'occasion. Extrais : le numero de facture, "
            .'le montant TTC (nombre), la plaque d immatriculation, le role du tiers (ex. mandataire, '
            ."comptant) et le code comptable si present. Si le document n'est pas une facture exploitable, "
            .'mets document_invalide=true. '
            .'Extrais aussi le CODE CLIENT ICAR : le numero du COMPTE CLIENT tel qu il figure sur la '
            .'facture (souvent dans le bloc client ou en tete, parfois prefixe par le type de compte, '
            .'ex. COMPTANT-805887). Il doit etre LU EXACTEMENT sur le document ; si tu ne le trouves '
            .'pas, renvoie une chaine VIDE pour code_icar (ne l invente jamais, et ne recopie ni le '
            .'numero de facture, ni le code comptable, ni aucune autre valeur).',
            $this->obj([
                'num_facture' => $this->str(), 'montant' => $this->num(), 'immatriculation' => $this->str(),
                'role_tiers' => $this->str(), 'code_comptable' => $this->str(), 'code_icar' => $this->str(),
                'document_invalide' => $this->bool(),
            ]));
    }

    private function estimation(): GabaritExtraction
    {
        return new GabaritExtraction('estimation_salesforce.v1',
            "Ce document est une estimation Salesforce de rachat. Extrais le montant d'estimation (nombre) "
            .'et indique si le document semble avoir ete modifie/retouche (modifications_detectees=true/false).',
            $this->obj(['montant_coherence' => $this->num(), 'modifications_detectees' => $this->bool()]));
    }

    private function carteGrise(): GabaritExtraction
    {
        return new GabaritExtraction('carte_grise.v1',
            "Ce document est une carte grise (certificat d'immatriculation). Extrais la plaque "
            .'d immatriculation. Indique si une mention manuscrite (barre/date/signature de cession) est '
            .'presente (ecriture_manuscrite=true/false). Si illisible/non conforme, document_invalide=true.',
            $this->obj([
                'immatriculation' => $this->str(), 'ecriture_manuscrite' => $this->bool(),
                'document_invalide' => $this->bool(),
            ]));
    }

    private function certificat(): GabaritExtraction
    {
        return new GabaritExtraction('certificat_situation.v1',
            'Ce document est un certificat de situation administrative (non-gage). Extrais la plaque '
            .'d immatriculation, indique si le vehicule est libre de tout gage/opposition (vehicule_libre='
            .'true/false) et la raison si non libre.',
            $this->obj([
                'immatriculation' => $this->str(), 'vehicule_libre' => $this->bool(), 'raison' => $this->str(),
            ]));
    }

    private function releveIcar(): GabaritExtraction
    {
        return new GabaritExtraction('releve_icar.v1',
            'Ce document est un releve de compte ICAR. Extrais le NOM du client, le solde comptable '
            .'(nombre, colonne de droite / solde du), le total non lettre si present, et la societe.',
            $this->obj([
                'releve_icar' => $this->str(), 'montant' => $this->num(),
                'total_non_lettre' => $this->num(), 'societe' => $this->str(),
            ]));
    }

    private function petitsComptes(): GabaritExtraction
    {
        return new GabaritExtraction('petits_comptes.v2',
            'Ce document liste des petits comptes. Extrais le NOM, le code ICAR et le montant (valeur '
            .'absolue, nombre). Le code ICAR doit etre LU EXACTEMENT sur le document ; si tu ne le '
            .'trouves pas, renvoie une chaine VIDE pour icar (ne l\'invente jamais, ne recopie aucune '
            .'autre valeur). Si le document ne contient aucune de ces informations, mets absent=true.',
            $this->obj([
                'nom' => $this->str(), 'icar' => $this->str(),
                'petits_comptes' => $this->num(), 'absent' => $this->bool(),
            ]));
    }

    /**
     * @param array<string, array<string, mixed>> $props
     *
     * @return array<string, mixed>
     */
    private function obj(array $props): array
    {
        return ['type' => 'OBJECT', 'properties' => $props];
    }

    /** @return array<string, mixed> */
    private function str(): array
    {
        return ['type' => 'STRING'];
    }

    /** @return array<string, mixed> */
    private function num(): array
    {
        return ['type' => 'NUMBER'];
    }

    /** @return array<string, mixed> */
    private function bool(): array
    {
        return ['type' => 'BOOLEAN'];
    }
}
