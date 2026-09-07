<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Recouvrement\Service\HtmlPdfConverter;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Shared\Repository\EtablissementRepository;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Attestation PDF de validation de remboursement, destinee au CLIENT (la secretaire
 * peut la transmettre comme preuve). Rendu depuis un gabarit Twig (charte de la suite, style
 * officiel) converti en PDF via dompdf. Utilise les valeurs RETENUES (valide_*).
 */
final class AttestationRemboursement
{
    public function __construct(
        private readonly Environment $twig,
        private readonly HtmlPdfConverter $pdf,
        private readonly EtablissementRepository $etablissements,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function pdf(Dossier $dossier): string
    {
        $rachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();
        $code = trim((string) $dossier->getEtablissementCode());
        $etab = '' !== $code ? $this->etablissements->findOneBy(['codeEtab' => $code]) : null;

        $html = $this->twig->render('pdf/attestation_remboursement.html.twig', [
            'reference' => $dossier->getReference(),
            'client' => trim((string) ($dossier->getValideNom() ?: $dossier->getControleNom() ?: $dossier->getNomClient())) ?: '—',
            'montant' => number_format((float) ($dossier->getValideMontant() ?: $dossier->getControleMontant() ?: $dossier->getMontant()), 2, ',', ' '),
            'motif' => $dossier->getMotif()->libelle(),
            'cleLabel' => $rachat ? 'Immatriculation' : null,
            'cle' => $rachat ? trim((string) ($dossier->getValideImmatriculation() ?: $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation())) : '',
            'etabSociete' => $etab?->getSociete() ?: 'GROUPE SYNTHAUTO',
            'etabLibelle' => $etab?->getLibelle() ?: '',
            'date' => (new DateTimeImmutable())->format('d/m/Y'),
            'logo' => $this->logoDataUri(),
        ]);

        return $this->pdf->enPdf($html);
    }

    /** Logo SYNTHAUTO embarque en data-URI (dompdf n'accede pas aux ressources distantes). */
    private function logoDataUri(): ?string
    {
        $chemin = $this->projectDir.'/assets/images/logo-fc-automobile.jpg';
        if (!is_file($chemin)) {
            return null;
        }
        $data = file_get_contents($chemin);

        return false !== $data ? 'data:image/jpeg;base64,'.base64_encode($data) : null;
    }
}
