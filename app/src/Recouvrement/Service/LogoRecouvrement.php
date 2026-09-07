<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fournit le logo SYNTHAUTO du bandeau des e-mails de relance, sous les deux formes
 * utiles :
 *   - chemin() : fichier disque, a embarquer en INLINE (CID) dans l'e-mail
 *     (Gmail affiche les images CID mais ignore les data-URI) ;
 *   - dataUri() : base64 data-URI, pour le rendu de la page de garde PDF (dompdf)
 *     et l'apercu, qui ne savent pas resoudre un cid:.
 *
 * Reference CID commune (le template affiche src="cid:{{ ... }}").
 */
final class LogoRecouvrement
{
    /** Nom (content-id) sous lequel le logo est embarque dans l'e-mail. */
    public const CID = 'logosynth';

    private ?string $dataUri = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function chemin(): string
    {
        // Logo blanc : contient deja "GROUPE SYNTHAUTO", pense pour un fond navy
        // (bandeau e-mail + en-tete du releve PDF).
        return $this->projectDir.'/assets/images/logo-fc-automobile-white.png';
    }

    /**
     * Src pour l'e-mail : reference CID (l'image est embarquee a part).
     */
    public function srcCid(): string
    {
        return 'cid:'.self::CID;
    }

    /**
     * Src pour le PDF / l'apercu : data-URI base64 (calculee une fois).
     */
    public function dataUri(): string
    {
        if (null !== $this->dataUri) {
            return $this->dataUri;
        }

        $binaire = @file_get_contents($this->chemin());

        return $this->dataUri = false === $binaire ? '' : 'data:image/png;base64,'.base64_encode($binaire);
    }
}
