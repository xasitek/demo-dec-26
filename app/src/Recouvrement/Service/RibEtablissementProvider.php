<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Referentiel\Etablissements;
use Doctrine\DBAL\Connection;

/**
 * Fournit les coordonnees bancaires (RIB) SYNTHAUTO par etablissement, pour indiquer
 * au client sur quel compte virer. Source : table recouvrement.rib_etablissement
 * (seedee hors-repo). La cle metier est le code etablissement ; comme
 * v_impayes.codeetab est zero-padde ('093') alors que la table stocke un entier
 * (93), on normalise systematiquement en entier avant lookup.
 */
final class RibEtablissementProvider
{
    /** @var array<int, array{titulaire: string, banque: string, iban: string, bic: string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Carte code etablissement (entier) -> RIB. Chargee une fois par requete.
     *
     * @return array<int, array{titulaire: string, banque: string, iban: string, bic: string}>
     */
    public function map(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT code_etab, titulaire, banque, iban, bic FROM recouvrement.rib_etablissement',
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['code_etab']] = [
                'titulaire' => (string) $row['titulaire'],
                'banque' => (string) $row['banque'],
                'iban' => (string) $row['iban'],
                'bic' => (string) $row['bic'],
            ];
        }

        return $this->cache = $map;
    }

    /**
     * RIB pour un codeetab de v_impayes (zero-padde ou non), ou null si l'etablissement
     * n'a pas de RIB connu (bloc de paiement alors omis pour ces factures).
     *
     * @return array{titulaire: string, banque: string, iban: string, bic: string}|null
     */
    public function pour(?string $codeetab): ?array
    {
        $code = self::normaliserCode($codeetab);

        return null === $code ? null : ($this->map()[$code] ?? null);
    }

    /**
     * Normalise un code etablissement en entier ('093' -> 93, '' / null -> null).
     * Delegue au referentiel des etablissements : une seule regle de normalisation
     * pour tout le module.
     */
    public static function normaliserCode(?string $codeetab): ?int
    {
        return Etablissements::normaliserCode($codeetab);
    }
}
