<?php

declare(strict_types=1);

namespace App\Tests\Livraison\Service;

use App\Livraison\Enum\TypePiece;
use App\Livraison\Service\ValidationPieces;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regles d'acceptation des pieces d'une declaration de livraison.
 *
 * Ces regles decident si le dossier d'un vehicule part ou non chez le loueur : un
 * refus a tort coute une relance, un refus silencieux coute le paiement. D'ou des
 * tests sur chaque cause de refus, et sur les formats que les concessions envoient
 * reellement.
 */
final class ValidationPiecesTest extends TestCase
{
    private ValidationPieces $validation;

    protected function setUp(): void
    {
        $this->validation = new ValidationPieces();
    }

    public function testUnPdfValideEstAccepte(): void
    {
        self::assertNull($this->validation->refus(
            TypePiece::PV_LIVRAISON,
            valide: true,
            taille: 250_000,
            mime: 'application/pdf',
        ));
    }

    /**
     * Les PV de livraison arrivent tres souvent en photo prise au telephone : les
     * formats mobiles doivent passer, HEIC compris (defaut sur iPhone).
     */
    #[DataProvider('formatsAcceptes')]
    public function testLesFormatsDesConcessionsSontAcceptes(string $mime): void
    {
        self::assertNull($this->validation->refus(
            TypePiece::PV_LIVRAISON,
            valide: true,
            taille: 1_000_000,
            mime: $mime,
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function formatsAcceptes(): iterable
    {
        yield 'PDF' => ['application/pdf'];
        yield 'photo JPEG' => ['image/jpeg'];
        yield 'capture PNG' => ['image/png'];
        yield 'photo iPhone HEIC' => ['image/heic'];
        yield 'photo iPhone HEIF' => ['image/heif'];
        yield 'scan TIFF' => ['image/tiff'];
        yield 'WebP' => ['image/webp'];
    }

    public function testUnFormatBureautiqueEstRefuse(): void
    {
        $refus = $this->validation->refus(
            TypePiece::BON_COMMANDE,
            valide: true,
            taille: 50_000,
            mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        self::assertNotNull($refus);
        // Le message doit nommer la piece concernee : la secretaire en depose trois.
        self::assertStringContainsString('Bon de commande', $refus);
        self::assertStringContainsString('PDF ou une image', $refus);
    }

    public function testUnMimeIndetermineEstRefuseSansMessageObscur(): void
    {
        $refus = $this->validation->refus(TypePiece::CPI, valide: true, taille: 10_000, mime: null);

        self::assertNotNull($refus);
        self::assertStringContainsString('inconnu', $refus);
    }

    public function testUnEnvoiInterrompuEstRefuse(): void
    {
        $refus = $this->validation->refus(
            TypePiece::PV_LIVRAISON,
            valide: false,
            taille: 0,
            mime: null,
        );

        self::assertNotNull($refus);
        self::assertStringContainsString('PV de livraison', $refus);
    }

    public function testUnFichierVideEstRefuse(): void
    {
        $refus = $this->validation->refus(
            TypePiece::CPI,
            valide: true,
            taille: 0,
            mime: 'application/pdf',
        );

        self::assertNotNull($refus);
        self::assertStringContainsString('vide', $refus);
    }

    public function testLaLimiteExacteEstAcceptee(): void
    {
        self::assertNull($this->validation->refus(
            TypePiece::PV_LIVRAISON,
            valide: true,
            taille: ValidationPieces::TAILLE_MAX,
            mime: 'application/pdf',
        ));
    }

    public function testUnOctetAuDelaDeLaLimiteEstRefuse(): void
    {
        $refus = $this->validation->refus(
            TypePiece::PV_LIVRAISON,
            valide: true,
            taille: ValidationPieces::TAILLE_MAX + 1,
            mime: 'application/pdf',
        );

        self::assertNotNull($refus);
        // Le message doit donner le poids reel ET la limite : « trop gros » sans
        // chiffre laisse la secretaire sans solution.
        self::assertStringContainsString('100 Mo', $refus);
    }

    #[DataProvider('taillesLisibles')]
    public function testLaTailleEstRenduePourUnHumain(int $octets, string $attendu): void
    {
        self::assertSame($attendu, ValidationPieces::lisible($octets));
    }

    /** @return iterable<string, array{int, string}> */
    public static function taillesLisibles(): iterable
    {
        yield 'octets' => [512, '512 octets'];
        yield 'kilooctets' => [2048, '2 Ko'];
        yield 'megaoctets entiers' => [5 * 1024 * 1024, '5 Mo'];
        yield 'megaoctets avec decimale' => [(int) (2.5 * 1024 * 1024), '2,5 Mo'];
        yield 'la limite' => [ValidationPieces::TAILLE_MAX, '100 Mo'];
    }
}
