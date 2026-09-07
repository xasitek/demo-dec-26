<?php

declare(strict_types=1);

namespace App\Remboursement\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Lecture SEULE des paiements de remboursement NON LETTRES, via la vue
 * `remboursement.v_lettrage` (cf. Version20260901120000 pour la definition et le
 * critere « non lettre »). Remplace l'onglet `paiements_non_lettres` du Google Sheet
 * de l'ancien dashboard : la source est desormais le miroir comptable directement.
 *
 * Aucune ecriture ici : seul le commentaire de la comptable est persiste, par
 * LettrageCommentaireRepository.
 */
final class LettrageRepository
{
    public const PAR_PAGE = 50;

    /** Tranches de retard proposees au filtre (bornes alignees sur la vue). */
    public const RETARDS = ['<15', '>15', '>30', '>60', '>90'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Une page de lignes a lettrer, les plus anciennes d'abord (ce sont les plus
     * urgentes a solder).
     *
     * @return list<array<string, mixed>>
     */
    public function lignes(?string $type, ?string $retard, ?string $recherche, int $page = 1): array
    {
        [$where, $params, $types] = $this->filtres($type, $retard, $recherche);

        $params['limit'] = self::PAR_PAGE;
        $params['offset'] = max(0, ($page - 1) * self::PAR_PAGE);
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM remboursement.v_lettrage'
            .$where
            .' ORDER BY date_piece ASC NULLS LAST, cle_ecriture ASC'
            .' LIMIT :limit OFFSET :offset',
            $params,
            $types,
        );

        return $rows;
    }

    /**
     * Compteurs et totaux de la selection courante (bandeau du haut). Une seule
     * requete d'agregation : pas de comptage cote PHP sur des lignes non chargees.
     *
     * @return array{total: int, montant: float, rbc: int, rbc_montant: float, tp: int, tp_montant: float, retard: int, commentes: int}
     */
    public function synthese(?string $type, ?string $retard, ?string $recherche): array
    {
        [$where, $params, $types] = $this->filtres($type, $retard, $recherche);

        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(montant), 0) AS montant,
                COUNT(*) FILTER (WHERE type = 'RBC') AS rbc,
                COALESCE(SUM(montant) FILTER (WHERE type = 'RBC'), 0) AS rbc_montant,
                COUNT(*) FILTER (WHERE type = 'TP') AS tp,
                COALESCE(SUM(montant) FILTER (WHERE type = 'TP'), 0) AS tp_montant,
                COUNT(*) FILTER (WHERE jours > 30) AS retard,
                COUNT(commentaire) AS commentes
             FROM remboursement.v_lettrage".$where,
            $params,
            $types,
        );

        if (false === $row) {
            return ['total' => 0, 'montant' => 0.0, 'rbc' => 0, 'rbc_montant' => 0.0, 'tp' => 0, 'tp_montant' => 0.0, 'retard' => 0, 'commentes' => 0];
        }

        return [
            'total' => (int) $row['total'],
            'montant' => (float) $row['montant'],
            'rbc' => (int) $row['rbc'],
            'rbc_montant' => (float) $row['rbc_montant'],
            'tp' => (int) $row['tp'],
            'tp_montant' => (float) $row['tp_montant'],
            'retard' => (int) $row['retard'],
            'commentes' => (int) $row['commentes'],
        ];
    }

    /** Nombre total de lignes a lettrer, sans filtre : pastille de l'onglet. */
    public function nbALettrer(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remboursement.v_lettrage');
    }

    /**
     * Une ligne par sa cle d'ecriture (rafraichissement apres commentaire).
     *
     * @return array<string, mixed>|null
     */
    public function ligne(int $cleEcriture): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM remboursement.v_lettrage WHERE cle_ecriture = :cle',
            ['cle' => $cleEcriture],
            ['cle' => ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    /** La cle d'ecriture correspond-elle bien a une ligne a lettrer ? (garde avant commentaire) */
    public function existe(int $cleEcriture): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM remboursement.v_lettrage WHERE cle_ecriture = :cle',
            ['cle' => $cleEcriture],
            ['cle' => ParameterType::INTEGER],
        );
    }

    /**
     * Clause WHERE partagee par la liste et la synthese : les deux doivent voir
     * exactement la meme selection.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function filtres(?string $type, ?string $retard, ?string $recherche): array
    {
        $where = [];
        $params = [];
        $types = [];

        if (null !== $type && \in_array($type, ['RBC', 'TP'], true)) {
            $where[] = 'type = :type';
            $params['type'] = $type;
        }

        if (null !== $retard && \in_array($retard, self::RETARDS, true)) {
            $where[] = 'retard = :retard';
            $params['retard'] = $retard;
        }

        $recherche = null !== $recherche ? trim($recherche) : '';
        if ('' !== $recherche) {
            // Un seul champ concatene (concat_ws ignore les NULL), desaccentue des deux
            // cotes : « Beram » retrouve « BÉRAM ».
            $where[] = "unaccent(lower(concat_ws(' ', nom, libelle, compte, codeetab,"
                .' etablissement, numimmat, numvin, numpiece, commentaire)))'
                ." LIKE '%' || unaccent(lower(:q)) || '%'";
            $params['q'] = $recherche;
        }

        return [[] === $where ? '' : ' WHERE '.implode(' AND ', $where), $params, $types];
    }
}
