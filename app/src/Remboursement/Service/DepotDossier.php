<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Message\AnalyserDossier;
use App\Remboursement\Repository\EmailFavoriRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Depot d'un dossier de remboursement par une secretaire : cree le Dossier a partir
 * des donnees saisies + des pieces uploadees, calcule la cle anti-doublon, stocke
 * les pieces (par l'app), puis franchit la transition "deposer" (BROUILLON -> DEPOSE)
 * via le workflow (garde de role + audit). L'extraction IA se declenchera ensuite
 * sur l'etat DEPOSE (handler async, lot ulterieur).
 */
final class DepotDossier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockagePieces $stockage,
        private readonly WorkflowRemboursement $workflow,
        private readonly MessageBusInterface $bus,
        private readonly EmailFavoriRepository $favoris,
    ) {
    }

    /**
     * @param array{etablissementCode?: ?string, nomClient?: string, ibanClient?: ?string, bicClient?: ?string, montant?: string, immatriculation?: ?string, codeIcar?: ?string, emailCopie?: ?string} $donnees
     * @param array<string, UploadedFile>                                                                                                                                                              $fichiers pieces par type
     */
    public function deposer(DossierMotif $motif, array $donnees, array $fichiers, ?string $par, ?string $sourceCompte = null): Dossier
    {
        $dossier = new Dossier($motif, $par);
        $dossier->setEtablissementCode($donnees['etablissementCode'] ?? null);
        $dossier->setNomClient(trim($donnees['nomClient'] ?? ''));
        $dossier->setIbanClient($donnees['ibanClient'] ?? null);
        $dossier->setBicClient($donnees['bicClient'] ?? null);
        $dossier->setMontant(number_format((float) str_replace(',', '.', $donnees['montant'] ?? '0'), 2, '.', ''));

        if (DossierMotif::RACHAT_SEC === $motif) {
            $dossier->setImmatriculation($donnees['immatriculation'] ?? null);
        }
        // Code client ICAR : exige aux DEUX motifs. La cle anti-doublon ne change pas pour
        // autant (immatriculation au rachat, ICAR au trop-percu) — cf. CleDoublon juste apres.
        $dossier->setCodeIcar($donnees['codeIcar'] ?? null);

        // Adresse FACULTATIVE mise en copie de l'attestation. Le format a deja ete verifie
        // par le controleur ; l'entite normalise (minuscules, espaces retires, vide -> null).
        $dossier->setEmailCopie($donnees['emailCopie'] ?? null);

        $dossier->setCleDoublon(CleDoublon::pour($dossier));

        // Depot PREREMPLI depuis le miroir Gestion commerciale (compte source, montant/ICAR inchanges) :
        // memorise le compte pour que le controle IA prenne la donnee comptable comme verite.
        if (null !== $sourceCompte && '' !== $sourceCompte) {
            $dossier->setControleExtra(['source_compte' => $sourceCompte]);
        }

        // Persiste le dossier (obtient l'id) avant de rattacher les pieces.
        $this->em->persist($dossier);
        $this->em->flush();

        foreach ($fichiers as $type => $fichier) {
            $meta = $this->stockage->stocker($dossier, $type, $fichier);
            $this->em->persist(new DossierPiece(
                $dossier,
                $type,
                $meta['nomOriginal'],
                $meta['contenu'],
                $meta['mime'],
                $meta['taille'],
                $meta['hash'],
                $par,
            ));
        }
        $this->em->flush();

        // BROUILLON -> DEPOSE (garde ROLE_SECRETAIRE + trace d'audit).
        $this->workflow->appliquer($dossier, 'deposer', $par);

        // DEPOSE -> EXTRACTION_IA tout de suite (synchrone) : "Mes dossiers" affiche
        // "Analyse IA en cours" des l'arrivee de la secretaire, sans dependre du timing du
        // worker (sinon elle rate le passage, publie avant l'ouverture de sa connexion Mercure).
        $this->workflow->appliquer($dossier, 'demarrer_extraction', null, null, true);

        // Declenche l'analyse IA (asynchrone ; sync en dev). L'id est dispo (flush ci-dessus).
        $this->bus->dispatch(new AnalyserDossier((int) $dossier->getId()));

        // Carnet d'adresses : on ne retient qu'apres un depot MENE AU BOUT. Place ici et
        // non au moment de la saisie, pour ne pas garder l'adresse d'un depot qui a
        // echoue en cours de route (piece refusee, doublon, surpaiement Buy Back).
        $copie = $dossier->getEmailCopie();
        if (null !== $copie && null !== $par) {
            $this->favoris->memoriser($par, $copie);
        }

        return $dossier;
    }
}
