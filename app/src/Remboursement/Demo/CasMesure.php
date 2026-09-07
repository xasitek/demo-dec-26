<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

/**
 * Un cas de mesure : sa premisse, ce qu'on en attend, et pourquoi.
 *
 * L'UNITE EST LE CAS, PAS LE DOSSIER. Certains cas -- une saisie d'IBAN
 * invalide, par exemple -- sont des TENTATIVES qui ne doivent produire aucun
 * dossier payable. Compter en dossiers rendrait ces cas invisibles.
 *
 * L'INVARIANT QUI COMMANDE TOUT. Une verite ne peut exister que si sa premisse
 * est materiellement presente dans le cas. C'est la lecon de la mesure
 * diagnostique : elle annoncait des doublons attendus sans qu'aucun jumeau
 * existe, et un IBAN mal forme alors que tous les IBAN charges etaient valides.
 * Une attente sans cause n'est pas une verite, c'est un souhait.
 *
 * Chaque cas porte donc :
 *   - la LISTE DES OBJETS que sa premisse exige (`premisses`) ;
 *   - le CONTROLE du registre qui doit parler (`controle`) ;
 *   - la PREUVE qu'on pourra montrer (`preuve`) ;
 *   - l'ACTION attendue (`action`).
 *
 * La fabrique de mesure verifie que chaque premisse declaree est reellement la
 * avant d'evaluer, et elle echoue autrement.
 */
final readonly class CasMesure
{
    /**
     * @param string       $classe    la classe de cas (CAS-01, CAS-02...)
     * @param string       $libelle   ce que le cas met a l'epreuve
     * @param string       $attendu   'autorise' | 'bloque' | 'humain' | 'refus_entree'
     * @param string       $controle  le code du registre qui doit parler
     * @param string       $preuve    ce qu'on pourra montrer a un jury
     * @param string       $action    l'action attendue du module
     * @param list<string> $premisses les objets que la premisse exige
     */
    public function __construct(
        public string $classe,
        public string $libelle,
        public string $attendu,
        public string $controle,
        public string $preuve,
        public string $action,
        public array $premisses,
    ) {
    }
}
