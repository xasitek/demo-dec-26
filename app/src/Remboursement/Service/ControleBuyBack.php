<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\BuyBackVehicule;
use App\Remboursement\Repository\BuyBackVehiculeRepository;

/**
 * Controle anti-surpaiement au rachat sec : compare le montant saisi a l'engagement
 * de reprise TTC (er_ttc) du vehicule (mirror Buy Back). Logique CENTRALE, partagee par
 * la validation serveur du depot (blocage dur) et l'endpoint AJAX (message temps reel).
 *
 * Regle (arbitrage PO) : on ne bloque QUE le SURPAIEMENT (montant > er_ttc + 3 EUR).
 * Plaque inconnue => null (aucun message). Payer moins => pas de blocage.
 */
final class ControleBuyBack
{
    /** Tolerance en euros au-dela de l'engagement de reprise. */
    public const SEUIL = 3.0;

    public function __construct(
        private readonly BuyBackVehiculeRepository $vehicules,
        private readonly NormalisationSaisie $normalisation,
    ) {
    }

    /**
     * @return array{vehicule: BuyBackVehicule, erTtc: float, montant: float|null, ecart: float|null, surpaiement: bool}|null
     *                                                                                                                        null si plaque vide / inconnue / sans er_ttc
     */
    public function pour(?string $immatBrut, ?float $montant): ?array
    {
        $immat = $this->normalisation->immatriculation($immatBrut);
        if (null === $immat) {
            return null;
        }

        $vehicule = $this->vehicules->trouverParImmat($immat);
        if (null === $vehicule || null === $vehicule->getErTtc()) {
            return null;
        }

        $erTtc = (float) $vehicule->getErTtc();
        $ecart = null !== $montant ? $montant - $erTtc : null;

        return [
            'vehicule' => $vehicule,
            'erTtc' => $erTtc,
            'montant' => $montant,
            'ecart' => $ecart,
            'surpaiement' => null !== $ecart && $ecart > self::SEUIL,
        ];
    }
}
