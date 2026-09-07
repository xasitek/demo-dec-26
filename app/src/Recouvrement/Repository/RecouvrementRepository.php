<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use Doctrine\DBAL\Connection;

/**
 * Lecture des factures clients impayées à relancer.
 *
 * Source unique : la vue `recouvrement.v_impayes` (FC clients 411 ouvertes,
 * cf. migration Version20260624120000). On ne lit JAMAIS `mirror.*` en direct.
 * Le périmètre « à relancer » = factures échues (jours_retard > 0).
 */
final class RecouvrementRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Rafraichit la vue materialisee des impayes (recouvrement.v_impayes).
     *
     * A lancer APRES l'ETL Progiciel (qui met a jour mirror.bal_eloficash / mirror.tiers) :
     * la chaine cron execute `app:etl:mirror-compta` puis `app:recouvrement:refresh-impayes`.
     * CONCURRENTLY : ne verrouille pas les lectures (necessite l'index unique sur cle).
     * work_mem eleve seulement pour cette connexion CLI, jamais pour le web.
     *
     * NB : jours_retard / retard sont calcules avec CURRENT_DATE au moment du refresh
     * (snapshot nocturne). PreparerRelancesCommand rafraichit d'abord pour travailler
     * sur le retard du jour.
     */
    public function rafraichirImpayes(): void
    {
        $this->connection->executeStatement("SET work_mem = '256MB'");
        $this->connection->executeStatement('REFRESH MATERIALIZED VIEW CONCURRENTLY recouvrement.v_impayes');
    }

    /**
     * Rafraichit l'annuaire clients (mv_annuaire_clients). A lancer APRES
     * rafraichirImpayes() : la projection agrege recouvrement.v_impayes.
     */
    public function rafraichirAnnuaire(): void
    {
        $this->connection->executeStatement('REFRESH MATERIALIZED VIEW CONCURRENTLY recouvrement.mv_annuaire_clients');
    }

    /**
     * Page paginée des impayés échus, du plus ancien échu au plus récent.
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $page, int $parPage): array
    {
        $offset = max(0, ($page - 1) * $parPage);
        $sql = $this->sqlBase()
            .sprintf(
                ' ORDER BY date_echeance ASC NULLS LAST, montant_solde DESC LIMIT %d OFFSET %d',
                $parPage,
                $offset,
            );

        return $this->connection->fetchAllAssociative($sql);
    }

    public function compter(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM ('.$this->sqlBase().') c');
    }

    /**
     * Factures impayées échues d'un client (pour le panneau "factures du client").
     *
     * @return list<array<string, mixed>>
     */
    public function facturesDuCompte(string $compte): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT ecriture_id, numpiece, reference_facture, date_echeance, jours_retard, retard, '
            .'montant_solde, codeetab, marque, numimmat, numor, chemin_pdf '
            .'FROM recouvrement.v_impayes '
            .'WHERE compte = :compte AND montant_solde > 0 AND jours_retard > 0 AND montant_initial_facturation > 0 '
            .'ORDER BY jours_retard DESC, montant_solde DESC',
            ['compte' => $compte],
        );
    }

    /**
     * Avoirs / crédits (lignes à solde négatif) d'un client, à déduire du dû.
     *
     * @return list<array<string, mixed>>
     */
    public function avoirsDuCompte(string $compte): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT ecriture_id, numpiece, reference_facture, date_echeance, jours_retard, retard, '
            .'montant_solde, codeetab, marque, numimmat, chemin_pdf '
            .'FROM recouvrement.v_impayes '
            .'WHERE compte = :compte AND montant_solde < 0 '
            .'ORDER BY montant_solde ASC',
            ['compte' => $compte],
        );
    }

    /**
     * Nombre de factures impayées échues d'un client (pour le bouton du détail).
     */
    public function compterFacturesDuCompte(string $compte): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM recouvrement.v_impayes '
            .'WHERE compte = :compte AND montant_solde > 0 AND jours_retard > 0 AND montant_initial_facturation > 0',
            ['compte' => $compte],
        );
    }

    /**
     * Téléphones connus d'un client (fixe + portable), depuis v_impayes. Champs à
     * null si inconnus (client sans impayé courant ou sans numéro renseigné).
     *
     * @return array{telephone: ?string, portable: ?string}
     */
    public function telephonesDuCompte(string $compte): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT max(telephone) AS telephone, max(portable) AS portable '
            .'FROM recouvrement.v_impayes WHERE compte = :compte',
            ['compte' => $compte],
        );
        if (false === $row) {
            return ['telephone' => null, 'portable' => null];
        }

        $nettoie = static function (mixed $valeur): ?string {
            $valeur = trim((string) $valeur);

            return '' !== $valeur ? $valeur : null;
        };

        return [
            'telephone' => $nettoie($row['telephone'] ?? null),
            'portable' => $nettoie($row['portable'] ?? null),
        ];
    }

    /**
     * Une facture par sa clé écriture (pour servir son PDF). null si introuvable.
     *
     * @return array<string, mixed>|null
     */
    public function factureParEcriture(string $ecritureId): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT ecriture_id, compte, reference_facture, numpiece '
            .'FROM recouvrement.v_impayes WHERE ecriture_id = :ecriture LIMIT 1',
            ['ecriture' => $ecritureId],
        );

        return false === $row ? null : $row;
    }

    /**
     * KPI du bandeau : nb impayés, encours total, nb clients, nb joignables par mail.
     *
     * @return array{impayes: int, encours: float, clients: int, avec_email: int}
     */
    public function synthese(): array
    {
        /** @var array{impayes: int|string, encours: int|string|float, clients: int|string, avec_email: int|string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) AS impayes, '
            .'COALESCE(sum(montant_solde), 0) AS encours, '
            .'count(DISTINCT compte) AS clients, '
            ."count(*) FILTER (WHERE email IS NOT NULL AND email <> '') AS avec_email "
            .'FROM ('.$this->sqlBase().') c',
        );

        if (false === $row) {
            return ['impayes' => 0, 'encours' => 0.0, 'clients' => 0, 'avec_email' => 0];
        }

        return [
            'impayes' => (int) $row['impayes'],
            'encours' => (float) $row['encours'],
            'clients' => (int) $row['clients'],
            'avec_email' => (int) $row['avec_email'],
        ];
    }

    /**
     * Base commune : VRAIES créances clients échues à recouvrer. Depuis que
     * v_impayes couvre tous les collectifs 41x et tous les types de pièce (pour le
     * moteur de règles), on RESTREINT ici aux factures clients réellement dues :
     * montant positif, échues, sur les collectifs clients VN/APV (4111/4114 + leurs
     * douteux 4161/4164). On exclut ainsi les garanties (4116), les avances (419),
     * les écritures comptables (OD...) et les avoirs, qui faussaient le total.
     * (Même périmètre que les règles de relance ; à ajuster ici si celui-ci change.).
     */
    private function sqlBase(): string
    {
        return 'SELECT compte, nom, prenom, civilite, raison_sociale, email, telephone, '
            .'numpiece, reference_facture, date_echeance, date_piece, jours_retard, retard, '
            .'montant_solde, codeetab, marque, numimmat '
            .'FROM recouvrement.v_impayes '
            .'WHERE jours_retard > 0 AND montant_solde > 0 AND montant_initial_facturation > 0 '
            ."AND collectif IN ('4111000', '4114000', '4161000', '4164000')";
    }
}
