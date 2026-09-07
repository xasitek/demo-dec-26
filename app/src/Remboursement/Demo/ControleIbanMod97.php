<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Service\IbanFictif;

/**
 * R04 — RENFORCEMENT DE LA COPIE DE DEMONSTRATION : la cle de controle de l'IBAN.
 *
 * CE QUE LE MODULE HISTORIQUE FAIT, ET OU IL S'ARRETE. Le circuit verifie la
 * STRUCTURE de l'IBAN — deux lettres, deux chiffres, onze a trente caracteres
 * alphanumeriques — a la generation du virement (C42 pour le debiteur, C44 pour
 * le beneficiaire). Le formulaire de depot, lui, exige seulement un IBAN non
 * vide. NULLE PART la cle de controle MOD 97 n'est verifiee.
 *
 * CE QUE CELA PRODUIT. Un IBAN structurellement recevable dont la cle est
 * fausse — un chiffre transpose, une frappe decalee — traverse tout le circuit
 * et entre dans le fichier de paiement. La mesure BLIND_O8_2 l'a etabli sur
 * vingt cas sur vingt (classe CAS-18), avant ce renforcement.
 *
 * CE QUE R04 AJOUTE. La verification arithmetique, a deux endroits :
 *
 *   1. A L'ENTREE, par `EcouteurR04Entree` : un IBAN dont la cle est fausse ne
 *      fait pas progresser le dossier, et la personne lit pourquoi.
 *   2. DEVANT LA CAISSE, par `MiddlewareGardeIbanMod97` : dernier ressort. Meme
 *      si un dossier ancien ou une voie technique a franchi le premier
 *      controle, aucun fichier de paiement ne peut sortir avec un IBAN dont le
 *      MOD 97 ne rend pas 1.
 *
 * R04 N'A JAMAIS EXISTE DANS LE MODULE HISTORIQUE. Il ne rejoint pas les 54
 * controles herites : il se compte a part, avec les autres renforcements de la
 * copie, et se presente toujours comme tel.
 *
 * UNE SEULE IMPLEMENTATION DE LA REGLE. L'arithmetique MOD 97 vit dans
 * `IbanFictif::valide()`, deja utilisee pour fabriquer les IBAN synthetiques et
 * pour les controler. Cette classe ne la recopie pas : elle l'appelle. Tout ce
 * qui doit verifier une cle d'IBAN dans la copie passe par ici.
 */
final readonly class ControleIbanMod97
{
    /** Le code du renforcement, tel qu'il apparait dans le registre et les traces. */
    public const CODE = 'R04';

    /** Ce que R04 repond, mot pour mot, a l'ecran comme dans les traces. */
    public const MESSAGE = 'IBAN invalide — clé de contrôle incorrecte';

    /**
     * La structure que le module herite exige, recopiee de
     * GenerateurSepa::exigerIban(). Elle sert a distinguer QUI parle : hors
     * structure, c'est le controle herite ; structure bonne et cle fausse,
     * c'est R04.
     */
    public const STRUCTURE = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/';

    /**
     * L'IBAN, debarrasse de ce qui n'est pas signifiant.
     *
     * Une secretaire ecrit son IBAN par groupes de quatre, en minuscules
     * parfois. Ce n'est pas une faute : c'est la lecture humaine d'un numero.
     * On normalise donc avant de juger, jamais l'inverse.
     */
    public static function normaliser(string $iban): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    /** La structure est-elle recevable au sens du module herite ? */
    public static function structureRecevable(string $iban): bool
    {
        return 1 === preg_match(self::STRUCTURE, self::normaliser($iban));
    }

    /** La cle de controle est-elle juste ? C'est la question de R04. */
    public static function cleJuste(string $iban): bool
    {
        return IbanFictif::valide(self::normaliser($iban));
    }

    /**
     * Ce que R04 reproche a cet IBAN, ou rien.
     *
     * Un IBAN vide n'est pas l'affaire de R04 : le module herite l'exige deja
     * non vide, et doubler ce reproche brouillerait qui controle quoi. Un IBAN
     * hors structure ne l'est pas davantage : C42 et C44 le refusent. R04 ne
     * parle que de la cle, et seulement d'elle.
     */
    public static function reproche(?string $iban): ?string
    {
        $normalise = self::normaliser((string) $iban);
        if ('' === $normalise) {
            return null;
        }
        if (1 !== preg_match(self::STRUCTURE, $normalise)) {
            return null;
        }

        return IbanFictif::valide($normalise) ? null : self::MESSAGE;
    }
}
