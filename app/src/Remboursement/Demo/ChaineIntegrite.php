<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Entity\DossierTransition;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION — chaine d'integrite du journal.
 *
 * Ce mecanisme N'EXISTE PAS dans le module historique. Le module reel journalise
 * chaque transition dans `remboursement.dossier_transition`, avec son statut
 * d'avant, son statut d'apres, son auteur et son horodatage : c'est une piste
 * d'audit honnete, et elle suffit a expliquer un dossier. Ce qu'elle ne fait
 * pas, c'est PROUVER qu'elle n'a pas ete retouchee apres coup. Une ligne
 * modifiee en base, une ligne supprimee, et le journal raconte autre chose sans
 * que rien ne le signale.
 *
 * Ce que la chaine ajoute. Chaque transition recoit un maillon : un condensat
 * SHA-256 de ce qu'elle dit, ET du condensat du maillon precedent. La chaine est
 * GLOBALE, une seule sequence pour tous les dossiers, parce qu'une chaine par
 * dossier ne verrait pas la disparition d'un dossier entier.
 *
 * La consequence est celle qu'on veut pouvoir demontrer : toucher a une ligne
 * quelconque casse tous les maillons a partir de son rang, et la verification
 * nomme le premier rang fautif. Falsifier proprement supposerait de recalculer
 * la chaine entiere, ce qu'une commande de verification revele en comparant le
 * dernier condensat a une valeur conservee ailleurs.
 *
 * Ce que la chaine ne pretend pas etre. Ce n'est pas un horodatage certifie,
 * ce n'est pas une signature, et un administrateur de la base qui recalcule la
 * chaine complete ne serait pas attrape par la seule verification interne. Elle
 * releve l'alteration ponctuelle, pas la reecriture totale par quelqu'un qui
 * connait le mecanisme.
 */
final readonly class ChaineIntegrite
{
    /** Le maillon d'origine : la chaine part d'une valeur connue et fixe. */
    public const GENESE = 'GENESE-JOURNAL-REMBOURSEMENT-DEMONSTRATION';

    public function __construct(private Connection $cnx)
    {
    }

    public const SCHEMA = <<<'SQL'
    CREATE TABLE IF NOT EXISTS remboursement.journal_integrite (
      rang bigserial PRIMARY KEY,
      transition_id integer NOT NULL UNIQUE,
      dossier_id integer NOT NULL,
      reference varchar(32) NOT NULL,
      transition varchar(32) NOT NULL,
      de_statut varchar(24),
      vers_statut varchar(24) NOT NULL,
      par varchar(180),
      le timestamptz NOT NULL,
      commentaire text,
      hash_precedent char(64) NOT NULL,
      hash_courant char(64) NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_ji_dossier ON remboursement.journal_integrite (dossier_id);
    SQL;

    public function installer(): void
    {
        $this->cnx->executeStatement(self::SCHEMA);
    }

    /**
     * Ajoute le maillon d'une transition. Appele par l'ecouteur, jamais par le
     * module historique.
     */
    public function ajouter(DossierTransition $t): void
    {
        $precedent = $this->cnx->fetchOne(
            'SELECT hash_courant FROM remboursement.journal_integrite ORDER BY rang DESC LIMIT 1');
        $hashPrecedent = \is_string($precedent) && '' !== $precedent
            ? $precedent
            : hash('sha256', self::GENESE);

        $ligne = $this->contenu(
            (int) $t->getId(),
            (int) $t->getDossier()->getId(),
            (string) $t->getDossier()->getReference(),
            $t->getTransition(),
            $t->getDeStatut()?->value,
            $t->getVersStatut()->value,
            $t->getPar(),
            $t->getLe()->format(DateTimeInterface::ATOM),
            $t->getCommentaire(),
        );

        $this->cnx->insert('remboursement.journal_integrite', [
            'transition_id' => (int) $t->getId(),
            'dossier_id' => (int) $t->getDossier()->getId(),
            'reference' => (string) $t->getDossier()->getReference(),
            'transition' => $t->getTransition(),
            'de_statut' => $t->getDeStatut()?->value,
            'vers_statut' => $t->getVersStatut()->value,
            'par' => $t->getPar(),
            'le' => $t->getLe()->format('Y-m-d H:i:sP'),
            'commentaire' => $t->getCommentaire(),
            'hash_precedent' => $hashPrecedent,
            'hash_courant' => hash('sha256', $hashPrecedent.'|'.$ligne),
        ]);
    }

    /**
     * Repose la chaine entiere depuis le journal courant.
     *
     * Necessaire apres un RESET DE DEMONSTRATION : rejouer une demonstration
     * suppose de supprimer des transitions, donc des maillons, et une chaine a
     * laquelle on retire un anneau du milieu est cassee -- elle a raison de le
     * dire. La chaine protege une exploitation, pas une machine a remonter le
     * temps. On la repose donc, et le rapport le dit plutot que de le cacher.
     *
     * Consequence a assumer : la chaine ne garantit que ce qui vient APRES sa
     * pose. Elle ne peut pas authentifier retrospectivement des lignes qui
     * existaient avant elle.
     *
     * @return int le nombre de maillons poses
     */
    public function reconstruire(): int
    {
        $this->installer();
        $this->cnx->executeStatement('TRUNCATE remboursement.journal_integrite RESTART IDENTITY');

        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT t.id, t.dossier_id, d.reference, t.transition, t.de_statut, t.vers_statut,
                    t.par, t.le, t.commentaire
               FROM remboursement.dossier_transition t
               JOIN remboursement.dossier d ON d.id = t.dossier_id
              ORDER BY t.id');

        $precedent = hash('sha256', self::GENESE);
        foreach ($lignes as $l) {
            $contenu = $this->contenu(
                (int) $l['id'], (int) $l['dossier_id'], (string) $l['reference'],
                (string) $l['transition'],
                null === $l['de_statut'] ? null : (string) $l['de_statut'],
                (string) $l['vers_statut'],
                null === $l['par'] ? null : (string) $l['par'],
                (new DateTimeImmutable((string) $l['le']))->format(DateTimeInterface::ATOM),
                null === $l['commentaire'] ? null : (string) $l['commentaire'],
            );
            $courant = hash('sha256', $precedent.'|'.$contenu);
            $this->cnx->insert('remboursement.journal_integrite', [
                'transition_id' => $l['id'], 'dossier_id' => $l['dossier_id'],
                'reference' => $l['reference'], 'transition' => $l['transition'],
                'de_statut' => $l['de_statut'], 'vers_statut' => $l['vers_statut'],
                'par' => $l['par'], 'le' => $l['le'], 'commentaire' => $l['commentaire'],
                'hash_precedent' => $precedent, 'hash_courant' => $courant,
            ]);
            $precedent = $courant;
        }

        return \count($lignes);
    }

    /**
     * Recalcule la chaine entiere et rend le premier ecart, s'il y en a un.
     *
     * @return array{maillons: int, ecarts: list<array{rang: int, reference: string, quoi: string}>, dernier: ?string}
     */
    public function verifier(): array
    {
        $maillons = $this->cnx->fetchAllAssociative(
            'SELECT j.rang, j.transition_id, j.dossier_id, j.reference, j.transition,
                    j.de_statut, j.vers_statut, j.par, j.le, j.commentaire,
                    j.hash_precedent, j.hash_courant,
                    t.id AS t_id, t.de_statut AS t_de, t.vers_statut AS t_vers,
                    t.transition AS t_transition, t.par AS t_par, t.le AS t_le,
                    t.commentaire AS t_commentaire, t.dossier_id AS t_dossier
               FROM remboursement.journal_integrite j
               LEFT JOIN remboursement.dossier_transition t ON t.id = j.transition_id
              ORDER BY j.rang');

        $attendu = hash('sha256', self::GENESE);
        $ecarts = [];
        $dernier = null;

        foreach ($maillons as $m) {
            $rang = (int) $m['rang'];

            if ($attendu !== (string) $m['hash_precedent']) {
                $ecarts[] = ['rang' => $rang, 'reference' => (string) $m['reference'],
                    'quoi' => 'chaine rompue : le maillon ne pointe pas le condensat precedent'];
            }

            $ligne = $this->contenu(
                (int) $m['transition_id'], (int) $m['dossier_id'], (string) $m['reference'],
                (string) $m['transition'], null === $m['de_statut'] ? null : (string) $m['de_statut'],
                (string) $m['vers_statut'], null === $m['par'] ? null : (string) $m['par'],
                (new DateTimeImmutable((string) $m['le']))->format(DateTimeInterface::ATOM),
                null === $m['commentaire'] ? null : (string) $m['commentaire'],
            );
            $recalcule = hash('sha256', (string) $m['hash_precedent'].'|'.$ligne);
            if ($recalcule !== (string) $m['hash_courant']) {
                $ecarts[] = ['rang' => $rang, 'reference' => (string) $m['reference'],
                    'quoi' => 'condensat faux : le maillon a ete recopie sans etre recalcule'];
            }

            // Le maillon dit-il encore la meme chose que la transition du module ?
            if (null === $m['t_id']) {
                $ecarts[] = ['rang' => $rang, 'reference' => (string) $m['reference'],
                    'quoi' => 'transition disparue du journal du module'];
            } else {
                $ecartsChamps = [];
                foreach ([
                    'transition' => 't_transition', 'de_statut' => 't_de', 'vers_statut' => 't_vers',
                    'par' => 't_par', 'commentaire' => 't_commentaire',
                ] as $champ => $miroir) {
                    if ((string) $m[$champ] !== (string) $m[$miroir]) {
                        $ecartsChamps[] = $champ;
                    }
                }
                if ((new DateTimeImmutable((string) $m['le']))->format(DateTimeInterface::ATOM)
                    !== (new DateTimeImmutable((string) $m['t_le']))->format(DateTimeInterface::ATOM)) {
                    $ecartsChamps[] = 'le';
                }
                if ([] !== $ecartsChamps) {
                    $ecarts[] = ['rang' => $rang, 'reference' => (string) $m['reference'],
                        'quoi' => 'transition modifiee en base, champ(s) : '.implode(', ', $ecartsChamps)];
                }
            }

            $attendu = (string) $m['hash_courant'];
            $dernier = (string) $m['hash_courant'];
        }

        // Une transition du module sans maillon : ligne ajoutee a la main.
        $orphelines = $this->cnx->fetchAllAssociative(
            'SELECT t.id, d.reference FROM remboursement.dossier_transition t
               JOIN remboursement.dossier d ON d.id = t.dossier_id
              WHERE NOT EXISTS (SELECT 1 FROM remboursement.journal_integrite j
                                 WHERE j.transition_id = t.id)
              ORDER BY t.id LIMIT 20');
        foreach ($orphelines as $o) {
            $ecarts[] = ['rang' => 0, 'reference' => (string) $o['reference'],
                'quoi' => 'transition '.$o['id'].' presente dans le module mais absente de la chaine'];
        }

        return ['maillons' => \count($maillons), 'ecarts' => $ecarts, 'dernier' => $dernier];
    }

    /** Le contenu couvert par le condensat. Tout y est, rien d'implicite. */
    private function contenu(
        int $transitionId,
        int $dossierId,
        string $reference,
        string $transition,
        ?string $deStatut,
        ?string $versStatut,
        ?string $par,
        string $le,
        ?string $commentaire,
    ): string {
        return implode("\x1f", [
            (string) $transitionId, (string) $dossierId, $reference, $transition,
            (string) $deStatut, (string) $versStatut, (string) $par, $le, (string) $commentaire,
        ]);
    }
}
