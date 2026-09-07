<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

/**
 * LE CONTRAT DE MESURE DE L'OUTIL 8, FIGE.
 *
 * Chaque classe de cas dit quatre choses, et les quatre sont obligatoires :
 * ce qu'elle met a l'epreuve, ce qu'on en attend, QUEL CONTROLE du registre
 * doit parler, et QUELLE PREMISSE doit etre materiellement presente pour que
 * l'attente ait un sens.
 *
 * L'INVARIANT. Une verite ne peut etre creee que si sa premisse est
 * materiellement presente dans le scenario. La fabrique le verifie avant
 * d'evaluer, et la commande app:demo:figer-fabrique-mesure verifie le contrat
 * lui-meme : un controle qui n'existe pas dans le registre, une premisse
 * inconnue de la fabrique, une attente sans cause -- et le gel echoue.
 *
 * CE QUI A ETE RETIRE, ET POURQUOI. Deux scenarios de la mesure diagnostique
 * ont disparu. Le premier reposait sur une revelation d'IBAN journalisee : ce
 * controle n'existe pas dans le module, et on ne l'a pas ajoute pour faire
 * passer un test. Le second demandait une orientation humaine sans pouvoir
 * dire quel controle l'aurait provoquee : une attente sans cause n'est pas une
 * verite. Le compte de classes vaut ce qu'il vaut -- dix-huit -- plutot que de
 * tenir un chiffre rond.
 */
final class ContratsMesure
{
    /**
     * @return list<CasMesure>
     */
    public static function toutes(): array
    {
        return [
            new CasMesure('CAS-01', 'Dossier propre, ecart nul', 'autorise', 'C31',
                'valeurs retenues completes, aucune divergence',
                'le dossier va au paiement', ['dossier', 'contrat_buyback']),

            new CasMesure('CAS-02', 'IBAN saisi hors structure', 'bloque', 'C44',
                'la generation refuse un IBAN qui ne respecte pas la structure',
                'aucun fichier de paiement', ['dossier', 'iban_saisi_hors_structure']),

            new CasMesure('CAS-03', 'Doublon parfait, temoin actif present', 'humain', 'C32',
                'un jumeau actif porte la meme cle anti-doublon',
                'le comptable tranche', ['dossier', 'temoin_actif', 'meme_cle_doublon']),

            new CasMesure('CAS-04', 'Meme compte bancaire, deux codes clients', 'humain', 'C33',
                'un dossier actif porte la meme empreinte d\'IBAN',
                'le comptable tranche', ['dossier', 'temoin_actif', 'meme_empreinte_iban']),

            new CasMesure('CAS-05', 'Surpaiement Buy Back de 600 €', 'refus_entree', 'C07',
                'l\'ecart depasse la tolerance de 3 € declaree dans le code',
                'le depot est refuse', ['contrat_buyback', 'ecart_recalculable']),

            new CasMesure('CAS-06', 'Ecart Buy Back de 2 €, sous tolerance', 'autorise', 'C07',
                'l\'ecart reste sous la tolerance de 3 €',
                'le dossier passe', ['dossier', 'contrat_buyback', 'ecart_recalculable']),

            new CasMesure('CAS-07', 'IBAN du RIB different de la saisie', 'humain', 'C20',
                'la lecture rend l\'IBAN du RIB, la saisie porte un autre',
                'le comptable tranche', ['dossier', 'rib_divergent']),

            new CasMesure('CAS-08', 'Montant de la facture different de la saisie', 'humain', 'C21',
                'la lecture rend le montant de la facture',
                'le comptable tranche', ['dossier', 'facture_divergente']),

            new CasMesure('CAS-09', 'Vehicule gage au certificat', 'humain', 'C26',
                'le certificat declare le vehicule non libre',
                'le comptable tranche', ['dossier', 'vehicule_declare_gage']),

            new CasMesure('CAS-10', 'Mention manuscrite sur la carte grise', 'humain', 'C25',
                'la carte grise porte une mention manuscrite',
                'porte a l\'ecran du comptable', ['dossier', 'mention_manuscrite_declaree']),

            new CasMesure('CAS-11', 'Estimation de reprise retouchee', 'humain', 'C27',
                'l\'offre de reprise porte des modifications apres edition',
                'le comptable tranche', ['dossier', 'estimation_retouchee_declaree']),

            new CasMesure('CAS-12', 'Document illisible', 'humain', 'C28',
                'la piece se declare illisible',
                'le comptable arbitre, aucun refus automatique', ['dossier', 'piece_declaree_illisible']),

            new CasMesure('CAS-13', 'Panne du lecteur de pieces', 'humain', 'C17',
                'le fournisseur leve son indisponibilite, la transition est echec_extraction',
                'controle humain requis, jamais un refus', ['dossier', 'panne_declaree']),

            new CasMesure('CAS-14', 'Valeurs retenues incompletes', 'refus_entree', 'C31',
                'le module exige nom, IBAN, BIC, montant et cle metier',
                'la validation comptable est refusee', ['dossier', 'valeurs_retenues_incompletes']),

            new CasMesure('CAS-15', 'Generation demandee sans validation directeur', 'bloque', 'C40',
                'la generation n\'accepte que l\'etat « valide directeur »',
                'aucun fichier de paiement', ['dossier']),

            new CasMesure('CAS-16', 'Rejeu de la demande de paiement', 'bloque', 'R02',
                'un paiement existe deja pour cette cle fonctionnelle',
                'aucun second fichier, tentative tracee', ['dossier', 'paiement_deja_produit']),

            new CasMesure('CAS-17', 'Surpaiement presente au paiement', 'bloque', 'R01',
                'le meme controle Buy Back rejoue avant la caisse',
                'paiement bloque, tentative tracee', ['dossier', 'contrat_buyback', 'ecart_recalculable']),

            // CE CAS A CHANGE DE VERITE, ET L'HISTOIRE COMPTE.
            //
            // Avant le renforcement R04, ce cas etablissait un TROU : le module
            // verifiait la structure de l'IBAN et jamais sa cle de controle, si
            // bien qu'un IBAN de cle fausse entrait dans le fichier de paiement.
            // La mesure BLIND_O8_2 l'a constate sur vingt cas sur vingt.
            //
            // R04 a ete ajoute dans la copie de demonstration -- a l'entree et
            // devant la caisse. L'attendu devient donc BLOQUE. Le constat, lui,
            // reste ecrit : il n'a pas ete efface, il a ete corrige.
            new CasMesure('CAS-18', 'IBAN a la cle MOD 97 fausse', 'bloque', 'R04',
                'la cle de controle est verifiee, ce que le module herite ne faisait pas',
                'aucun fichier de paiement, tentative tracee',
                ['dossier', 'iban_saisi_cle_fausse']),
        ];
    }
}
