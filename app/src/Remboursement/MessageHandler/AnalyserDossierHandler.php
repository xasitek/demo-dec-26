<?php

declare(strict_types=1);

namespace App\Remboursement\MessageHandler;

use App\Remboursement\Message\AnalyserDossier;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\Ia\AnalyseDossierService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AnalyserDossierHandler
{
    public function __construct(
        private DossierRepository $dossiers,
        private AnalyseDossierService $analyse,
    ) {
    }

    public function __invoke(AnalyserDossier $message): void
    {
        $dossier = $this->dossiers->find($message->dossierId);
        if (null !== $dossier) {
            $this->analyse->analyser($dossier);
        }
    }
}
