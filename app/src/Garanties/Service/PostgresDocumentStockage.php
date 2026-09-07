<?php

declare(strict_types=1);

namespace App\Garanties\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

/**
 * Stockage du binaire des documents dans la colonne bytea de garanties.document.
 *
 * Binding via ParameterType::LARGE_OBJECT (flux) : Doctrine mappe le bytea
 * Postgres proprement, sans concatenation ni echappement manuel. La lecture
 * renvoie soit un flux, soit une chaine selon le driver : on normalise en string.
 */
final class PostgresDocumentStockage implements DocumentStockageInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function ecrire(string $reference, string $contenu): void
    {
        $flux = fopen('php://temp', 'r+b');
        if (false === $flux) {
            throw new RuntimeException('Impossible d ouvrir un flux temporaire pour le binaire du document.');
        }
        fwrite($flux, $contenu);
        rewind($flux);

        $this->connection->executeStatement(
            'UPDATE garanties.document SET contenu = :contenu WHERE reference = :reference',
            ['contenu' => $flux, 'reference' => $reference],
            ['contenu' => ParameterType::LARGE_OBJECT],
        );
    }

    public function lire(string $reference): ?string
    {
        $valeur = $this->connection->fetchOne(
            'SELECT contenu FROM garanties.document WHERE reference = :reference',
            ['reference' => $reference],
        );

        if (false === $valeur || null === $valeur) {
            return null;
        }

        if (\is_resource($valeur)) {
            return (string) stream_get_contents($valeur);
        }

        return (string) $valeur;
    }
}
