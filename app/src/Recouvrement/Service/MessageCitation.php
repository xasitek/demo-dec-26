<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

/**
 * Extrait le message REEL d'une reponse email en retirant la citation (l'e-mail
 * d'origine recopie sous la reponse).
 *
 * Quand un client repond a une relance, son webmail ajoute sous son texte tout
 * le message precedent, precede d'une ligne d'attribution ("Le ... a ecrit :")
 * et/ou de lignes citees prefixees par ">". On ne veut afficher (ni categoriser)
 * que le nouveau texte : sinon l'interface montre toute la relance recopiee, et
 * la categorisation se declenche sur des mots-cles presents dans la citation.
 *
 * Fonction PURE (aucune I/O). Heuristique : on garde les lignes jusqu'au premier
 * marqueur de citation. L'attribution Gmail est souvent REPLIEE sur plusieurs
 * lignes par le webmail (le "<email>" passe a la ligne) : on la detecte donc en
 * recollant quelques lignes. Si tout est coupe (reponse sans nouveau texte), on
 * rend l'original nettoye plutot qu'une chaine vide.
 */
final class MessageCitation
{
    public static function principal(?string $corps): ?string
    {
        if (null === $corps) {
            return null;
        }

        $normalise = str_replace(["\r\n", "\r"], "\n", $corps);
        $lignes = explode("\n", $normalise);
        $nb = \count($lignes);

        $coupe = $nb;
        for ($i = 0; $i < $nb; ++$i) {
            $l = trim($lignes[$i]);
            if ('' === $l) {
                continue;
            }
            if (self::estMarqueurCitation($l) || self::debuteAttribution($lignes, $i)) {
                $coupe = $i;
                break;
            }
        }

        $texte = trim(implode("\n", \array_slice($lignes, 0, $coupe)));

        // Repli : la reponse n'etait que de la citation (aucun texte au-dessus) ->
        // on prefere rendre l'original nettoye qu'une chaine vide.
        return '' !== $texte ? $texte : trim($normalise);
    }

    /**
     * Marqueurs de citation "sur une seule ligne" : ligne citee ">", separateurs
     * "----- Original Message -----" / filet Outlook "____", en-tete "De :"/"From:".
     */
    private static function estMarqueurCitation(string $ligne): bool
    {
        if (str_starts_with($ligne, '>')) {
            return true;
        }
        if (1 === preg_match('/^-{2,}\s*(Original Message|Message d\'origine)\s*-{2,}$/i', $ligne)) {
            return true;
        }
        if (1 === preg_match('/^_{5,}$/', $ligne)) {
            return true;
        }

        return 1 === preg_match('/^(De|From)\s*:\s.+/i', $ligne);
    }

    /**
     * La ligne $i debute-t-elle une attribution "Le ... a ecrit :" (FR) ou
     * "On ... wrote:" (EN) ? L'attribution etant souvent repliee par le webmail,
     * on recolle la ligne courante avec les 2 suivantes avant de chercher le
     * suffixe "a ecrit :" / "wrote:". Le prefixe "Le "/"On " requis evite de
     * couper une phrase legitime contenant "a ecrit".
     *
     * @param list<string> $lignes
     */
    private static function debuteAttribution(array $lignes, int $i): bool
    {
        if (1 !== preg_match('/^(Le|On)\s/i', trim($lignes[$i]))) {
            return false;
        }

        $bloc = trim(implode(' ', \array_slice($lignes, $i, 3)));

        return 1 === preg_match('/\ba\s+[ée]crit\s*:/u', $bloc)
            || 1 === preg_match('/\bwrote\s*:/i', $bloc);
    }
}
