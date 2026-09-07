<?php

declare(strict_types=1);

namespace App\Remboursement\MessageHandler;

use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\GenerationFichiersComptables;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenererFichiersHandler
{
    public function __construct(
        private DossierRepository $dossiers,
        private GenerationFichiersComptables $generation,
    ) {
    }

    public function __invoke(GenererFichiers $message): void
    {
        $dossier = $this->dossiers->find($message->dossierId);
        if (null !== $dossier) {
            $this->generation->generer($dossier);
        }
    }
}
