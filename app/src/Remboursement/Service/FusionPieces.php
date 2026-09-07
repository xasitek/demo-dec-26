<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierPiece;
use App\Remboursement\Repository\DossierPieceRepository;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fusionne toutes les pieces d'un dossier (PDF + images) en UN seul PDF, que le
 * directeur fait defiler avant de valider ("Voir les pieces" de l'e-mail). Les pieces
 * PDF sont importees page a page (FPDI) ; les images sont posees pleine page. Pur PHP,
 * hors re-encodage GD des formats que FPDF ne sait pas lire.
 *
 * Deux regles de robustesse, parce que le destinataire est un directeur d'etablissement
 * SANS compte qui valide un virement depuis sa boite : si la page tombe, il n'a aucun
 * repli et ne peut que valider a l'aveugle.
 *
 *   - une piece illisible ne fait JAMAIS echouer le document entier : elle est sautee ;
 *   - toute piece sautee est listee sur une page finale. Un document ampute en silence
 *     serait pire qu'une erreur : on validerait un virement sans savoir qu'une piece
 *     manque.
 *
 * Les pieces ni PDF ni image (XML SEPA, CSV OD generes apres validation) ne sont ni
 * fusionnees ni signalees : elles n'ont rien a faire dans un document de consultation.
 */
final class FusionPieces
{
    /** Images que FPDF embarque directement (IMAGETYPE_* -> type FPDF). */
    private const TYPES_FPDF = [
        \IMAGETYPE_JPEG => 'jpg',
        \IMAGETYPE_PNG => 'png',
        \IMAGETYPE_GIF => 'gif',
    ];

    /** Cote maximale (px) d'une image re-encodee : borne la memoire et le poids du PDF. */
    private const COTE_MAX = 2200;

    /** Delai maximal (s) accorde a qpdf pour remettre un PDF a plat. */
    private const DELAI_QPDF = 15;

    public function __construct(
        private readonly DossierPieceRepository $pieces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Rend le PDF fusionne (octets binaires). Vide si aucune piece exploitable. */
    public function pdf(Dossier $dossier): string
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $ajout = false;
        /** @var list<string> $temporaires */
        $temporaires = [];
        /** @var list<DossierPiece> $ignorees */
        $ignorees = [];

        try {
            foreach ($this->pieces->pourDossier($dossier) as $piece) {
                if (!ConvertisseurPiece::estPdfOuImage($piece->getMimeType())) {
                    continue; // XML SEPA / CSV OD : hors document de consultation.
                }

                $raison = null;
                try {
                    $rendue = $this->ajouter($pdf, $piece, $temporaires, $raison);
                } catch (Throwable $e) {
                    $rendue = false; // FPDF et FPDI levent (format refuse, PDF chiffre...).
                    $raison = $e->getMessage();
                }

                if ($rendue) {
                    $ajout = true;
                } else {
                    $ignorees[] = $piece;
                    // La page finale dit au directeur QU'IL manque une piece ; le journal
                    // dit POURQUOI, seul moyen de distinguer un PDF chiffre d'un xref
                    // compresse (parseur libre de FPDI) ou d'un format hors GD.
                    $this->logger->warning('Remboursement : piece non fusionnee ({raison})', [
                        'raison' => $raison ?? 'cause inconnue',
                        'dossier' => $dossier->getReference(),
                        'piece' => $piece->getId(),
                        'fichier' => $piece->getNomFichier(),
                        'mime' => $piece->getMimeType(),
                        'octets' => \strlen($piece->getContenu()),
                    ]);
                }
            }

            if (!$ajout && [] === $ignorees) {
                return '';
            }
            if ([] !== $ignorees) {
                $this->pageIgnorees($pdf, $ignorees);
            }

            return (string) $pdf->Output('S');
        } catch (Throwable) {
            return ''; // Dernier filet : le controleur affiche "aucune piece", jamais un 500.
        } finally {
            foreach ($temporaires as $t) {
                @unlink($t);
            }
        }
    }

    /**
     * Ajoute une piece au document. Rend false si elle n'a pas pu etre rendue (elle
     * sera signalee sur la page finale).
     *
     * @param list<string> $temporaires
     * @param string|null  $raison      renseigne (par reference) avec la cause de l'echec, pour le journal
     */
    private function ajouter(Fpdi $pdf, DossierPiece $piece, array &$temporaires, ?string &$raison): bool
    {
        $octets = $piece->getContenu();
        if ('' === $octets) {
            $raison = 'contenu vide en base';

            return false;
        }

        // FPDI et FPDF exigent un chemin de fichier : la piece (stockee en base) passe
        // par un fichier temporaire le temps de la fusion, supprime a la sortie.
        $chemin = $this->fichierTemporaire($octets, $temporaires);
        if (null === $chemin) {
            $raison = 'fichier temporaire non ecrit';

            return false;
        }

        if (str_contains($piece->getMimeType(), 'pdf')) {
            return $this->ajouterPdf($pdf, $chemin, $temporaires, $raison);
        }

        if (!$this->ajouterImage($pdf, $chemin, $temporaires)) {
            $raison = 'image illisible par GD (HEIC, fichier corrompu...)';

            return false;
        }

        return true;
    }

    /** @param list<string> $temporaires */
    private function fichierTemporaire(string $octets, array &$temporaires): ?string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'remb_piece_');
        if (false === $chemin) {
            return null;
        }
        $temporaires[] = $chemin;

        return false === file_put_contents($chemin, $octets) ? null : $chemin;
    }

    /** @param list<string> $temporaires */
    private function ajouterPdf(Fpdi $pdf, string $chemin, array &$temporaires, ?string &$raison): bool
    {
        try {
            $nb = $pdf->setSourceFile($chemin);
        } catch (Throwable $e) {
            // Les deux refus courants d'un RIB de banque : document chiffre (meme sans mot
            // de passe a l'ouverture) et table xref compressee (PDF 1.5+), que le parseur
            // libre de FPDI ne sait pas lire. Une passe qpdf remet le document a plat ;
            // si elle n'aboutit pas, on garde le message d'origine pour le journal.
            $remis = $this->reparer($chemin, $temporaires);
            if (null === $remis) {
                $raison = $e->getMessage();

                return false;
            }

            try {
                $nb = $pdf->setSourceFile($remis);
            } catch (Throwable $apres) {
                $raison = sprintf('%s (apres reparation qpdf : %s)', $e->getMessage(), $apres->getMessage());

                return false;
            }
        }

        $posees = 0;
        for ($i = 1; $i <= $nb; ++$i) {
            try {
                $tpl = $pdf->importPage($i);
                $taille = $pdf->getTemplateSize($tpl);
                if (!\is_array($taille)) {
                    continue;
                }
                $pdf->AddPage($taille['orientation'], [$taille['width'], $taille['height']]);
                $pdf->useTemplate($tpl);
                ++$posees;
            } catch (Throwable $e) {
                // Page non importable (filtre exotique) : on passe a la suivante plutot
                // que de perdre tout le document.
                $raison ??= $e->getMessage();
            }
        }

        if (0 === $posees) {
            $raison ??= sprintf('aucune des %d pages n\'a pu etre importee', $nb);
        }

        return $posees > 0;
    }

    /**
     * Derniere chance pour un PDF que FPDI refuse : le remettre a plat avec qpdf.
     *
     * Les deux cas courants viennent des portails bancaires — document CHIFFRE (souvent
     * sans mot de passe a l'ouverture, seules les permissions sont posees) et table xref
     * COMPRESSEE des PDF 1.5+. Le parseur libre embarque dans FPDI ne sait lire ni l'un
     * ni l'autre ; qpdf, si : `--decrypt` leve le chiffrement, et forcer la version 1.4
     * (qui ne connait pas les flux d'objets) reecrit une table xref classique.
     *
     * Rend le chemin du PDF remis a plat, ou null si qpdf est absent (poste de dev) ou
     * n'y arrive pas. Aucune sortie shell : arguments passes en tableau, delai borne.
     *
     * @param list<string> $temporaires
     */
    private function reparer(string $chemin, array &$temporaires): ?string
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if (null === $qpdf) {
            return null;
        }

        $cible = tempnam(sys_get_temp_dir(), 'remb_qpdf_');
        if (false === $cible) {
            return null;
        }
        $temporaires[] = $cible;

        $process = new Process([
            $qpdf, '--decrypt', '--object-streams=disable', '--force-version=1.4',
            $chemin, $cible,
        ]);
        $process->setTimeout(self::DELAI_QPDF);

        try {
            $process->run();
        } catch (Throwable $e) {
            $this->logger->warning('Remboursement : qpdf n\'a pas pu s\'executer ({message})', ['message' => $e->getMessage()]);

            return null;
        }

        // 0 = propre, 3 = avertissements mais fichier ecrit (frequent sur les PDF de
        // banque, mal formes sans etre illisibles). Au-dela, rien d'exploitable.
        if (!\in_array($process->getExitCode(), [0, 3], true) || 0 === filesize($cible)) {
            return null;
        }

        return $cible;
    }

    /** @param list<string> $temporaires */
    private function ajouterImage(Fpdi $pdf, string $chemin, array &$temporaires): bool
    {
        $image = $this->resoudre($chemin, $temporaires);
        if (null === $image) {
            return false;
        }
        [$fichier, $type, $largeur, $hauteur] = $image;

        $paysage = $largeur > $hauteur;
        $pdf->AddPage($paysage ? 'L' : 'P', 'A4');

        // Zone utile A4 avec marge de 10 mm, image ajustee en conservant le ratio.
        $pw = $paysage ? 297.0 : 210.0;
        $ph = $paysage ? 210.0 : 297.0;
        $marge = 10.0;
        $ratio = min(($pw - 2 * $marge) / max(1, $largeur), ($ph - 2 * $marge) / max(1, $hauteur));
        $w = $largeur * $ratio;
        $h = $hauteur * $ratio;

        // Le type est passe EXPLICITEMENT : le fichier temporaire n'a pas d'extension et
        // FPDF, sans type, deduit le format de l'extension -- puis leve une exception.
        $pdf->Image($fichier, ($pw - $w) / 2, ($ph - $h) / 2, $w, $h, $type);

        return true;
    }

    /**
     * Rend [chemin, type FPDF, largeur, hauteur] pour une image que FPDF saura
     * embarquer : telle quelle si le format est supporte, re-encodee en JPEG sinon.
     * Null si la piece est hors de portee (HEIC iPhone, fichier corrompu).
     *
     * @param list<string> $temporaires
     *
     * @return array{0: string, 1: string, 2: int, 3: int}|null
     */
    private function resoudre(string $chemin, array &$temporaires): ?array
    {
        $infos = @getimagesize($chemin);
        if (false === $infos) {
            return null;
        }

        $type = self::TYPES_FPDF[$infos[2]] ?? null;
        // FPDF refuse la profondeur 16 bits et l'entrelacement : GD sait les relire.
        if ('png' === $type && !self::pngLisibleParFpdf($chemin)) {
            $type = null;
        }
        if (null !== $type) {
            return [$chemin, $type, (int) $infos[0], (int) $infos[1]];
        }

        return $this->versJpeg($chemin, $temporaires);
    }

    /** PNG lisible par FPDF ? Il refuse la profondeur 16 bits et l'entrelacement. */
    private static function pngLisibleParFpdf(string $chemin): bool
    {
        $entete = (string) @file_get_contents($chemin, false, null, 0, 29);
        if (29 !== \strlen($entete)) {
            return false;
        }

        // En-tete IHDR : profondeur de bits a l'octet 24, entrelacement a l'octet 28.
        return 16 !== \ord($entete[24]) && 0 === \ord($entete[28]);
    }

    /**
     * Re-encode une image en JPEG via GD : formats que FPDF ne lit pas (webp, bmp, tiff)
     * et PNG 16 bits ou entrelaces. Reduite a COTE_MAX pour borner la memoire (limite
     * 256 M en prod) et le poids du PDF, aplatie sur blanc car un JPEG n'a pas d'alpha.
     *
     * @param list<string> $temporaires
     *
     * @return array{0: string, 1: string, 2: int, 3: int}|null
     */
    private function versJpeg(string $chemin, array &$temporaires): ?array
    {
        $octets = @file_get_contents($chemin);
        if (false === $octets) {
            return null;
        }
        $source = @imagecreatefromstring($octets);
        if (false === $source) {
            return null; // HEIC et compagnie : GD ne sait pas les decoder.
        }

        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $facteur = min(1.0, self::COTE_MAX / max(1, $largeur, $hauteur));
        if ($facteur < 1.0) {
            $reduite = imagescale($source, (int) round($largeur * $facteur), (int) round($hauteur * $facteur));
            if (false !== $reduite) {
                $source = $reduite;
                $largeur = imagesx($source);
                $hauteur = imagesy($source);
            }
        }

        $aplatie = imagecreatetruecolor($largeur, $hauteur);
        if (false === $aplatie) {
            return null;
        }
        $blanc = imagecolorallocate($aplatie, 255, 255, 255);
        if (false !== $blanc) {
            imagefilledrectangle($aplatie, 0, 0, $largeur, $hauteur, $blanc);
        }
        imagecopy($aplatie, $source, 0, 0, 0, 0, $largeur, $hauteur);

        $cible = tempnam(sys_get_temp_dir(), 'remb_jpeg_');
        if (false === $cible) {
            return null;
        }
        $temporaires[] = $cible;

        return imagejpeg($aplatie, $cible, 82) ? [$cible, 'jpg', $largeur, $hauteur] : null;
    }

    /**
     * Page finale listant les pieces non affichables, pour que le directeur sache qu'il
     * lui manque quelque chose avant de valider.
     *
     * @param list<DossierPiece> $ignorees
     */
    private function pageIgnorees(Fpdi $pdf, array $ignorees): void
    {
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage('P', 'A4');

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetXY(20, 25);
        $pdf->Cell(0, 9, self::texte(1 === \count($ignorees) ? 'Pièce non affichable' : 'Pièces non affichables'), 0, 1);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetX(20);
        $pdf->MultiCell(170, 6, self::texte(
            1 === \count($ignorees)
                ? "Cette pièce du dossier n'a pas pu être intégrée à ce document. Elle reste consultable dans l'application, sur la fiche du dossier."
                : "Ces pièces du dossier n'ont pas pu être intégrées à ce document. Elles restent consultables dans l'application, sur la fiche du dossier."
        ), 0, 'L');
        $pdf->Ln(4);

        foreach ($ignorees as $piece) {
            $pdf->SetX(20);
            $pdf->MultiCell(170, 6, self::texte(sprintf(
                '- %s (%s)',
                $piece->getNomFichier(),
                self::libelleType($piece->getType()),
            )), 0, 'L');
        }
    }

    /**
     * Le type d'une piece est le nom du champ du formulaire de depot (« carte_grise ») :
     * rendu lisible ici plutot que fige dans une table de libelles, qui divergerait du
     * formulaire des qu'on y ajoute un champ.
     */
    private static function libelleType(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }

    /** Les polices de base de FPDF attendent du Windows-1252, pas de l'UTF-8. */
    private static function texte(string $valeur): string
    {
        return (string) mb_convert_encoding($valeur, 'Windows-1252', 'UTF-8');
    }
}
