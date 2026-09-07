<?php

declare(strict_types=1);

namespace App\Remboursement\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le registre des controles du module Remboursement, recompte SUR LE CODE.
 *
 * Ce n'est pas une liste d'intentions. Chaque ligne porte une PREUVE : un
 * fichier et un fragment de code qui doit s'y trouver. La commande relit les
 * fichiers et echoue si un seul fragment a disparu. Un controle retire du code
 * fait donc tomber le registre, et le nombre affiche cesse d'etre vrai.
 *
 * LA REGLE DE COMPTAGE, ecrite avant de compter. Un controle est UNE condition
 * evaluee sur UNE donnee, qui change le sort du dossier : elle le bloque, elle
 * alerte quelqu'un, ou elle l'informe. Deux conditions distinctes dans le code
 * font deux controles, meme si elles se ressemblent -- l'IBAN du debiteur et
 * celui du beneficiaire sont deux verifications, et il faut les deux. A
 * l'inverse, une meme condition ecrite a deux endroits ne compte qu'une fois.
 *
 * TROIS NATURES, ET IL NE FAUT JAMAIS LES CONFONDRE.
 *   - BLOQUANT : le geste n'a pas lieu. Un depot refuse, un paiement interdit.
 *   - ALERTE : le geste reste possible, mais quelqu'un doit trancher.
 *   - INFORMATIF : rien n'est empeche, une information est portee a l'ecran.
 *
 * On n'ecrit donc JAMAIS « N controles bloquants ». Le registre contient des
 * controles bloquants, des alertes et des controles informatifs, et le compte
 * par nature est affiche a la fin.
 */
#[AsCommand(
    name: 'app:demo:registre-controles',
    description: 'Le registre des controles, recompte sur le code, avec preuve par fichier.',
)]
final class RegistreControlesCommand extends Command
{
    /**
     * Le registre. Colonnes : famille, moment, donnee, condition, resultat,
     * nature, role, fichier de preuve, fragment attendu dans ce fichier.
     *
     * @var array<string, array{famille: string, moment: string, donnee: string, condition: string, resultat: string, nature: string, role: string, fichier: string, preuve: string}>
     */
    /**
     * Le registre des controles herites. Public parce que le gel de la fabrique
     * de mesure verifie que chaque classe de cas s'adosse a un controle qui
     * existe VRAIMENT ici.
     */
    public const REGISTRE = [
        // ------------------------------------------------ le depot (secretaire)
        'C01' => ['famille' => 'saisie', 'moment' => 'depot', 'donnee' => 'nom du client',
            'condition' => 'chaine vide apres nettoyage', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'Le nom du client est requis.'],
        'C02' => ['famille' => 'saisie', 'moment' => 'depot', 'donnee' => 'etablissement',
            'condition' => 'aucun etablissement choisi', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'tablissement est requis.'],
        'C03' => ['famille' => 'montant', 'moment' => 'depot', 'donnee' => 'montant demande',
            'condition' => 'montant nul ou negatif', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'Le montant doit être strictement positif.'],
        'C04' => ['famille' => 'bancaire', 'moment' => 'depot', 'donnee' => 'IBAN du client',
            'condition' => 'IBAN absent', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'IBAN du client est requis.'],
        'C05' => ['famille' => 'bancaire', 'moment' => 'depot', 'donnee' => 'BIC du client',
            'condition' => 'BIC absent', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'Le BIC du client est requis.'],
        'C06' => ['famille' => 'vehicule', 'moment' => 'depot', 'donnee' => 'immatriculation',
            'condition' => 'absente sur un rachat sec', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'immatriculation est requise.'],
        'C07' => ['famille' => 'contrat', 'moment' => 'depot', 'donnee' => 'montant vs engagement de reprise',
            'condition' => 'ecart superieur a la tolerance de 3 €', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'supérieur de'],
        'C08' => ['famille' => 'identite client', 'moment' => 'depot', 'donnee' => 'code client ICAR',
            'condition' => 'code absent', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'Le code client ICAR est requis.'],
        'C09' => ['famille' => 'pieces', 'moment' => 'depot', 'donnee' => 'pieces exigees par le motif',
            'condition' => 'une piece manque', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'Pièce manquante'],
        'C10' => ['famille' => 'pieces', 'moment' => 'depot', 'donnee' => 'format du fichier',
            'condition' => 'ni PDF ni image', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'format refusé'],
        'C11' => ['famille' => 'notification', 'moment' => 'depot', 'donnee' => 'adresse en copie',
            'condition' => 'adresse mal formee', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'est pas une adresse e-mail valide'],
        'C12' => ['famille' => 'integrite de la requete', 'moment' => 'depot', 'donnee' => 'jeton de session',
            'condition' => 'jeton absent ou perime', 'resultat' => 'depot refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => 'remboursement_depot'],
        'C13' => ['famille' => 'habilitation', 'moment' => 'depot', 'donnee' => 'role de l\'auteur',
            'condition' => 'role secretaire absent', 'resultat' => 'acces refuse',
            'nature' => 'bloquant', 'role' => 'secretaire',
            'fichier' => 'src/Remboursement/Controller/DepotController.php',
            'preuve' => "IsGranted('ROLE_SECRETAIRE')"],
        'C14' => ['famille' => 'doublon', 'moment' => 'depot', 'donnee' => 'cle fonctionnelle du dossier',
            'condition' => 'cle calculee et posee sur le dossier', 'resultat' => 'cle enregistree',
            'nature' => 'informatif', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/DepotDossier.php',
            'preuve' => 'CleDoublon::pour'],
        'C15' => ['famille' => 'doublon', 'moment' => 'depot', 'donnee' => 'empreinte de l\'IBAN',
            'condition' => 'empreinte aveugle calculee', 'resultat' => 'empreinte enregistree',
            'nature' => 'informatif', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Entity/Dossier.php',
            'preuve' => 'rafraichirIbanHash'],

        // ------------------------------------------------ la lecture des pieces
        'C16' => ['famille' => 'lecture', 'moment' => 'extraction', 'donnee' => 'gabarit du type de piece',
            'condition' => 'aucun gabarit pour ce type', 'resultat' => 'piece non lue, dossier poursuivi',
            'nature' => 'informatif', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/Ia/AnalyseDossierService.php',
            'preuve' => 'null === $gabarit'],
        'C17' => ['famille' => 'lecture', 'moment' => 'extraction', 'donnee' => 'disponibilite du fournisseur',
            'condition' => 'fournisseur indisponible', 'resultat' => 'extraction en surcharge, controle humain',
            'nature' => 'alerte', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/Ia/AnalyseDossierService.php',
            'preuve' => 'ProviderIndisponibleException'],
        'C18' => ['famille' => 'lecture', 'moment' => 'extraction', 'donnee' => 'reponse du fournisseur',
            'condition' => 'reponse inexploitable', 'resultat' => 'extraction en surcharge, controle humain',
            'nature' => 'alerte', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/Ia/AnalyseDossierService.php',
            'preuve' => 'ReponseNonExploitableException'],
        'C19' => ['famille' => 'lecture', 'moment' => 'extraction', 'donnee' => 'issue de l\'analyse',
            'condition' => 'quelle que soit l\'issue', 'resultat' => 'jamais un refus : route vers « a verifier »',
            'nature' => 'informatif', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/Ia/AnalyseDossierService.php',
            'preuve' => "'echec_extraction' : 'terminer_extraction'"],

        // ------------------------------------ la confrontation saisie / lecture
        'C20' => ['famille' => 'bancaire', 'moment' => 'controle comptable', 'donnee' => 'IBAN saisi vs IBAN du RIB',
            'condition' => 'les deux diverge', 'resultat' => 'verdict « a verifier »',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'IBAN saisi ≠ IBAN du RIB'],
        'C21' => ['famille' => 'montant', 'moment' => 'controle comptable', 'donnee' => 'montant saisi vs facture',
            'condition' => 'les deux diverge', 'resultat' => 'verdict « a verifier »',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'Montant saisi ≠ montant de la facture'],
        'C22' => ['famille' => 'vehicule', 'moment' => 'controle comptable', 'donnee' => 'immatriculation saisie vs facture',
            'condition' => 'les deux diverge', 'resultat' => 'verdict « a verifier »',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'Immatriculation saisie ≠ facture'],
        'C23' => ['famille' => 'identite client', 'moment' => 'controle comptable', 'donnee' => 'code ICAR saisi vs justificatif',
            'condition' => 'les deux diverge', 'resultat' => 'verdict « a verifier »',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'Code client ICAR saisi ≠ facture'],
        'C24' => ['famille' => 'montant', 'moment' => 'controle comptable', 'donnee' => 'montant vs solde du releve ICAR',
            'condition' => 'les deux diverge', 'resultat' => 'verdict « a verifier »',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'Montant saisi ≠ solde du relevé ICAR'],
        'C25' => ['famille' => 'pieces', 'moment' => 'controle comptable', 'donnee' => 'mentions manuscrites sur la carte grise',
            'condition' => 'mention detectee', 'resultat' => 'porte a l\'ecran du comptable',
            'nature' => 'informatif', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'carte_grise_manuscrite'],
        'C26' => ['famille' => 'vehicule', 'moment' => 'controle comptable', 'donnee' => 'situation administrative du vehicule',
            'condition' => 'vehicule gage ou frappe d\'opposition', 'resultat' => 'porte a l\'ecran du comptable',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'vehicule_libre'],
        'C27' => ['famille' => 'pieces', 'moment' => 'controle comptable', 'donnee' => 'estimation de reprise',
            'condition' => 'modifications detectees apres edition', 'resultat' => 'porte a l\'ecran du comptable',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'modifications_detectees'],
        'C28' => ['famille' => 'pieces', 'moment' => 'controle comptable', 'donnee' => 'lisibilite des pieces',
            'condition' => 'document declare illisible', 'resultat' => 'porte a l\'ecran du comptable',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/Ia/AgregateurControle.php',
            'preuve' => 'facture_invalide'],
        'C29' => ['famille' => 'bancaire', 'moment' => 'controle comptable', 'donnee' => 'valeur retenue vs valeur lue',
            'condition' => 'l\'IBAN retenu ne correspond ni a la saisie ni a la lecture',
            'resultat' => 'ecart signale a l\'ecran', 'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/EcartControle.php',
            'preuve' => 'function ibanDiverge'],
        'C30' => ['famille' => 'montant', 'moment' => 'controle comptable', 'donnee' => 'montant retenu vs montant lu',
            'condition' => 'le montant retenu ne correspond ni a la saisie ni a la lecture',
            'resultat' => 'ecart signale a l\'ecran', 'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Service/EcartControle.php',
            'preuve' => 'function montantDiverge'],
        'C31' => ['famille' => 'saisie', 'moment' => 'validation comptable', 'donnee' => 'valeurs retenues obligatoires',
            'condition' => 'nom, IBAN, BIC, montant ou cle metier manquants', 'resultat' => 'validation refusee',
            'nature' => 'bloquant', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Controller/DossierController.php',
            'preuve' => 'valeursValideesManquantes'],
        'C32' => ['famille' => 'doublon', 'moment' => 'controle comptable', 'donnee' => 'dossiers actifs de meme cle',
            'condition' => 'un jumeau actif porte la meme cle', 'resultat' => 'bandeau doublon, decision au comptable',
            'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Repository/DossierRepository.php',
            'preuve' => 'function doublonsActifs'],
        'C33' => ['famille' => 'doublon', 'moment' => 'controle comptable', 'donnee' => 'dossiers actifs de meme IBAN',
            'condition' => 'un dossier actif porte la meme empreinte bancaire',
            'resultat' => 'bandeau meme compte, decision au comptable', 'nature' => 'alerte', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Repository/DossierRepository.php',
            'preuve' => 'function memeIbanActif'],
        'C34' => ['famille' => 'integrite de la requete', 'moment' => 'validation comptable', 'donnee' => 'jeton de la fiche',
            'condition' => 'jeton absent ou faux', 'resultat' => 'action refusee',
            'nature' => 'bloquant', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Controller/DossierController.php',
            'preuve' => "isCsrfTokenValid('remb_action'"],
        'C35' => ['famille' => 'habilitation', 'moment' => 'controle comptable', 'donnee' => 'role de l\'auteur',
            'condition' => 'ni comptable, ni directeur, ni manager', 'resultat' => 'acces refuse',
            'nature' => 'bloquant', 'role' => 'comptable',
            'fichier' => 'src/Remboursement/Controller/DossierController.php',
            'preuve' => "is_granted('ROLE_COMPTABLE')"],

        // ------------------------------------------------ la decision directeur
        'C36' => ['famille' => 'habilitation', 'moment' => 'validation directeur', 'donnee' => 'signature du lien',
            'condition' => 'lien non signe ou altere', 'resultat' => 'acces refuse',
            'nature' => 'bloquant', 'role' => 'directeur',
            'fichier' => 'src/Remboursement/Controller/DirecteurController.php',
            'preuve' => 'Lien invalide ou expiré'],
        'C37' => ['famille' => 'workflow', 'moment' => 'toute transition', 'donnee' => 'statut de depart',
            'condition' => 'transition impossible depuis cet etat', 'resultat' => 'transition refusee',
            'nature' => 'bloquant', 'role' => 'tous',
            'fichier' => 'src/Remboursement/Service/WorkflowRemboursement.php',
            'preuve' => 'impossible depuis'],
        'C38' => ['famille' => 'workflow', 'moment' => 'toute transition', 'donnee' => 'role de l\'auteur',
            'condition' => 'role insuffisant pour la transition', 'resultat' => 'transition refusee',
            'nature' => 'bloquant', 'role' => 'tous',
            'fichier' => 'src/Remboursement/Service/WorkflowRemboursement.php',
            'preuve' => 'Role insuffisant pour la transition'],
        'C39' => ['famille' => 'workflow', 'moment' => 'toute transition', 'donnee' => 'nature de la transition',
            'condition' => 'transition reservee au systeme demandee par un humain', 'resultat' => 'transition refusee',
            'nature' => 'bloquant', 'role' => 'tous',
            'fichier' => 'src/Remboursement/Service/WorkflowRemboursement.php',
            'preuve' => 'reservee au systeme'],

        // --------------------------------------------------------- le paiement
        'C40' => ['famille' => 'paiement', 'moment' => 'generation', 'donnee' => 'statut du dossier',
            'condition' => 'dossier pas en « valide directeur »', 'resultat' => 'aucune generation',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerationFichiersComptables.php',
            'preuve' => 'DossierStatut::VALIDE_DIRECTEUR !== $dossier->getStatut()'],
        'C41' => ['famille' => 'paiement', 'moment' => 'generation', 'donnee' => 'etablissement du dossier',
            'condition' => 'etablissement inconnu du referentiel', 'resultat' => 'generation en erreur, tracee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerationFichiersComptables.php',
            'preuve' => 'introuvable'],
        'C42' => ['famille' => 'bancaire', 'moment' => 'generation', 'donnee' => 'IBAN du debiteur',
            // ATTENTION : la verification porte sur la STRUCTURE seule -- deux
            // lettres, deux chiffres, onze a trente alphanumeriques. La cle de
            // controle MOD 97 n'est PAS verifiee, a aucun moment du parcours.
            // Le registre dit ce que le code fait, pas ce qu'on aimerait.
            'condition' => 'IBAN hors structure ; la cle MOD 97 reste non verifiee',
            'resultat' => 'generation refusee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerateurSepa.php',
            'preuve' => 'exigerIban($ibanEtab'],
        'C43' => ['famille' => 'bancaire', 'moment' => 'generation', 'donnee' => 'BIC du debiteur',
            'condition' => 'BIC hors syntaxe ISO 9362', 'resultat' => 'generation refusee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerateurSepa.php',
            'preuve' => 'exigerBic($bicEtab'],
        'C44' => ['famille' => 'bancaire', 'moment' => 'generation', 'donnee' => 'IBAN du beneficiaire',
            // Meme limite que C42 : la structure, pas la cle MOD 97.
            'condition' => 'IBAN hors structure ; la cle MOD 97 reste non verifiee',
            'resultat' => 'generation refusee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerateurSepa.php',
            'preuve' => 'exigerIban($ibanClient'],
        'C45' => ['famille' => 'bancaire', 'moment' => 'generation', 'donnee' => 'BIC du beneficiaire',
            'condition' => 'BIC hors syntaxe ISO 9362', 'resultat' => 'generation refusee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerateurSepa.php',
            'preuve' => 'exigerBic($bicClient'],
        'C46' => ['famille' => 'montant', 'moment' => 'generation', 'donnee' => 'montant du virement',
            'condition' => 'montant nul ou negatif', 'resultat' => 'generation refusee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerateurSepa.php',
            'preuve' => 'doit etre strictement positif'],
        'C47' => ['famille' => 'paiement', 'moment' => 'generation', 'donnee' => 'noms des parties',
            'condition' => 'nom du debiteur ou du beneficiaire absent', 'resultat' => 'generation refusee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerateurSepa.php',
            'preuve' => 'Nom du debiteur et du beneficiaire requis'],
        'C48' => ['famille' => 'paiement', 'moment' => 'generation', 'donnee' => 'issue de la generation',
            'condition' => 'une exception a interrompu la generation', 'resultat' => 'statut « erreur de generation », trace',
            'nature' => 'informatif', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Service/GenerationFichiersComptables.php',
            'preuve' => "'echec_generation'"],
        'C49' => ['famille' => 'paiement', 'moment' => 'preparation du lot', 'donnee' => 'statut des dossiers coches',
            'condition' => 'dossier pas en « en cours de paiement »', 'resultat' => 'ecarte du lot',
            'nature' => 'bloquant', 'role' => 'manager',
            'fichier' => 'src/Remboursement/Controller/VirementController.php',
            'preuve' => 'DossierStatut::GENERATION_EN_COURS === $d->getStatut()'],
        'C50' => ['famille' => 'integrite de la requete', 'moment' => 'preparation du lot', 'donnee' => 'jeton du lot',
            'condition' => 'jeton absent ou faux', 'resultat' => 'action refusee',
            'nature' => 'bloquant', 'role' => 'manager',
            'fichier' => 'src/Remboursement/Controller/VirementController.php',
            'preuve' => "isCsrfTokenValid('virements_zip'"],
        'C51' => ['famille' => 'habilitation', 'moment' => 'preparation du lot', 'donnee' => 'role de l\'auteur',
            'condition' => 'role manager absent', 'resultat' => 'acces refuse',
            'nature' => 'bloquant', 'role' => 'manager',
            'fichier' => 'src/Remboursement/Controller/VirementController.php',
            'preuve' => "IsGranted('ROLE_MANAGER')"],
        'C52' => ['famille' => 'integrite de la requete', 'moment' => 'confirmation du paiement', 'donnee' => 'jeton de confirmation',
            'condition' => 'jeton absent ou faux', 'resultat' => 'action refusee',
            'nature' => 'bloquant', 'role' => 'direction',
            'fichier' => 'src/Remboursement/Controller/PaiementJournalController.php',
            'preuve' => "isCsrfTokenValid('paiements_confirmer'"],
        'C53' => ['famille' => 'paiement', 'moment' => 'confirmation du paiement', 'donnee' => 'statut du dossier',
            'condition' => 'dossier pas en « en cours de paiement »', 'resultat' => 'confirmation ignoree',
            'nature' => 'bloquant', 'role' => 'direction',
            'fichier' => 'src/Remboursement/Controller/PaiementJournalController.php',
            'preuve' => 'DossierStatut::GENERATION_EN_COURS !== $dossier->getStatut()'],
        'C54' => ['famille' => 'habilitation', 'moment' => 'confirmation du paiement', 'donnee' => 'role de l\'auteur',
            'condition' => 'ni directeur, ni credit manager designe', 'resultat' => 'acces refuse',
            'nature' => 'bloquant', 'role' => 'direction',
            'fichier' => 'src/Remboursement/Controller/PaiementJournalController.php',
            'preuve' => "is_granted('ROLE_DIRECTEUR')"],
    ];

    /**
     * Les RENFORCEMENTS de la copie de demonstration.
     *
     * Ils sont comptes a part, et ils ne rejoignent jamais le registre herite :
     * les presenter ensemble laisserait croire que le module historique les
     * portait.
     *
     * @var array<string, array{famille: string, moment: string, donnee: string, condition: string, resultat: string, nature: string, role: string, fichier: string, preuve: string}>
     */
    /** Les renforcements de la copie de demonstration, jamais melanges aux 54. */
    public const RENFORCEMENTS = [
        'R01' => ['famille' => 'contrat', 'moment' => 'avant generation', 'donnee' => 'montant retenu vs engagement de reprise',
            'condition' => 'ecart superieur a la tolerance de 3 €', 'resultat' => 'paiement bloque, tentative tracee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Demo/MiddlewareGardeSurpaiement.php',
            'preuve' => 'PAIEMENT BLOQUÉ'],
        'R02' => ['famille' => 'paiement', 'moment' => 'avant generation', 'donnee' => 'empreinte du paiement',
            'condition' => 'un paiement existe deja pour cette cle fonctionnelle',
            'resultat' => 'aucune nouvelle generation, fichier existant reutilise, rejeu trace',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Demo/MiddlewareAntiRejeu.php',
            'preuve' => 'tracerRejeu'],
        'R03' => ['famille' => 'piste d\'audit', 'moment' => 'chaque transition', 'donnee' => 'contenu de la transition',
            'condition' => 'un maillon ne correspond plus a sa transition', 'resultat' => 'alteration nommee par son rang',
            'nature' => 'informatif', 'role' => 'auditeur',
            'fichier' => 'src/Remboursement/Demo/ChaineIntegrite.php',
            'preuve' => 'hash_precedent'],
        // R04 : trouve en construisant BLIND_O8_2. Le module verifie la
        // structure de l'IBAN, jamais sa cle de controle. Ce renforcement
        // ferme le trou a DEUX endroits, et il ne rejoint pas les 54 : il
        // n'a jamais existe dans le module historique.
        'R04' => ['famille' => 'bancaire', 'moment' => 'entree et avant generation',
            'donnee' => 'cle de controle des IBAN beneficiaire et debiteur',
            'condition' => 'structure recevable mais MOD 97 different de 1',
            'resultat' => 'progression refusee a l'."'".'entree ; aucun fichier de paiement devant la caisse, tentative tracee',
            'nature' => 'bloquant', 'role' => 'systeme',
            'fichier' => 'src/Remboursement/Demo/ControleIbanMod97.php',
            'preuve' => 'cleJuste'],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $racineProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('detail', null, InputOption::VALUE_NONE,
            'Afficher chaque controle avec sa condition et sa preuve.');
    }

    /**
     * Un compteur par cle, rendu en lignes de tableau.
     *
     * @param array<string, int> $compteur
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function enLignes(array $compteur): array
    {
        $lignes = [];
        foreach ($compteur as $cle => $nombre) {
            $lignes[] = [(string) $cle, (string) $nombre];
        }

        return $lignes;
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $io = new SymfonyStyle($entree, $sortie);
        $io->title('Registre des controles du module Remboursement');
        $io->text('Recompte SUR LE CODE : chaque ligne porte un fichier et un fragment');
        $io->text('attendu. Un fragment disparu fait echouer cette commande.');

        $manquants = [];
        $verifies = 0;
        foreach ([self::REGISTRE, self::RENFORCEMENTS] as $table) {
            foreach ($table as $code => $c) {
                $chemin = $this->racineProjet.'/'.$c['fichier'];
                if (!is_file($chemin)) {
                    $manquants[] = $code.' : fichier absent — '.$c['fichier'];
                    continue;
                }
                $contenu = (string) file_get_contents($chemin);
                if (!str_contains($contenu, $c['preuve'])) {
                    $manquants[] = $code.' : fragment absent de '.$c['fichier'].' — « '.$c['preuve'].' »';
                    continue;
                }
                ++$verifies;
            }
        }

        if (true === $entree->getOption('detail')) {
            $io->section('Le registre herite, controle par controle');
            $io->table(
                ['Code', 'Famille', 'Moment', 'Donnee', 'Condition', 'Resultat', 'Nature', 'Role'],
                array_map(static fn (string $code): array => [
                    $code,
                    self::REGISTRE[$code]['famille'],
                    self::REGISTRE[$code]['moment'],
                    self::REGISTRE[$code]['donnee'],
                    self::REGISTRE[$code]['condition'],
                    self::REGISTRE[$code]['resultat'],
                    self::REGISTRE[$code]['nature'],
                    self::REGISTRE[$code]['role'],
                ], array_keys(self::REGISTRE)));

            $io->section('Les renforcements de la copie, comptes a part');
            $io->table(
                ['Code', 'Famille', 'Moment', 'Donnee', 'Condition', 'Resultat', 'Nature', 'Role'],
                array_map(static fn (string $code): array => [
                    $code,
                    self::RENFORCEMENTS[$code]['famille'],
                    self::RENFORCEMENTS[$code]['moment'],
                    self::RENFORCEMENTS[$code]['donnee'],
                    self::RENFORCEMENTS[$code]['condition'],
                    self::RENFORCEMENTS[$code]['resultat'],
                    self::RENFORCEMENTS[$code]['nature'],
                    self::RENFORCEMENTS[$code]['role'],
                ], array_keys(self::RENFORCEMENTS)));
        }

        // -------------------------------------------------- les comptes
        $io->section('Ce que le compte donne');
        $parNature = [];
        $parFamille = [];
        $parMoment = [];
        foreach (self::REGISTRE as $c) {
            $parNature[$c['nature']] = ($parNature[$c['nature']] ?? 0) + 1;
            $parFamille[$c['famille']] = ($parFamille[$c['famille']] ?? 0) + 1;
            $parMoment[$c['moment']] = ($parMoment[$c['moment']] ?? 0) + 1;
        }
        ksort($parNature);
        ksort($parFamille);

        $io->definitionList(
            ['Controles herites du module reel' => (string) \count(self::REGISTRE)],
            ['Renforcements ajoutes dans la copie' => (string) \count(self::RENFORCEMENTS)],
            ['Fragments de code verifies' => (string) $verifies],
            ['Fragments introuvables' => (string) \count($manquants)],
        );

        $io->table(['Nature', 'Nombre'], self::enLignes($parNature));
        $io->text('Le registre contient des controles bloquants, des alertes et des controles');
        $io->text('informatifs. On n\'ecrit donc jamais « N controles bloquants ».');

        $io->table(['Famille', 'Nombre'], self::enLignes($parFamille));
        $io->table(['Moment du parcours', 'Nombre'], self::enLignes($parMoment));

        if ([] !== $manquants) {
            $io->section('Ce qui ne se verifie plus dans le code');
            foreach ($manquants as $m) {
                $io->text('  • '.$m);
            }
            $io->error('Le registre ne correspond plus au code : le code prime, corrigez le registre.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d controles herites et %d renforcements, tous retrouves dans le code.',
            \count(self::REGISTRE), \count(self::RENFORCEMENTS)));

        return Command::SUCCESS;
    }
}
