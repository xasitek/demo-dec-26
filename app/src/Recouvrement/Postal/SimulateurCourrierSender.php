<?php

declare(strict_types=1);

namespace App\Recouvrement\Postal;

use App\Recouvrement\Enum\StatutCourrier;
use Psr\Log\LoggerInterface;

/**
 * Expediteur SIMULE : n'envoie RIEN reellement. Garde-fou par defaut tant qu'aucun
 * prestataire reel n'est configure (ou en mode simulation), pour developper et
 * tester toute la chaine sans depenser d'argent ni poster de vrai courrier.
 *
 * Journalise ce qui SERAIT envoye et renvoie une reference factice.
 */
final class SimulateurCourrierSender implements CourrierPostalSender
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function envoyer(CourrierAEnvoyer $courrier): ResultatCourrier
    {
        $reference = 'SIM-'.strtoupper(bin2hex(random_bytes(6)));

        $this->logger->info('Courrier SIMULE (aucun envoi reel)', [
            'reference' => $reference,
            'destinataire' => $courrier->destinataireNom,
            'type' => $courrier->type->value,
            'octets_pdf' => \strlen($courrier->pdf),
        ]);

        return new ResultatCourrier('simule', $reference, StatutCourrier::DEPOSE);
    }

    public function statut(string $reference): StatutCourrier
    {
        // En simulation, on considere le courrier immediatement distribue.
        return StatutCourrier::DISTRIBUE;
    }

    public function nom(): string
    {
        return 'simule';
    }
}
