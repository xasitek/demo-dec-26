<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Entity\Dossier;
use Doctrine\DBAL\Connection;

/**
 * Ce que les PIECES d'un dossier montrent, tel que le monde synthetique le dit.
 *
 * Le monde porte des faits documentaires qui ne coincident pas toujours avec la
 * saisie : un RIB peut afficher un autre IBAN que celui que la secretaire a
 * tape, une facture un autre montant, une carte grise une mention manuscrite,
 * un certificat un vehicule gage. Un document peut meme etre illisible, ou
 * mettre le fournisseur de lecture en panne.
 *
 * Ces faits sont charges dans `remboursement.source_piece_demo` par le
 * chargement de l'univers. Ce service les traduit en contexte pour la fabrique
 * de documents.
 *
 * DEUX PRECAUTIONS QUI COMMANDENT LA CONCEPTION.
 *
 * D'abord, ce n'est pas de la verite de mesure. La table ne dit pas ce qu'un
 * dossier DOIT devenir -- cela vit dans `remboursement_verite`, que rien ici
 * ne lit. Elle dit ce que le papier porte, ce qui est une donnee du monde.
 *
 * Ensuite, aucun module metier ne lit cette table. Seules la fabrique de
 * documents et les commandes de demonstration s'en servent. Le moteur
 * d'extraction, lui, ne connait que le document qu'on lui donne.
 */
final readonly class SourcePiece
{
    public function __construct(private Connection $cnx)
    {
    }

    /**
     * Les faits documentaires d'un dossier, ou null s'il n'en porte aucun.
     *
     * @return array<string, mixed>|null
     */
    public function pour(int $dossierId): ?array
    {
        $l = $this->cnx->fetchAssociative(
            'SELECT * FROM remboursement.source_piece_demo WHERE dossier_id = ?', [$dossierId]);

        return false === $l ? null : $l;
    }

    /**
     * Le contexte a passer a la fabrique, pour un type de piece donne.
     *
     * @return array<string, mixed>
     */
    public function contexte(Dossier $dossier, string $typePiece): array
    {
        $source = $this->pour((int) $dossier->getId());
        if (null === $source) {
            return [];
        }

        $illisible = (bool) $source['piece_illisible'];
        $panne = (bool) $source['panne_extraction'];

        // On ne rend pas TOUT illisible : un dossier dont aucune piece ne se lit
        // n'apprend rien, et le module distingue justement une piece perdue d'un
        // dossier perdu. L'incident se pose donc sur la piece MAITRESSE du motif
        // -- la facture au rachat, le releve au trop-percu.

        return match ($typePiece) {
            'rib' => [
                // Le RIB peut porter un autre IBAN que la saisie : c'est la
                // divergence que le comptable doit trancher.
                'iban_document' => (string) ($source['iban_rib'] ?? $dossier->getIbanClient()),
            ],
            'facture_achat_vo' => [
                'montant_document' => (float) $source['montant_facture'],
                'document_invalide' => $illisible,
                'panne_extraction' => $panne,
            ],
            'carte_grise' => [
                'ecriture_manuscrite' => (bool) $source['mention_manuscrite'],
                'document_invalide' => false,
            ],
            'certificat_situation' => [
                // Un vehicule gage n'est pas libre : le certificat le dit.
                'vehicule_libre' => !(bool) $source['vehicule_gage'],
                'raison' => (bool) $source['vehicule_gage']
                    ? 'Gage inscrit au fichier des vehicules'
                    : 'Aucune opposition enregistree',
            ],
            'estimation_salesforce' => [
                'montant_coherence' => (float) $source['montant_facture'],
                'modifications_detectees' => (bool) $source['estimation_retouchee'],
            ],
            // Le trop-percu ne se lit pas sur une facture d'achat mais sur le
            // releve du compte client : c'est LUI qui doit porter le montant du
            // monde, sinon la divergence de montant n'existe que pour les
            // rachats et la moitie du scenario ne se joue jamais.
            'releve_icar' => [
                'montant_document' => (float) $source['montant_facture'],
                'total_non_lettre' => (float) $source['montant_facture'],
                'document_invalide' => $illisible,
                'panne_extraction' => $panne,
            ],
            'petits_comptes' => [
                'montant_document' => (float) $source['montant_facture'],
            ],
            default => [],
        };
    }

    /** Le dossier porte-t-il une panne de lecture declaree par le monde ? */
    public function porteUnePanne(int $dossierId): bool
    {
        return (bool) $this->cnx->fetchOne(
            'SELECT panne_extraction FROM remboursement.source_piece_demo WHERE dossier_id = ?',
            [$dossierId]);
    }

    /** Le dossier porte-t-il un document illisible declare par le monde ? */
    public function porteUnIllisible(int $dossierId): bool
    {
        return (bool) $this->cnx->fetchOne(
            'SELECT piece_illisible FROM remboursement.source_piece_demo WHERE dossier_id = ?',
            [$dossierId]);
    }
}
