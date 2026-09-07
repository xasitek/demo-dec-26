<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Service\FusionPieces;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use setasign\Fpdi\Fpdi;

/**
 * Regression : "Voir les pieces" de l'e-mail directeur tombait en 500 des qu'une piece
 * etait une image. FPDF deduit le format de l'extension du fichier, et le fichier
 * temporaire par lequel passe une piece stockee en base n'en a pas.
 */
final class FusionPiecesTest extends TestCase
{
    public function testUneImageJpegProduitBienUnePage(): void
    {
        $dossier = new Dossier(DossierMotif::TROP_PERCU);
        $pdf = $this->fusion([
            self::piece($dossier, self::image('jpg'), 'image/jpeg', 'rib.jpg'),
        ])->pdf($dossier);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(1, self::pages($pdf));
    }

    public function testJpegPngEtGifSontTousFusionnes(): void
    {
        $dossier = new Dossier(DossierMotif::TROP_PERCU);
        $pdf = $this->fusion([
            self::piece($dossier, self::image('jpg'), 'image/jpeg', 'rib.jpg'),
            self::piece($dossier, self::image('png'), 'image/png', 'carte-grise.png'),
            self::piece($dossier, self::image('gif'), 'image/gif', 'cheque.gif'),
        ])->pdf($dossier);

        self::assertSame(3, self::pages($pdf));
    }

    public function testFormatQueFpdfNeLitPasEstReEncodeEnJpeg(): void
    {
        if (!\function_exists('imagewebp')) {
            self::markTestSkipped('GD sans support WEBP.');
        }

        $dossier = new Dossier(DossierMotif::TROP_PERCU);
        $pdf = $this->fusion([
            self::piece($dossier, self::image('webp'), 'image/webp', 'photo.webp'),
        ])->pdf($dossier);

        self::assertSame(1, self::pages($pdf));
    }

    public function testUnePieceIllisibleNeCassePasLeDocumentEtEstSignalee(): void
    {
        $dossier = new Dossier(DossierMotif::TROP_PERCU);
        $pdf = $this->fusion([
            self::piece($dossier, self::image('jpg'), 'image/jpeg', 'rib.jpg'),
            self::piece($dossier, random_bytes(64), 'image/heic', 'photo.heic'),
        ])->pdf($dossier);

        // La page de l'image, plus la page finale qui signale la piece non affichable :
        // le directeur ne valide jamais un document ampute en silence.
        self::assertSame(2, self::pages($pdf));
    }

    public function testToutesLesPagesDUnPdfSontImportees(): void
    {
        $dossier = new Dossier(DossierMotif::RACHAT_SEC);
        $pdf = $this->fusion([
            self::piece($dossier, self::pdfDeDeuxPages(), 'application/pdf', 'facture.pdf'),
        ])->pdf($dossier);

        self::assertSame(2, self::pages($pdf));
    }

    public function testLesFichiersSepaEtOdSontIgnoresSansEtreSignales(): void
    {
        $dossier = new Dossier(DossierMotif::TROP_PERCU);
        $pdf = $this->fusion([
            self::piece($dossier, '<Document/>', 'application/xml', 'sepa.xml'),
            self::piece($dossier, 'SOCIETE;compte', 'text/csv', 'od.csv'),
        ])->pdf($dossier);

        self::assertSame('', $pdf);
    }

    public function testAucunePieceRendUneChaineVide(): void
    {
        $dossier = new Dossier(DossierMotif::TROP_PERCU);

        self::assertSame('', $this->fusion([])->pdf($dossier));
    }

    /** @param list<DossierPiece> $pieces */
    private function fusion(array $pieces): FusionPieces
    {
        $repository = $this->createStub(DossierPieceRepository::class);
        $repository->method('pourDossier')->willReturn($pieces);

        return new FusionPieces($repository, new NullLogger());
    }

    private static function piece(Dossier $dossier, string $contenu, string $mime, string $nom): DossierPiece
    {
        return new DossierPiece($dossier, 'justificatif', $nom, $contenu, $mime, \strlen($contenu));
    }

    /** Nombre de pages du PDF rendu (chaque page porte un objet /Type /Page). */
    private static function pages(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    private static function image(string $format): string
    {
        $image = imagecreatetruecolor(400, 300);
        if (false === $image) {
            self::fail('GD indisponible.');
        }
        imagefilledrectangle($image, 0, 0, 400, 300, (int) imagecolorallocate($image, 200, 40, 30));

        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image),
            'png' => imagepng($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            default => self::fail('Format de fixture inconnu : '.$format),
        };

        return (string) ob_get_clean();
    }

    private static function pdfDeDeuxPages(): string
    {
        $pdf = new Fpdi();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->AddPage();
        $pdf->Cell(0, 10, 'page 1');
        $pdf->AddPage();
        $pdf->Cell(0, 10, 'page 2');

        return (string) $pdf->Output('S');
    }
}
