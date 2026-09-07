<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

/**
 * Resultat de la preparation des pieces jointes d'une relance email.
 *
 * Porte la ou les pieces a joindre a l'e-mail et, en cas de DEBORDEMENT (le
 * document complet releve + factures ne tient pas sous le quota SMTP), le
 * document complet a stocker en base pour etre expose via un lien de
 * telechargement.
 */
final class PieceJointeRelance
{
    /**
     * @param list<array{nom: string, contenu: string}> $pieces          pieces a joindre a l'e-mail
     * @param bool                                      $deborde         le document complet ne tient pas dans l'e-mail
     * @param string|null                               $documentComplet PDF complet (releve + factures) a exposer, ou null si indisponible
     */
    public function __construct(
        public readonly array $pieces,
        public readonly bool $deborde = false,
        public readonly ?string $documentComplet = null,
    ) {
    }

    public static function aucune(): self
    {
        return new self([]);
    }

    /**
     * @param list<array{nom: string, contenu: string}> $pieces
     */
    public static function attachee(array $pieces): self
    {
        return new self($pieces);
    }
}
