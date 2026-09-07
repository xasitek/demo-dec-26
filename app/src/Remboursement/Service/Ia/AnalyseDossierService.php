<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\ExtractionPiece;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Enum\StatutExtraction;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\ExtractionPieceRepository;
use App\Remboursement\Service\Ia\Exception\ProviderIndisponibleException;
use App\Remboursement\Service\Ia\Exception\ReponseNonExploitableException;
use App\Remboursement\Service\RemboursementNotifier;
use App\Remboursement\Service\WorkflowRemboursement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestre l'extraction IA d'un dossier deposé : demarre l'extraction, passe chaque
 * piece au provider (Gemini), journalise (ExtractionPiece), agrege dans controle_* +
 * verdict, puis route vers "a verifier" (l'IA ne refuse JAMAIS). Voir 4.4.5.
 */
final class AnalyseDossierService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowRemboursement $workflow,
        private readonly GabaritRegistry $gabarits,
        private readonly ProviderOcr $provider,
        private readonly DossierPieceRepository $pieces,
        private readonly ExtractionPieceRepository $extractions,
        private readonly AgregateurControle $agregateur,
        private readonly RemboursementNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function analyser(Dossier $dossier): void
    {
        // Garde : dossier fraichement depose OU deja passe en extraction au depot (le
        // depot applique demarrer_extraction en synchrone pour l'affichage immediat).
        if (!\in_array($dossier->getStatut(), [DossierStatut::DEPOSE, DossierStatut::EXTRACTION_IA], true)) {
            return;
        }
        // Ne (re)demarrer l'extraction que si le depot ne l'a pas deja fait.
        if (DossierStatut::DEPOSE === $dossier->getStatut()) {
            $this->workflow->appliquer($dossier, 'demarrer_extraction', null, null, true);
        }
        $this->extractions->purgerDossier($dossier);

        /** @var array<string, array<string, mixed>> $champsParType */
        $champsParType = [];
        $surcharge = false;

        foreach ($this->pieces->pourDossier($dossier) as $piece) {
            $type = $piece->getType();
            $gabarit = $this->gabarits->gabarit($type);
            if (null === $gabarit) {
                continue;
            }

            $extraction = new ExtractionPiece($dossier, $piece, $type, $gabarit->version);
            $extraction->definirHash($piece->getHash());

            try {
                if (!$this->provider->estDisponible()) {
                    throw new ProviderIndisponibleException('Provider OCR indisponible (cle absente).');
                }
                $resultat = $this->provider->extraire(
                    new ContenuPiece($piece->getContenu(), $piece->getMimeType()),
                    $gabarit,
                );
                $extraction->reussir($resultat->champsExtraits, $resultat->resultatBrut, $this->provider->nom(), $resultat->modele, $resultat->tokensEntree, $resultat->tokensSortie, $resultat->latenceMs);
                $champsParType[$type] = $resultat->champsExtraits;
            } catch (ProviderIndisponibleException|ReponseNonExploitableException $e) {
                $extraction->echouer(StatutExtraction::SURCHARGE, $e->getMessage());
                $surcharge = true;
                $this->logger->warning('Extraction IA en surcharge', ['dossier' => $dossier->getReference(), 'piece' => $type, 'message' => $e->getMessage()]);
            }

            $this->em->persist($extraction);
        }
        $this->em->flush();

        $this->agregateur->agreger($dossier, $champsParType, null);

        // Route TOUJOURS vers "a verifier" ; en cas de surcharge, via echec_extraction
        // (bandeau "analyse a relancer" cote comptable), jamais un refus automatique.
        $this->workflow->appliquer($dossier, $surcharge ? 'echec_extraction' : 'terminer_extraction', null, null, true);

        // Dossier arrive dans la file comptable : cloche + notif navigateur + insertion
        // live (best-effort : une notification en echec ne doit jamais rejouer l'analyse).
        try {
            $this->notifier->signalerAVerifier($dossier);
        } catch (Throwable $e) {
            $this->logger->warning('Notification "a verifier" echouee', ['dossier' => $dossier->getReference(), 'message' => $e->getMessage()]);
        }
    }
}
