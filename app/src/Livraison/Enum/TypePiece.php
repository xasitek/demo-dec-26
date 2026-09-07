<?php

declare(strict_types=1);

namespace App\Livraison\Enum;

/**
 * Pieces que la secretaire joint a une declaration de livraison.
 *
 * Ce sont les trois pieces que le formulaire Google demandait. Les FACTURES n'en font
 * pas partie et n'en feront jamais : elles viennent de la comptabilite, la secretaire
 * ne les a jamais saisies. Le tableur les retrouvait par recherche sur
 * l'immatriculation, le module les lit dans `livraison.v_a_livrer`.
 */
enum TypePiece: string
{
    case PV_LIVRAISON = 'pv_livraison';
    case BON_COMMANDE = 'bon_commande';
    case CPI = 'cpi';

    public function libelle(): string
    {
        return match ($this) {
            self::PV_LIVRAISON => 'PV de livraison',
            self::BON_COMMANDE => 'Bon de commande',
            self::CPI => 'CPI',
        };
    }

    public function precision(): string
    {
        return match ($this) {
            self::PV_LIVRAISON => 'Signé et tamponné',
            self::BON_COMMANDE => 'Le bon de commande du client',
            self::CPI => 'Certificat provisoire d\'immatriculation',
        };
    }

    /** @return list<self> */
    public static function requises(): array
    {
        return [self::PV_LIVRAISON, self::BON_COMMANDE, self::CPI];
    }
}
