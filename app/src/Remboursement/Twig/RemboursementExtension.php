<?php

declare(strict_types=1);

namespace App\Remboursement\Twig;

use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Repository\LettrageRepository;
use App\Remboursement\Service\Ia\NormalisateurControle;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig du module Remboursement.
 *
 * - `remb_nb_correction()` : nombre de dossiers de la secretaire connectee en
 *   « Correction requise » — alimente le badge de sa nav (savoir en un coup d'oeil
 *   combien de dossiers lui reviennent a corriger). 0 si non connectee.
 * - `remb_nb_a_lettrer()` : nombre de paiements non lettres — pastille de l'onglet
 *   Lettrage, affichee sur TOUTES les pages du poste comptable. Memoise pour la
 *   requete : la nav est rendue une fois, mais l'appel reste sans cout si un autre
 *   gabarit le redemande.
 * - `remb_ecart()` : y a-t-il un VRAI ecart entre deux valeurs de controle ? La
 *   comparaison porte sur les formes normalisees (memes regles que le back, cf.
 *   NormalisateurControle), de sorte qu'une simple difference de mise en forme
 *   (GD860PJ vs GD-860-PJ, IBAN espace ou non, casse) ne soit plus signalee.
 * - `remb_diff()` : la meme comparaison, mais qui RENVOIE la valeur avec la seule
 *   portion divergente surlignee. Colorer la valeur entiere n'apprenait rien ; ici
 *   l'oeil tombe sur le caractere qui change.
 *
 * `remb_ecart` et `remb_diff` sont STATIQUES : deux fonctions pures, sans base ni
 * securite derriere elles, appelables (et testables) telles quelles.
 */
final class RemboursementExtension extends AbstractExtension
{
    private ?int $nbALettrer = null;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly LettrageRepository $lettrage,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('remb_nb_correction', $this->nbCorrection(...)),
            new TwigFunction('remb_nb_a_lettrer', $this->nbALettrer(...)),
            new TwigFunction('remb_ecart', self::ecart(...)),
            new TwigFunction('remb_diff', self::diff(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Rend une valeur en ne SURLIGNANT que ce qui la distingue d'une autre. Colorer la
     * valeur entiere obligeait le comptable a comparer caractere par caractere pour
     * trouver le chiffre qui change (AGRIFRPP826 / AGRIFRPP825) ; ici, seul ce chiffre
     * ressort.
     *
     * On coupe par le prefixe et le suffixe communs : sur ces champs (IBAN, BIC,
     * immatriculation, montant, nom) une divergence est presque toujours un caractere
     * remplace, ajoute ou retire d'un bloc. Un vrai diff par plus longue sous-sequence
     * commune couterait plus cher sans mieux se lire.
     *
     * Rien a surligner (valeurs equivalentes une fois normalisees, ou difference qui ne
     * porte que sur des caracteres absents ici) : la valeur sort telle quelle. La sortie
     * est echappee ici, morceau par morceau.
     */
    public static function diff(?string $valeur, ?string $reference, bool $identifiant = false): string
    {
        $texte = (string) $valeur;
        if (!self::ecart($valeur, $reference, $identifiant)) {
            return self::echapper($texte);
        }

        $a = mb_str_split($texte);
        $b = mb_str_split((string) $reference);
        $court = min(\count($a), \count($b));

        $prefixe = 0;
        while ($prefixe < $court && $a[$prefixe] === $b[$prefixe]) {
            ++$prefixe;
        }
        $suffixe = 0;
        while ($suffixe < $court - $prefixe && $a[\count($a) - 1 - $suffixe] === $b[\count($b) - 1 - $suffixe]) {
            ++$suffixe;
        }

        $milieu = implode('', \array_slice($a, $prefixe, \count($a) - $prefixe - $suffixe));
        if ('' === $milieu) {
            return self::echapper($texte); // la difference est un manque : rien a pointer ici.
        }

        return self::echapper(implode('', \array_slice($a, 0, $prefixe)))
            .'<span class="rounded-sm bg-negative/10 px-0.5 font-semibold text-negative">'.self::echapper($milieu).'</span>'
            .self::echapper(implode('', \array_slice($a, \count($a) - $suffixe)));
    }

    private static function echapper(string $valeur): string
    {
        return htmlspecialchars($valeur, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Ecart REEL entre la saisie et la valeur extraite, une fois les deux valeurs
     * normalisees. Une valeur absente d'un cote n'est jamais un ecart (rien a comparer).
     *
     * @param bool $identifiant true pour un identifiant (IBAN, BIC, immatriculation,
     *                          code ICAR) : seuls comptent les caracteres alphanumeriques,
     *                          les tirets et espaces de presentation sont ignores.
     *                          false pour du texte libre : espaces reduits et casse ignoree.
     */
    public static function ecart(?string $saisie, ?string $ia, bool $identifiant = false): bool
    {
        $a = $identifiant ? NormalisateurControle::alphaNum($saisie) : NormalisateurControle::nom($saisie);
        $b = $identifiant ? NormalisateurControle::alphaNum($ia) : NormalisateurControle::nom($ia);

        return '' !== $a && '' !== $b && $a !== $b;
    }

    public function nbCorrection(): int
    {
        $user = $this->security->getUser();

        return null !== $user ? $this->dossiers->compterCorrectionRequise($user->getUserIdentifier()) : 0;
    }

    public function nbALettrer(): int
    {
        return $this->nbALettrer ??= $this->lettrage->nbALettrer();
    }
}
