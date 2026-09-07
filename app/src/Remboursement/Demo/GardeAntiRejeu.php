<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use Doctrine\DBAL\Connection;

/**
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION — anti-rejeu du paiement.
 *
 * Ce mecanisme N'EXISTE PAS dans le module historique. Ce que le module fait
 * deja, et qui n'est pas rien : `GenerationFichiersComptables::generer()`
 * refuse de travailler si le dossier n'est pas en « valide directeur ». Dans le
 * parcours normal, un second appel ne produit donc rien.
 *
 * Ce qui manquait, et pourquoi ce n'est pas theorique. Le MsgId du fichier SEPA
 * est bati sur l'horodatage courant -- `REMB-<AAAAMMJJHHMMSS>-<reference>` -- et
 * la generation SUPPRIME les anciens fichiers avant d'en ecrire de nouveaux. Une
 * relance depuis un etat ou la garde ne s'applique pas (reprise apres erreur de
 * generation, par exemple) refabriquerait donc, pour LE MEME paiement, un MsgId
 * different, un XML different et un condensat different. Cote banque, deux
 * messages differents pour un meme du, c'est exactement la porte du double
 * paiement.
 *
 * Ce que le renforcement ajoute :
 *   - une EMPREINTE posee a la premiere generation, indexee par une cle
 *     fonctionnelle -- reference, empreinte de l'IBAN retenu, montant retenu,
 *     etablissement -- et non par un identifiant technique ;
 *   - un REFUS de toute generation ulterieure pour cette cle, avec reutilisation
 *     du fichier existant ;
 *   - une TRACE de chaque tentative, pour que « rien ne s'est passe » soit un
 *     fait observable et pas une absence.
 */
final readonly class GardeAntiRejeu
{
    /** Ce que le module repond a une seconde demande de paiement. */
    public const MESSAGE = 'PAIEMENT DÉJÀ GÉNÉRÉ — FICHIER EXISTANT RÉUTILISÉ';

    public const SCHEMA = <<<'SQL'
    CREATE TABLE IF NOT EXISTS remboursement.paiement_empreinte (
      cle_fonctionnelle char(64) PRIMARY KEY,
      dossier_id integer NOT NULL,
      reference varchar(32) NOT NULL,
      msg_id varchar(80) NOT NULL,
      nom_sepa varchar(160) NOT NULL,
      hash_sepa char(64) NOT NULL,
      nom_od varchar(160),
      hash_od char(64),
      montant numeric(14,2) NOT NULL,
      genere_le timestamptz NOT NULL,
      rejeux integer NOT NULL DEFAULT 0,
      dernier_rejeu_le timestamptz
    );
    CREATE UNIQUE INDEX IF NOT EXISTS uniq_pe_dossier ON remboursement.paiement_empreinte (dossier_id);

    CREATE TABLE IF NOT EXISTS remboursement.rejeu_evenement (
      id bigserial PRIMARY KEY,
      cle_fonctionnelle char(64) NOT NULL,
      dossier_id integer NOT NULL,
      reference varchar(32) NOT NULL,
      source varchar(40) NOT NULL,
      decision varchar(32) NOT NULL,
      message text NOT NULL,
      le timestamptz NOT NULL DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS idx_re_dossier ON remboursement.rejeu_evenement (dossier_id);
    SQL;

    public function __construct(private Connection $cnx)
    {
    }

    public function installer(): void
    {
        $this->cnx->executeStatement(self::SCHEMA);
    }

    /**
     * La cle FONCTIONNELLE du paiement.
     *
     * Volontairement pas l'identifiant du dossier : ce qui ne doit pas partir
     * deux fois, c'est un montant, vers un compte, pour une reference, chez un
     * etablissement. L'empreinte d'IBAN est celle que le module calcule
     * lui-meme, de sorte que la cle ne transporte aucune coordonnee bancaire.
     */
    public function cle(Dossier $dossier): string
    {
        return hash('sha256', implode('|', [
            (string) $dossier->getReference(),
            (string) $dossier->getIbanHash(),
            (string) ($dossier->getValideMontant() ?: $dossier->getMontant()),
            (string) $dossier->getEtablissementCode(),
        ]));
    }

    /**
     * L'empreinte deja posee pour ce dossier, s'il y en a une.
     *
     * @return array<string, mixed>|null
     */
    public function empreinte(int $dossierId): ?array
    {
        $l = $this->cnx->fetchAssociative(
            'SELECT * FROM remboursement.paiement_empreinte WHERE dossier_id = ?', [$dossierId]);

        return false === $l ? null : $l;
    }

    /**
     * Pose l'empreinte a la premiere generation.
     *
     * Idempotent : si le dossier porte deja une empreinte, on n'ecrase rien --
     * ce serait perdre la trace du premier fichier, c'est-a-dire exactement ce
     * qu'on cherche a conserver.
     */
    public function enregistrerSepa(
        Dossier $dossier,
        DossierPiece $sepa,
        ?string $nomOd,
        ?string $contenuOd,
    ): void {
        if (null !== $this->empreinte((int) $dossier->getId())) {
            return;
        }

        $this->cnx->executeStatement(
            'INSERT INTO remboursement.paiement_empreinte
               (cle_fonctionnelle, dossier_id, reference, msg_id, nom_sepa, hash_sepa,
                nom_od, hash_od, montant, genere_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (cle_fonctionnelle) DO NOTHING',
            [
                $this->cle($dossier),
                (int) $dossier->getId(),
                (string) $dossier->getReference(),
                self::msgId($sepa->getContenu()),
                $sepa->getNomFichier(),
                hash('sha256', $sepa->getContenu()),
                $nomOd,
                null === $contenuOd ? null : hash('sha256', $contenuOd),
                (string) ($dossier->getValideMontant() ?: $dossier->getMontant()),
            ]);
    }

    /**
     * Une tentative de rejeu : on la compte, on la trace, on rend le message.
     *
     * @param array<string, mixed> $empreinte
     */
    public function tracerRejeu(array $empreinte, string $source): string
    {
        $this->cnx->executeStatement(
            'UPDATE remboursement.paiement_empreinte
                SET rejeux = rejeux + 1, dernier_rejeu_le = now()
              WHERE dossier_id = ?', [$empreinte['dossier_id']]);

        $message = sprintf('%s — %s, MsgId %s, condensat %s',
            self::MESSAGE, (string) $empreinte['nom_sepa'], (string) $empreinte['msg_id'],
            substr((string) $empreinte['hash_sepa'], 0, 16).'…');

        $this->cnx->insert('remboursement.rejeu_evenement', [
            'cle_fonctionnelle' => (string) $empreinte['cle_fonctionnelle'],
            'dossier_id' => (int) $empreinte['dossier_id'],
            'reference' => (string) $empreinte['reference'],
            'source' => $source,
            'decision' => 'refuse_rejeu',
            'message' => $message,
        ]);

        return $message;
    }

    /** Le MsgId tel que le fichier le porte, lu sur le fichier lui-meme. */
    public static function msgId(string $xml): string
    {
        return 1 === preg_match('#<MsgId>([^<]+)</MsgId>#', $xml, $m) ? trim($m[1]) : 'inconnu';
    }
}
