<?php

declare(strict_types=1);

namespace App\Garanties\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Ingestion d'un document constructeur (PDF) recu via l'API.
 *
 * Idempotent sur la reference : ré-uploader la même référence met à jour les
 * métadonnées et réécrit le binaire, sans créer de doublon. Le rapprochement
 * avec les dossiers n'est PAS fait ici : il est porté par la colonne « document »
 * du Google Sheet, traitée par GarantiesSheetSync (liaison par reference).
 */
final class DocumentIngestion
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentStockageInterface $stockage,
    ) {
    }

    /**
     * @return array{document_id: int, reference: string, deja_present: bool, taille: int, hash: string}
     */
    public function ingerer(
        string $reference,
        string $contenu,
        string $nomFichier,
        string $mime,
        string $marque,
        ?string $typeDocument,
        ?string $compteLogin,
        ?string $concession,
        ?string $periodeDebut,
        ?string $periodeFin,
        ?string $source,
    ): array {
        $hash = hash('sha256', $contenu);
        $taille = \strlen($contenu);

        $params = [
            'reference' => $reference,
            'marque' => $marque,
            'type_document' => $typeDocument,
            'compte_login' => $compteLogin,
            'concession' => $concession,
            'periode_debut' => '' === (string) $periodeDebut ? null : $periodeDebut,
            'periode_fin' => '' === (string) $periodeFin ? null : $periodeFin,
            'nom_fichier' => $nomFichier,
            'mime' => $mime,
            'taille' => $taille,
            'hash_sha256' => $hash,
            'source' => $source,
        ];

        // Upsert des metadonnees. Le binaire est ecrit a part (stockage abstrait).
        $sql = 'INSERT INTO garanties.document '
            .'(reference, marque, type_document, compte_login, concession, periode_debut, periode_fin, '
            .'nom_fichier, mime, taille, hash_sha256, source, importe_le) '
            .'VALUES (:reference, :marque, :type_document, :compte_login, :concession, :periode_debut, :periode_fin, '
            .':nom_fichier, :mime, :taille, :hash_sha256, :source, CURRENT_TIMESTAMP) '
            .'ON CONFLICT (reference) DO UPDATE SET '
            .'marque = EXCLUDED.marque, type_document = EXCLUDED.type_document, '
            .'compte_login = EXCLUDED.compte_login, concession = EXCLUDED.concession, '
            .'periode_debut = EXCLUDED.periode_debut, periode_fin = EXCLUDED.periode_fin, '
            .'nom_fichier = EXCLUDED.nom_fichier, mime = EXCLUDED.mime, taille = EXCLUDED.taille, '
            .'hash_sha256 = EXCLUDED.hash_sha256, source = EXCLUDED.source, importe_le = CURRENT_TIMESTAMP '
            .'RETURNING id, (xmax = 0) AS inserted';

        /** @var array{id: int|string, inserted: bool}|false $r */
        $r = $this->connection->fetchAssociative($sql, $params);
        if (false === $r) {
            throw new RuntimeException('Echec de l upsert du document (reference '.$reference.').');
        }

        $dejaPresent = true !== $r['inserted'];

        // Le binaire est ecrit apres l'insertion de la ligne (la reference existe).
        $this->stockage->ecrire($reference, $contenu);

        return [
            'document_id' => (int) $r['id'],
            'reference' => $reference,
            'deja_present' => $dejaPresent,
            'taille' => $taille,
            'hash' => $hash,
        ];
    }
}
