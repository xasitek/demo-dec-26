<?php

declare(strict_types=1);

namespace App\Garanties\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lecture des documents constructeur (PDF) et de leur liaison aux dossiers.
 *
 * La liaison passe par garanties.dossier_document, rapprochee par egalite de
 * reference avec garanties.document. Un document peut etre lie sans etre encore
 * uploade (la jointure ne renvoie alors rien : self-healing au prochain upload).
 */
final class DocumentRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Documents distincts lies a un ensemble de dossiers (DG).
     *
     * @param list<int> $dossierIds
     *
     * @return list<array{id: int, reference: string, nom_fichier: ?string, type_document: ?string, periode_debut: ?string, periode_fin: ?string, taille: ?int}>
     */
    public function parDossiers(array $dossierIds): array
    {
        if ([] === $dossierIds) {
            return [];
        }

        /** @var list<array{id: int, reference: string, nom_fichier: ?string, type_document: ?string, periode_debut: ?string, periode_fin: ?string, taille: ?int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT d.id, d.reference, d.nom_fichier, d.type_document, '
            .'d.periode_debut, d.periode_fin, d.taille '
            .'FROM garanties.document d '
            .'JOIN garanties.dossier_document dd ON dd.reference = d.reference '
            .'WHERE dd.dossier_id IN (?) '
            .'ORDER BY d.periode_debut DESC NULLS LAST, d.id DESC',
            [$dossierIds],
            [ArrayParameterType::INTEGER],
        );

        return $rows;
    }

    /**
     * Metadonnees minimales d'un document, pour le servir.
     *
     * @return array{reference: string, nom_fichier: ?string, mime: ?string}|null
     */
    public function metadata(int $id): ?array
    {
        /** @var array{reference: string, nom_fichier: ?string, mime: ?string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT reference, nom_fichier, mime FROM garanties.document WHERE id = ?',
            [$id],
        );

        return false === $row ? null : $row;
    }
}
