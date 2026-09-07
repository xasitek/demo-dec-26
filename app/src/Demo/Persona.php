<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Les postes depuis lesquels l'examinateur peut regarder la suite.
 *
 * Ce ne sont pas des habillages : chaque poste a ses roles, son perimetre et
 * donc ses ecrans. Le meme dossier ne se presente pas de la meme facon a la
 * secretaire qui l'a depose, au comptable qui l'instruit et au directeur qui
 * doit engager le decaissement. C'est la separation des taches, vue de face.
 */
enum Persona: string
{
    case SECRETAIRE = 'secretaire';
    case COMPTABLE = 'comptable';
    case DIRECTEUR_CONCESSION = 'directeur-concession';
    case DIRECTEUR_COMPTABLE = 'directeur-comptable';
    case CREDIT_MANAGER = 'credit-manager';
    case EXPERT_COMPTABLE = 'expert-comptable';

    public function libelle(): string
    {
        return match ($this) {
            self::SECRETAIRE => 'Secrétaire',
            self::COMPTABLE => 'Comptable',
            self::DIRECTEUR_CONCESSION => 'Directeur de concession',
            self::DIRECTEUR_COMPTABLE => 'Directeur comptable',
            self::CREDIT_MANAGER => 'Credit manager',
            self::EXPERT_COMPTABLE => 'Expert-comptable',
        };
    }

    /** Ce que ce poste fait dans le circuit, en une phrase. */
    public function mission(): string
    {
        return match ($this) {
            self::SECRETAIRE => 'Dépose, complète les dossiers, fournit les pièces et suit leur état.',
            self::COMPTABLE => 'Vérifie, rapproche, corrige, lettre et instruit les exceptions.',
            self::DIRECTEUR_CONCESSION => 'Voit uniquement son périmètre et prend les décisions qui lui appartiennent.',
            self::DIRECTEUR_COMPTABLE => 'Pilote les équipes, les volumes, les anomalies et les résultats.',
            self::CREDIT_MANAGER => 'Pilote le risque, la relance, les stratégies et les priorités.',
            self::EXPERT_COMPTABLE => 'Lecture transversale : contrôles, méthode, limites et résultats.',
        };
    }

    /** Ce que ce poste voit de plus, ou de moins, que les autres. */
    public function perspective(): string
    {
        return match ($this) {
            self::SECRETAIRE => 'Ses seuls dossiers, un statut simplifié, et les corrections qu\'on lui demande. Ni les contrôles internes, ni les mécanismes de paiement, ni les dossiers des autres établissements.',
            self::COMPTABLE => 'La file à vérifier, la saisie confrontée à l\'extraction puis à la valeur retenue, les doublons, l\'IBAN, l\'engagement de reprise, et l\'action suivante.',
            self::DIRECTEUR_CONCESSION => 'Son établissement et rien d\'autre. Les dossiers qui attendent sa validation, leur montant, la pièce déterminante et la conséquence de sa décision.',
            self::DIRECTEUR_COMPTABLE => 'Tous les établissements autorisés : volumes, files d\'attente, anomalies, taux de traitement, montants arrêtés et répartition par motif.',
            self::CREDIT_MANAGER => 'Les stratégies et leurs niveaux, les promesses, la priorisation et ce qu\'il ne faut surtout pas relancer.',
            self::EXPERT_COMPTABLE => 'Le parcours complet : règles déclarées, piste d\'audit, explicabilité, indicateurs de performance et limites assumées.',
        };
    }

    /** Adresse de messagerie du profil synthetique correspondant. */
    public function email(): string
    {
        return $this->value.'@demonstration.invalid';
    }

    /** @return list<string> */
    public function roles(): array
    {
        return match ($this) {
            self::SECRETAIRE => ['ROLE_SECRETAIRE'],
            self::COMPTABLE => ['ROLE_COMPTABLE'],
            self::DIRECTEUR_CONCESSION => ['ROLE_DIRECTEUR'],
            self::DIRECTEUR_COMPTABLE => ['ROLE_MANAGER', 'ROLE_COMPTABLE', 'ROLE_DIRECTEUR'],
            self::CREDIT_MANAGER => ['ROLE_MANAGER', 'ROLE_COMPTABLE'],
            self::EXPERT_COMPTABLE => ['ROLE_MANAGER', 'ROLE_COMPTABLE', 'ROLE_DIRECTEUR', 'ROLE_AUDITEUR', 'ROLE_ADMIN'],
        };
    }

    /** Nombre d'etablissements rattaches : le perimetre EST la difference. */
    public function nbEtablissements(): int
    {
        return match ($this) {
            self::DIRECTEUR_CONCESSION => 1,
            self::SECRETAIRE => 1,
            default => 0, // 0 = tous
        };
    }

    public function initiales(): string
    {
        return match ($this) {
            self::SECRETAIRE => 'SE', self::COMPTABLE => 'CO',
            self::DIRECTEUR_CONCESSION => 'DC', self::DIRECTEUR_COMPTABLE => 'DK',
            self::CREDIT_MANAGER => 'CM', self::EXPERT_COMPTABLE => 'EC',
        };
    }

    /** Teinte du poste, dans la palette de la suite. */
    public function teinte(): string
    {
        return match ($this) {
            self::SECRETAIRE => '#6b7280', self::COMPTABLE => '#d97706',
            self::DIRECTEUR_CONCESSION => '#0284c7', self::DIRECTEUR_COMPTABLE => '#2D3250',
            self::CREDIT_MANAGER => '#0d9488', self::EXPERT_COMPTABLE => '#A88A56',
        };
    }
}
