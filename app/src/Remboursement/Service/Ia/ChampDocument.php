<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

/**
 * Un champ d'un document synthetique : sa source CANONIQUE.
 *
 * C'est la piece maitresse de l'invariant « visible = structure ». Un champ
 * porte trois choses issues d'une seule valeur :
 *
 *   - le LIBELLE, tel que le document l'imprime ;
 *   - la valeur VISIBLE, telle que le lecteur la voit ;
 *   - la valeur STRUCTUREE, telle que le bloc machine la porte.
 *
 * Le document et le bloc machine sont donc rendus depuis le MEME objet. Il
 * devient impossible d'afficher « lu sur la piece : X » quand la piece porte Y,
 * non pas parce qu'on aurait pris soin de les synchroniser, mais parce qu'ils
 * n'ont jamais existe separement.
 */
final readonly class ChampDocument
{
    /**
     * @param string                 $cle       le nom attendu par le gabarit d'extraction
     * @param string                 $libelle   ce que le document imprime devant la valeur
     * @param string                 $visible   ce que le lecteur voit
     * @param string|float|bool|null $structure ce que le bloc machine porte
     */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $visible,
        public string|float|bool|null $structure,
    ) {
    }

    /** Un champ texte : la valeur visible et la valeur structuree coincident. */
    public static function texte(string $cle, string $libelle, ?string $valeur): self
    {
        return new self($cle, $libelle, null === $valeur || '' === $valeur ? '—' : $valeur, $valeur);
    }

    /** Un montant : visible en euros a la francaise, structure en nombre. */
    public static function montant(string $cle, string $libelle, ?float $valeur): self
    {
        return new self($cle, $libelle,
            null === $valeur ? '—' : number_format($valeur, 2, ',', ' ').' €',
            $valeur);
    }

    /**
     * Un fait : visible en clair, structure en booleen.
     *
     * Le libelle dit ce que « oui » signifie, pour qu'un lecteur comprenne la
     * ligne sans connaitre le nom du champ.
     */
    public static function fait(string $cle, string $libelle, bool $valeur, string $oui, string $non): self
    {
        return new self($cle, $libelle, $valeur ? $oui : $non, $valeur);
    }
}
