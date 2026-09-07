<?php

declare(strict_types=1);

namespace App\Recouvrement\MessageHandler;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Message\EnvoyerRelance;
use App\Recouvrement\Service\EnvoiRelanceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consomme un ordre EnvoyerRelance : charge le RelanceEnvoi pre-cree puis
 * delegue l'envoi (et la mise a jour du statut) a EnvoiRelanceService.
 *
 * Idempotence : si l'entite est introuvable (purgee) ou deja ENVOYE, on ne fait
 * rien. EnvoiRelanceService::envoyerPrepare court-circuite aussi un envoi deja
 * realise, ce qui rend le message rejouable sans double email.
 */
#[AsMessageHandler]
final class EnvoyerRelanceHandler
{
    public function __construct(
        private readonly EnvoiRelanceService $envoiRelanceService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnvoyerRelance $message): void
    {
        $relance = $this->entityManager->getRepository(RelanceEnvoi::class)->find($message->relanceId);

        if (!$relance instanceof RelanceEnvoi) {
            // Relance introuvable (purgee, base reinitialisee) : rien a envoyer.
            $this->logger->warning('Relance introuvable, message ignore', [
                'relance_id' => $message->relanceId,
            ]);

            return;
        }

        $this->envoiRelanceService->envoyerPrepare($relance, $message->groupe);
    }
}
