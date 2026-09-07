<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Origine d'un retour client (recouvrement.retour_client.source).
 *
 *   IMAP    : collecte automatique de la boite de reception (ext-imap).
 *   POP3    : collecte automatique via POP3S (serveurs ou l'IMAP est bloque).
 *   MANUEL     : saisi par un utilisateur depuis l'interface.
 *   WEBHOOK    : recu via un fournisseur de mail entrant.
 *   FORMULAIRE : soumis par le client via le formulaire web public (orphelins).
 */
enum RetourSource: string
{
    case IMAP = 'imap';
    case POP3 = 'pop3';
    case MANUEL = 'manuel';
    case WEBHOOK = 'webhook';
    case FORMULAIRE = 'formulaire';

    public function libelle(): string
    {
        return match ($this) {
            self::IMAP => 'IMAP',
            self::POP3 => 'POP3',
            self::MANUEL => 'Manuel',
            self::WEBHOOK => 'Webhook',
            self::FORMULAIRE => 'Formulaire',
        };
    }
}
