<?php

declare(strict_types=1);

namespace App\GrandsComptes\Moteur;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le circuit d'un dossier, et la trace de chaque acte.
 *
 * L'etat d'un dossier ne se stocke pas en double : il se DEDUIT des faits --
 * les pieces presentes, et les actes poses. Un statut recopie a cote finit
 * toujours par mentir, et c'est devant un loueur qu'on s'en apercoit.
 *
 * Cinq etats, dans l'ordre du circuit :
 *
 *   a_completer   il manque une piece que la grille du loueur exige. La
 *                 secretaire la depose.
 *   a_verifier    le dossier est complet et certifie. Le comptable controle.
 *   conforme      le controle a conclu, le dossier part au loueur.
 *   renvoye       le controle a trouve une anomalie etablie. Le site corrige
 *                 et redepose -- une facture se corrige par un avoir.
 *   a_instruire   le controle n'a pas pu conclure : piece absente ou valeur
 *                 illisible. On leve le doute, on ne le tranche pas.
 */
final class Circuit
{
    /** @var array<string, array{libelle: string, teinte: string, qui: string, quoi: string}> */
    public const ETATS = [
        'a_completer' => [
            'libelle' => 'À compléter',
            'teinte' => 'warning',
            'qui' => 'secrétaire',
            'quoi' => 'Déposer les pièces que la grille du loueur exige.',
        ],
        'a_verifier' => [
            'libelle' => 'À vérifier',
            'teinte' => 'navy',
            'qui' => 'comptable',
            'quoi' => 'Contrôler les pièces et rendre un verdict.',
        ],
        'conforme' => [
            'libelle' => 'Conforme, transmis',
            'teinte' => 'positive',
            'qui' => 'personne',
            'quoi' => 'Le dossier est parti au loueur. La créance peut être recouvrée.',
        ],
        'renvoye' => [
            'libelle' => 'Renvoyé au site',
            'teinte' => 'negative',
            'qui' => 'secrétaire',
            'quoi' => 'Corriger l’anomalie nommée, puis redéposer le dossier.',
        ],
        'a_instruire' => [
            'libelle' => 'À instruire',
            'teinte' => 'gold',
            'qui' => 'comptable et secrétaire',
            'quoi' => 'Une pièce manque ou ne se lit pas : lever le doute avant de conclure.',
        ],
    ];

    /**
     * Les actes, et ce que chacun produit.
     *
     * @var array<string, array{libelle: string, etat: ?string, role: string, effet: string}>
     */
    public const ACTES = [
        'deposer_piece' => [
            'libelle' => 'Déposer une pièce',
            'etat' => null,
            'role' => 'ROLE_SECRETAIRE',
            'effet' => 'La pièce entre au dossier. Tant qu’une pièce exigée manque, le dossier '
                .'reste à compléter.',
        ],
        'certifier' => [
            'libelle' => 'Certifier et soumettre',
            'etat' => 'a_verifier',
            'role' => 'ROLE_SECRETAIRE',
            'effet' => 'La secrétaire certifie l’exactitude des informations et soumet le dossier '
                .'au contrôle. La certification est datée et nominative.',
        ],
        'valider' => [
            'libelle' => 'Valider la conformité',
            'etat' => 'conforme',
            'role' => 'ROLE_COMPTABLE',
            'effet' => 'Le dossier part au loueur. Aucune anomalie établie ne subsiste.',
        ],
        'renvoyer' => [
            'libelle' => 'Renvoyer au site',
            'etat' => 'renvoye',
            'role' => 'ROLE_COMPTABLE',
            'effet' => 'Les anomalies retenues sont nommées et adressées au site. Celles qui '
                .'portent sur une facture appellent un avoir et une refacturation.',
        ],
        'instruire' => [
            'libelle' => 'Mettre à instruire',
            'etat' => 'a_instruire',
            'role' => 'ROLE_COMPTABLE',
            'effet' => 'Le contrôle n’a pas pu conclure. On demande la pièce ou la valeur '
                .'manquante, sans prononcer de rejet.',
        ],
        'note' => [
            'libelle' => 'Ajouter une note',
            'etat' => null,
            'role' => 'ROLE_COMPTABLE',
            'effet' => 'Consigne une observation datée, lisible par le site et par le prochain '
                .'comptable qui ouvrira le dossier.',
        ],
    ];

    public function __construct(
        private readonly Connection $cnx,
        private readonly Security $securite,
    ) {
    }

    /**
     * L'etat courant d'un dossier, deduit des faits et des actes.
     *
     * @return array{etat: string, depuis: ?string, dernier_acte: ?array<string, mixed>,
     *               manquantes: list<string>, certifie: bool}
     */
    public function etat(string $dossierId): array
    {
        $dernier = $this->cnx->fetchAssociative(
            "SELECT * FROM grands_comptes.acte
              WHERE dossier_id = ? AND type IN ('certifier','valider','renvoyer','instruire')
              ORDER BY fait_le DESC, id DESC LIMIT 1", [$dossierId]) ?: null;

        /** @var list<string> $manquantes */
        $manquantes = $this->cnx->fetchFirstColumn(
            "SELECT p.type_piece FROM grands_comptes.piece p
              WHERE p.dossier_id = ? AND NOT p.presente
                AND p.exigence IN ('obligatoire','si_electrique','si_premier_reglt')
              ORDER BY p.type_piece", [$dossierId]);

        $certifie = null !== $this->cnx->fetchOne(
            "SELECT 1 FROM grands_comptes.acte WHERE dossier_id = ? AND type = 'certifier' LIMIT 1",
            [$dossierId]) && false !== $this->cnx->fetchOne(
                "SELECT 1 FROM grands_comptes.acte WHERE dossier_id = ? AND type = 'certifier' LIMIT 1",
                [$dossierId]);

        // Une decision de controle prime : c'est le dernier mot du circuit.
        if (null !== $dernier && \in_array($dernier['type'], ['valider', 'renvoyer', 'instruire'], true)) {
            $etat = (string) self::ACTES[(string) $dernier['type']]['etat'];

            return ['etat' => $etat, 'depuis' => (string) $dernier['fait_le'],
                'dernier_acte' => $dernier, 'manquantes' => $manquantes, 'certifie' => $certifie];
        }

        // Sinon, ce sont les pieces qui parlent.
        $etat = [] !== $manquantes ? 'a_completer' : 'a_verifier';

        return ['etat' => $etat, 'depuis' => null !== $dernier ? (string) $dernier['fait_le'] : null,
            'dernier_acte' => $dernier, 'manquantes' => $manquantes, 'certifie' => $certifie];
    }

    /** Pose un acte et le trace. */
    public function poser(
        string $dossierId,
        string $type,
        ?string $pieceId = null,
        ?string $codeAnomalie = null,
        ?string $commentaire = null,
    ): void {
        if (!isset(self::ACTES[$type])) {
            throw new InvalidArgumentException('Acte inconnu : '.$type);
        }
        $utilisateur = $this->securite->getUser();

        $this->cnx->insert('grands_comptes.acte', [
            'dossier_id' => $dossierId,
            'type' => $type,
            'piece_id' => $pieceId,
            'code_anomalie' => $codeAnomalie,
            'commentaire' => null !== $commentaire && '' !== $commentaire
                ? mb_substr($commentaire, 0, 400) : null,
            'auteur' => $utilisateur?->getUserIdentifier() ?? 'inconnu',
            'role' => self::ACTES[$type]['role'],
        ]);
    }

    /**
     * L'historique d'un dossier, du plus recent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function historique(string $dossierId): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->cnx->fetchAllAssociative(
            'SELECT * FROM grands_comptes.acte WHERE dossier_id = ?
              ORDER BY fait_le DESC, id DESC LIMIT 40', [$dossierId]);

        return $lignes;
    }
}
