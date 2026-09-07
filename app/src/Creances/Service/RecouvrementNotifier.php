<?php

declare(strict_types=1);

namespace App\Creances\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Publication temps reel pour le module Recouvrement (Mercure).
 *
 * Topics :
 *  - fc-finance:recouvrement:tiers:{code}  → mises a jour d'un tiers
 *    (annotation, action, promesse, dossier). Souscrits par les fiches tiers
 *    ouvertes pour propager les changements faits par d'autres comptables.
 *  - fc-finance:recouvrement:user:{id}     → mises a jour personnelles
 *    (action attribuee, action cloturee). Souscrits par le tableau de bord.
 *
 * Best-effort : un hub indisponible n'empeche pas l'action metier.
 */
final class RecouvrementNotifier
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function topicTiers(string $compteCode): string
    {
        return 'fc-finance:recouvrement:tiers:'.$compteCode;
    }

    public static function topicUser(int $userId): string
    {
        return 'fc-finance:recouvrement:user:'.$userId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function notifierTiers(string $compteCode, string $evenement, array $payload = []): void
    {
        $this->publier(self::topicTiers($compteCode), $evenement, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function notifierUtilisateur(int $userId, string $evenement, array $payload = []): void
    {
        $this->publier(self::topicUser($userId), $evenement, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publier(string $topic, string $evenement, array $payload): void
    {
        try {
            $this->hub->publish(new Update(
                $topic,
                json_encode(['type' => $evenement] + $payload, \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $e) {
            $this->logger->warning(
                'Recouvrement : publication Mercure echouee sur {topic} : {message}',
                ['topic' => $topic, 'message' => $e->getMessage()],
            );
        }
    }
}
