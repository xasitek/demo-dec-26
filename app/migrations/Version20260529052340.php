<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Recouvrement — optimisations d'index.
 *
 * Audit base : tous les index simples sur les colonnes filtrees sont
 * deja la (CompteCode, statut, dates, etc.). Manquaient les composites
 * pour les requetes critiques :
 *
 * - `action(destinataire_id, realisee, echeance)` : `findAFaire(User)`
 *   trie par echeance et filtre par 2 colonnes → index couvrant.
 * - `action(compte_code, realisee)` : timeline actions d'un compte.
 * - `promesse(ecriture_numero, statut)` : `findActiveByEcriture` (cas
 *   le plus chaud, appele sur chaque pop-up de detail ecriture).
 * - `relance_envoi(compte_code, envoye_le)` : `intensiteRelance()` qui
 *   filtre par compte ET periode envoye_le > NOW - interval.
 * - `dossier(compte_code, statut)` : `findByCompteEtType` + verifications
 *   dossier ouvert.
 * - `email_reponse(compte_code, traite)` : non traites par compte.
 * - `action(cree_le)` : intensiteRelance filtre par cree_le sur la
 *   periode de 30/90 jours.
 *
 * Pas de modifications de structure, uniquement des index. Operation
 * concurrente non utilisee ici (volumes faibles a moyen).
 */
final class Version20260529052340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recouvrement : index composites pour les requetes critiques (intensite relance, comptes inactifs, action a faire)';
    }

    public function up(Schema $schema): void
    {
        // action : findAFaire(User) - 3 colonnes (destinataire + realisee + echeance trie).
        $this->addSql('CREATE INDEX idx_creances_action_dest_real_ech ON creances.action (destinataire_id, realisee, echeance)');

        // action : timeline d'un compte (compte_code + realisee).
        $this->addSql('CREATE INDEX idx_creances_action_compte_real ON creances.action (compte_code, realisee)');

        // action : intensiteRelance() filtre par cree_le > NOW - INTERVAL.
        $this->addSql('CREATE INDEX idx_creances_action_compte_cree ON creances.action (compte_code, cree_le)');

        // promesse : findActiveByEcriture (ecriture + statut en_cours).
        $this->addSql('CREATE INDEX idx_creances_promesse_ecr_statut ON creances.promesse (ecriture_numero, statut)');

        // relance_envoi : intensiteRelance() filtre compte + envoye_le.
        $this->addSql('CREATE INDEX idx_creances_re_compte_envoye ON creances.relance_envoi (compte_code, envoye_le)');

        // dossier : dossier ouvert par compte (statut + type).
        $this->addSql('CREATE INDEX idx_creances_dossier_compte_statut ON creances.dossier (compte_code, statut)');

        // email_reponse : non traites par compte.
        $this->addSql('CREATE INDEX idx_creances_er_compte_traite ON creances.email_reponse (compte_code, traite)');

        // note : timeline par compte trie par cree_le (deja idx_note_compte mais
        // sans cree_le ; on ajoute le composite).
        $this->addSql('CREATE INDEX idx_creances_note_compte_cree ON creances.note (compte_code, cree_le)');

        // strategie_niveau : on cherche souvent par strategie_id + ordre (deja
        // UNIQUE composite uniq_creances_sn_ordre).

        // dossier_ecriture : verification "ecriture deja dans un dossier ouvert"
        // (jointure avec dossier).statut = 'ouvert').
        $this->addSql('CREATE INDEX idx_creances_de_ecr_dossier ON creances.dossier_ecriture (ecriture_numero, dossier_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX creances.idx_creances_action_dest_real_ech');
        $this->addSql('DROP INDEX creances.idx_creances_action_compte_real');
        $this->addSql('DROP INDEX creances.idx_creances_action_compte_cree');
        $this->addSql('DROP INDEX creances.idx_creances_promesse_ecr_statut');
        $this->addSql('DROP INDEX creances.idx_creances_re_compte_envoye');
        $this->addSql('DROP INDEX creances.idx_creances_dossier_compte_statut');
        $this->addSql('DROP INDEX creances.idx_creances_er_compte_traite');
        $this->addSql('DROP INDEX creances.idx_creances_note_compte_cree');
        $this->addSql('DROP INDEX creances.idx_creances_de_ecr_dossier');
    }
}
