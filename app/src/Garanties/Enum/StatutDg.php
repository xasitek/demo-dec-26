<?php

declare(strict_types=1);

namespace App\Garanties\Enum;

/**
 * Statuts d'une demande de garantie (DG) sur le portail constructeur Fiat.
 *
 * Codes et significations valides apres la reunion auditeur du 2026-05-28 :
 *   20 = annule, 21 = paye, 22 = a corriger, 23 = en traitement,
 *   24 = en traitement, 25 = demande de renseignement, 29 = refuse.
 *
 * Le regroupement en Famille sert au visuel (couleur du badge), mais nous ne
 * derivons plus d'« anomalie » automatique cote app : c'est l'auditeur qui
 * tranche en lisant le statut et les ecritures Progiciel. Voir docs/ARCHITECTURE.md.
 */
enum StatutDg: string
{
    case ANNULE = '20';
    case PAYE = '21';
    case A_CORRIGER = '22';
    case EN_TRAITEMENT_23 = '23';
    case EN_TRAITEMENT_24 = '24';
    case DEMANDE_RENSEIGNEMENT = '25';
    case REFUSE = '29';

    /**
     * Libelle d'origine du portail (italien). 23/24/25 non documentes, conserve
     * le libelle francais utilise dans l'app.
     */
    public function libelleOrigine(): string
    {
        return match ($this) {
            self::ANNULE => 'SR stornata',
            self::PAYE => 'SR Liquidata',
            self::A_CORRIGER => 'SR da correggere',
            self::EN_TRAITEMENT_23 => 'SR in trattamento',
            self::EN_TRAITEMENT_24 => 'SR in trattamento',
            self::DEMANDE_RENSEIGNEMENT => 'SR di informazione',
            self::REFUSE => 'SR rifiutata',
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::ANNULE => 'Annulé',
            self::PAYE => 'Payé',
            self::A_CORRIGER => 'À corriger',
            self::EN_TRAITEMENT_23 => 'En traitement',
            self::EN_TRAITEMENT_24 => 'En traitement',
            self::DEMANDE_RENSEIGNEMENT => 'Demande de renseignement',
            self::REFUSE => 'Refusé',
        };
    }

    public function famille(): Famille
    {
        return match ($this) {
            self::PAYE => Famille::PAYE,
            self::ANNULE, self::REFUSE => Famille::ANNULE,
            self::A_CORRIGER, self::DEMANDE_RENSEIGNEMENT => Famille::A_CORRIGER,
            self::EN_TRAITEMENT_23, self::EN_TRAITEMENT_24 => Famille::EN_TRAITEMENT,
        };
    }

    /**
     * Resout un code brut (eventuellement inconnu ou vide) en statut connu.
     */
    public static function depuisCode(?string $code): ?self
    {
        if (null === $code || '' === trim($code)) {
            return null;
        }

        return self::tryFrom(trim($code));
    }
}
