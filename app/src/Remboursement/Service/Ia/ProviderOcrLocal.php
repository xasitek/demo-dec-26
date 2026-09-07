<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

use App\Remboursement\Service\Ia\Exception\ReponseNonExploitableException;
use JsonException;

/**
 * LECTURE LOCALE DETERMINISTE du document synthetique.
 *
 * Ce composant n'est ni une OCR, ni une intelligence artificielle : rien n'est
 * reconnu, rien n'est infere, rien n'est execute par un modele. Aucun appel
 * sortant n'a lieu -- l'environnement de demonstration est hors reseau.
 *
 * Ce qu'il fait, exactement. Les documents synthetiques de demonstration
 * embarquent une representation structuree des informations qu'ils affichent.
 * Le fournisseur local LIT cette representation afin de reproduire de maniere
 * deterministe le contrat d'extraction documentaire du module, sans appel a un
 * service externe.
 *
 * La consequence est la propriete qu'on veut pouvoir defendre devant un jury :
 * la valeur presentee comme « lue sur la piece » EST celle que la piece porte.
 * Il n'y a pas de table cachee a cote, pas de valeur soufflee -- le document
 * visible et la representation structuree sont rendus depuis une meme source
 * canonique (voir FabricantPieces et son invariant).
 *
 * Il respecte le meme contrat que le fournisseur reel -- `ProviderOcr` -- de
 * sorte que le reste du module ignore qu'il s'agit de la demonstration : le
 * service d'analyse, l'agregateur, le journal d'extraction et le triptyque de
 * l'ecran comptable fonctionnent sans une ligne de difference.
 *
 * Deux comportements a ne pas confondre, et le module les distingue deja :
 *   - un document ILLISIBLE rend un resultat vide et une extraction en echec
 *     metier : le comptable arbitre ;
 *   - une PANNE du fournisseur leve ProviderIndisponibleException et le dossier
 *     part en « analyse indisponible, controle humain requis ». Jamais un refus.
 */
final class ProviderOcrLocal implements ProviderOcr
{
    /** La balise qui porte le bloc machine du document synthetique. */
    public const BALISE = 'donnees-extraction';

    /** Panne simulee : le document le declare lui-meme. */
    public const MARQUEUR_PANNE = 'PANNE_EXTRACTION';

    /**
     * Le libelle a afficher partout ou un utilisateur lit d'ou vient la valeur.
     *
     * C'est la seule formulation admise a l'ecran et dans la page Methode. On
     * n'ecrit jamais « OCR », jamais « IA », jamais « analyse intelligente ».
     * La colonne technique `provider` du journal d'extraction est limitee a 40
     * caracteres par le module reel : elle recoit l'identifiant `nom()`, et cet
     * identifiant n'est pas destine a etre lu par un utilisateur.
     */
    public const LIBELLE = 'Lecture locale déterministe du document synthétique — environnement de démonstration hors réseau.';

    public function extraire(ContenuPiece $contenu, GabaritExtraction $gabarit): ResultatExtraction
    {
        $t0 = microtime(true);
        $brut = $contenu->contenu;

        // Le bloc machine, en fin de document. Un document sans bloc n'est pas
        // une panne : c'est un document dont on ne lit rien.
        if (1 !== preg_match(
            '/<script type="application\/json" id="'.preg_quote(self::BALISE, '/').'">(.*?)<\/script>/s',
            $brut, $m)) {
            return new ResultatExtraction([], ['lu' => 'aucune représentation structurée dans le document'],
                null, null, (int) ((microtime(true) - $t0) * 1000), $this->nom());
        }

        try {
            /** @var array<string, mixed> $lu */
            $lu = json_decode(trim($m[1]), true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ReponseNonExploitableException('Représentation structurée du document illisible : '.$e->getMessage());
        }

        // La panne technique est declaree par le document lui-meme, pour que le
        // scenario adversarial « analyse indisponible » soit rejouable sans
        // toucher a la configuration.
        if (true === ($lu[self::MARQUEUR_PANNE] ?? false)) {
            throw new Exception\ProviderIndisponibleException('Lecture locale indisponible sur cette pièce (scénario de panne).');
        }

        // On ne rend QUE les champs que le gabarit attend. Un champ hors schema
        // serait ignore par l'agregateur : autant ne pas le fabriquer.
        /** @var array<string, mixed> $proprietes */
        $proprietes = $gabarit->schema['properties'] ?? [];
        $champs = [];
        foreach (array_keys($proprietes) as $cle) {
            if (\array_key_exists($cle, $lu)) {
                $champs[(string) $cle] = $lu[$cle];
            }
        }

        return new ResultatExtraction(
            $champs,
            [
                'lecture' => self::LIBELLE,
                'fournisseur' => $this->nom(),
                'gabarit' => $gabarit->version,
                'lu' => $lu,
            ],
            null,
            null,
            (int) ((microtime(true) - $t0) * 1000),
            'lecture-locale-deterministe',
        );
    }

    /**
     * L'IDENTIFIANT technique, journalise dans la colonne `provider` (40 car.).
     *
     * Ce n'est pas un libelle d'interface : ce qui s'affiche a un utilisateur,
     * c'est self::LIBELLE.
     */
    public function nom(): string
    {
        return 'lecture-locale-deterministe';
    }

    /**
     * Toujours disponible : il n'y a ni cle, ni quota, ni reseau.
     *
     * La panne se declare piece par piece, dans le document, et non par une
     * indisponibilite globale du fournisseur.
     */
    public function estDisponible(): bool
    {
        return true;
    }
}
