<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Shared\Entity\Etablissement;
use App\Shared\Repository\EtablissementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestre la generation des DEUX fichiers comptables d'un dossier « Dossier valide »
 * (valide par le directeur) : ecriture OD (CSV Gestion commerciale) + virement SEPA (pain.001).
 *
 * Les fichiers sont attaches au dossier comme DossierPiece (types fichier_od / fichier_sepa),
 * puis le dossier passe en « En cours de paiement » (GENERATION_EN_COURS). En cas d'echec
 * (ex. IBAN etablissement invalide), il bascule en ERREUR_GENERATION.
 *
 * Declenche AUTOMATIQUEMENT (async) quand le dossier entre en VALIDE_DIRECTEUR. Le passage
 * ulterieur a « Paye » est une autre logique (a venir). Valeurs RETENUES (valide_*) utilisees.
 */
final class GenerationFichiersComptables
{
    public const TYPE_OD = 'fichier_od';
    public const TYPE_SEPA = 'fichier_sepa';

    public function __construct(
        private readonly EtablissementRepository $etablissements,
        private readonly DossierPieceRepository $pieces,
        private readonly GenerateurCsvComptable $csv,
        private readonly GenerateurSepa $sepa,
        private readonly StockagePieces $stockage,
        private readonly WorkflowRemboursement $workflow,
        private readonly RemboursementMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function generer(Dossier $dossier): void
    {
        // Idempotence : on ne genere que depuis « Dossier valide » (valide directeur).
        if (DossierStatut::VALIDE_DIRECTEUR !== $dossier->getStatut()) {
            return;
        }

        // Passage en generation : confirmation systeme + demarrage (transitions existantes).
        $this->workflow->appliquer($dossier, 'confirmer', 'systeme', null, false, true, true);
        $this->workflow->appliquer($dossier, 'demarrer_generation', 'systeme', null, true);

        try {
            $etab = $this->etablissement($dossier);
            if (null === $etab) {
                throw new RuntimeException(sprintf('Etablissement "%s" introuvable.', $dossier->getEtablissementCode()));
            }

            $this->supprimerAnciens($dossier);

            // Libelle STRUCTURE appose sur les 2 fichiers (detection doublons/anomalies).
            $libelle = LibellePaiement::pour($dossier, (string) $etab->getLibelle());

            $od = $this->csv->construire($dossier, [
                'societe' => (string) $etab->getSociete(),
                'codeEtab' => (string) $dossier->getEtablissementCode(),
                'cinq' => (string) $etab->getCompteContrepartie(),
                'codeComptable' => (string) $dossier->getValideCodeComptable(),
                'roleTiers' => (string) $dossier->getValideRoleTiers(),
            ], null, $libelle);
            $this->attacher($dossier, self::TYPE_OD, $od['nomFichier'], $od['csv'], 'text/csv');

            $sepa = $this->sepa->construire($dossier, [
                'nom' => (string) ($etab->getNomLegalSepa() ?: $etab->getSociete() ?: $etab->getLibelle()),
                'iban' => (string) $etab->getIban(),
                'bic' => (string) $etab->getBic(),
            ], $libelle);
            $this->attacher($dossier, self::TYPE_SEPA, $sepa['nomFichier'], $sepa['xml'], 'application/xml');

            $this->em->flush();
            // Reste en GENERATION_EN_COURS = « En cours de paiement ».

            // Previent la secretaire : dossier valide, remboursement sous 48h (best-effort).
            $this->mailer->paiementEnCours($dossier);
        } catch (Throwable $e) {
            $this->logger->error('Generation fichiers remboursement echouee : {message}', [
                'message' => $e->getMessage(),
                'dossier' => $dossier->getReference(),
            ]);
            $this->workflow->appliquer($dossier, 'echec_generation', 'systeme', substr($e->getMessage(), 0, 500), true);
        }
    }

    private function attacher(Dossier $dossier, string $type, string $nom, string $contenu, string $mime): void
    {
        $meta = $this->stockage->stockerContenu($contenu, $mime);
        $this->em->persist(new DossierPiece($dossier, $type, $nom, $meta['contenu'], $meta['mime'], $meta['taille'], $meta['hash'], 'systeme'));
    }

    /** Retire d'eventuels fichiers generes precedemment (relance apres erreur). */
    private function supprimerAnciens(Dossier $dossier): void
    {
        foreach ($this->pieces->pourDossier($dossier) as $piece) {
            if (\in_array($piece->getType(), [self::TYPE_OD, self::TYPE_SEPA], true)) {
                $this->em->remove($piece);
            }
        }
    }

    private function etablissement(Dossier $dossier): ?Etablissement
    {
        $code = trim((string) $dossier->getEtablissementCode());

        return '' !== $code ? $this->etablissements->findOneBy(['codeEtab' => $code]) : null;
    }
}
