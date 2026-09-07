<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remboursement — adresse mise en copie de l'attestation de paiement, et carnet
 * d'adresses de la secretaire.
 *
 * `dossier.email_copie` : champ FACULTATIF du formulaire de depot, aux deux motifs.
 * Rempli, il ajoute son adresse en copie du SEUL e-mail de validation definitive,
 * celui qui porte l'attestation en piece jointe. Vide, rien ne change.
 *
 * `email_favori` : les adresses deja saisies par une secretaire, proposees en
 * autocompletion a son depot suivant. Une ligne par couple (secretaire, adresse),
 * garantie par l'index unique — l'ecriture est un UPSERT qui incremente `nb_usages`,
 * de sorte que les adresses les plus utilisees remontent en premier.
 *
 * Ces adresses sont EN CLAIR, contrairement a l'IBAN et au BIC du meme dossier. Deux
 * raisons : le carnet doit etre comparable et filtrable en SQL (unicite, `LIKE`,
 * tri par usage), et chiffrer la colonne du dossier alors que les memes adresses
 * vivent en clair dans le carnet ne protegerait rien. La table `dossier` porte deja
 * des adresses en clair (`cree_par`, le compte de la secretaire).
 *
 * L'index (secretaire, dernier_usage_le DESC) sert la lecture du carnet : elle est
 * toujours filtree sur UNE secretaire et triee par usage recent.
 */
final class Version20260903170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remboursement : adresse facultative en copie de l\'attestation, et carnet d\'adresses par secrétaire';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remboursement.dossier ADD email_copie VARCHAR(190) DEFAULT NULL');

        $this->addSql('CREATE SEQUENCE remboursement.email_favori_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE remboursement.email_favori (
                id BIGINT NOT NULL DEFAULT nextval('remboursement.email_favori_id_seq'),
                secretaire VARCHAR(190) NOT NULL,
                email VARCHAR(190) NOT NULL,
                nb_usages INT NOT NULL DEFAULT 1,
                dernier_usage_le TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER SEQUENCE remboursement.email_favori_id_seq OWNED BY remboursement.email_favori.id');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_favori ON remboursement.email_favori (secretaire, email)');
        $this->addSql('CREATE INDEX idx_email_favori_usage ON remboursement.email_favori (secretaire, dernier_usage_le DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE remboursement.email_favori');
        $this->addSql('DROP SEQUENCE remboursement.email_favori_id_seq');
        $this->addSql('ALTER TABLE remboursement.dossier DROP email_copie');
    }
}
