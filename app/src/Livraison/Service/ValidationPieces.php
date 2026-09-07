<?php

declare(strict_types=1);

namespace App\Livraison\Service;

use App\Livraison\Enum\TypePiece;

/**
 * Regles d'acceptation des pieces jointes a une declaration de livraison.
 *
 * Volontairement sans dependance a UploadedFile ni au systeme de fichiers : le
 * service ne recoit que des valeurs, ce qui le rend testable sans base ni fichier
 * temporaire, et evite d'avoir ces regles dans un controleur.
 *
 * Les limites reprennent celles du formulaire Google remplace : PDF ou image,
 * 100 Mo par piece. Ne pas les durcir sans le dire aux concessions — certaines
 * scannent en TIFF non compresse, et un refus silencieux couterait un dossier.
 */
final class ValidationPieces
{
    /** Taille maximale par piece, alignee sur le formulaire Google remplace. */
    public const TAILLE_MAX = 100 * 1024 * 1024;

    /**
     * Types acceptes. HEIC et HEIF sont indispensables : c'est le format par defaut
     * des photos prises sur iPhone, et les PV de livraison arrivent souvent en photo.
     */
    public const MIMES_ACCEPTES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/heif',
        'image/tiff',
        'image/webp',
    ];

    /**
     * Message de refus d'une piece, ou null si elle est acceptable.
     *
     * @param bool        $valide l'envoi est-il arrive intact (UploadedFile::isValid)
     * @param int|null    $taille taille en octets, null si inconnue
     * @param string|null $mime   type MIME detecte, null si indetermine
     */
    public function refus(TypePiece $type, bool $valide, ?int $taille, ?string $mime): ?string
    {
        if (!$valide) {
            return sprintf(
                'La pièce « %s » n\'a pas pu être lue. L\'envoi a peut-être été interrompu, ou le fichier dépasse la limite du serveur.',
                $type->libelle(),
            );
        }

        if (null === $taille || 0 === $taille) {
            return sprintf('La pièce « %s » est vide.', $type->libelle());
        }

        if ($taille > self::TAILLE_MAX) {
            return sprintf(
                'La pièce « %s » pèse %s, la limite est de %s.',
                $type->libelle(),
                self::lisible($taille),
                self::lisible(self::TAILLE_MAX),
            );
        }

        if (null === $mime || !\in_array($mime, self::MIMES_ACCEPTES, true)) {
            return sprintf(
                'La pièce « %s » doit être un PDF ou une image. Format reçu : %s.',
                $type->libelle(),
                null === $mime || '' === $mime ? 'inconnu' : $mime,
            );
        }

        return null;
    }

    /** Taille en octets rendue lisible pour un message d'erreur. */
    public static function lisible(int $octets): string
    {
        if ($octets >= 1024 * 1024) {
            return rtrim(rtrim(number_format($octets / (1024 * 1024), 1, ',', ' '), '0'), ',').' Mo';
        }
        if ($octets >= 1024) {
            return number_format($octets / 1024, 0, ',', ' ').' Ko';
        }

        return $octets.' octets';
    }
}
