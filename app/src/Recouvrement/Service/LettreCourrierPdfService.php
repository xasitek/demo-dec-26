<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Repository\RecouvrementRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Twig\Environment;

/**
 * Genere le PDF des lettres de relance papier (vecteur COURRIER) pour les clients
 * sans email. Deux usages :
 *   - lettre() : une lettre (un courrier) -> un PDF, telechargeable par client ;
 *   - lot()    : tous les courriers en attente -> un seul PDF, une lettre par page,
 *                pour une impression groupee.
 *
 * Les coordonnees (raison sociale / nom, adresse postale) et les factures sont
 * relues a la volee depuis la vue recouvrement.v_impayes (lecture seule, jamais
 * mirror.*), de sorte que la lettre reflete l'encours reel a l'instant de
 * l'impression. Le rendu HTML est produit par Twig puis converti par dompdf
 * (styles inline, pas de Tailwind dans le PDF).
 *
 * @phpstan-import-type GroupeARelancer from SelectionRelanceService
 *
 * @phpstan-type DonneesLettre array{
 *     courrier: RelanceEnvoi,
 *     niveau: int,
 *     groupe: GroupeARelancer|null,
 *     destinataire_nom: string,
 *     adresse: ?string,
 *     formule_appel: string,
 *     total: string,
 *     nb_factures: int,
 *     est_med: bool,
 *     date_jour: \DateTimeImmutable
 * }
 */
final class LettreCourrierPdfService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly RecouvrementRepository $recouvrementRepository,
        private readonly Connection $connection,
        private readonly PdfFactureProvider $pdfFactureProvider,
        private readonly FusionPdfService $fusionPdfService,
        private readonly HtmlPdfConverter $htmlPdf,
        private readonly LogoRecouvrement $logo,
        private readonly SelectionRelanceService $selection,
    ) {
    }

    /**
     * PDF d'UNE lettre (un courrier).
     *
     * Valeur probante : un courrier DEJA POSTE possedant un snapshot (corps_html
     * fige au marquage) est ressorti TEL QU'EMIS, jamais regenere depuis des
     * donnees qui ont pu changer. Sinon (courrier a envoyer), rendu a la volee.
     */
    public function lettre(RelanceEnvoi $courrier): string
    {
        return $this->avecFactures(
            $this->rendre($this->htmlLettre($courrier)),
            $courrier->getCompteCode(),
        );
    }

    /**
     * HTML a imprimer : snapshot fige (courrier deja poste = valeur probante),
     * sinon rendu a la volee depuis les donnees courantes.
     */
    private function htmlLettre(RelanceEnvoi $courrier): string
    {
        $snapshot = $courrier->getCorpsHtml();
        if (RelanceStatut::ENVOYE === $courrier->getStatut() && null !== $snapshot && '' !== $snapshot) {
            return $snapshot;
        }

        return $this->lettreHtml($courrier);
    }

    /**
     * HTML de la lettre (rendu a la volee depuis les donnees courantes). Sert au
     * rendu PDF live ET au snapshot pose au marquage "poste".
     */
    public function lettreHtml(RelanceEnvoi $courrier): string
    {
        return $this->twig->render('recouvrement/courrier/lettre.html.twig', [
            'lettre' => $this->donneesLettre($courrier),
            'logo_src' => $this->logo->dataUri(),
        ]);
    }

    /**
     * PDF du LOT : toutes les lettres fournies, une par page.
     *
     * @param list<RelanceEnvoi> $courriers
     */
    public function lot(array $courriers): string
    {
        // Chaque courrier : sa lettre (releve) SUIVIE de ses factures, le tout
        // fusionne en un seul PDF (releve + factures du client 1, puis client 2...).
        $pdfs = [];
        foreach ($courriers as $courrier) {
            $pdfs[] = $this->rendre($this->htmlLettre($courrier));
            $pdfs = array_merge($pdfs, $this->pdfsFactures($courrier->getCompteCode()));
        }

        $fusionne = [] !== $pdfs ? $this->fusionPdfService->fusionner($pdfs) : null;
        if (null !== $fusionne) {
            return $fusionne;
        }

        // Repli (Ghostscript indisponible) : les lettres seules, une par page.
        $lettres = array_map(fn (RelanceEnvoi $c): array => $this->donneesLettre($c), $courriers);

        return $this->rendre($this->twig->render('recouvrement/courrier/_lot.html.twig', [
            'lettres' => $lettres,
            'logo_src' => $this->logo->dataUri(),
        ]));
    }

    /**
     * Fusionne le PDF de la lettre (releve) avec les PDF des factures du compte,
     * recuperes via l'URL Progiciel (PdfFactureProvider). Repli sur la lettre seule si
     * aucune facture recuperee ou si la fusion (Ghostscript) est indisponible.
     */
    private function avecFactures(string $lettrePdf, string $compte): string
    {
        $pdfs = array_merge([$lettrePdf], $this->pdfsFactures($compte));
        if (1 === \count($pdfs)) {
            return $lettrePdf;
        }

        return $this->fusionPdfService->fusionner($pdfs) ?? $lettrePdf;
    }

    /**
     * PDF des factures echues d'un compte, recuperes via l'URL Progiciel, dans l'ordre.
     * Une facture dont le PDF n'est pas joignable est simplement omise.
     *
     * @return list<string>
     */
    private function pdfsFactures(string $compte): array
    {
        $pdfs = [];
        foreach ($this->recouvrementRepository->facturesDuCompte($compte) as $facture) {
            $reference = (string) ($facture['reference_facture'] ?? $facture['numpiece'] ?? '');
            $chemin = isset($facture['chemin_pdf']) ? (string) $facture['chemin_pdf'] : null;
            $pdf = $this->pdfFactureProvider->recuperer($reference, $compte, $chemin);
            if (null !== $pdf) {
                $pdfs[] = $pdf;
            }
        }

        return $pdfs;
    }

    /**
     * Assemble les donnees d'affichage d'une lettre a partir du courrier persiste
     * et des donnees fraiches de la vue (coordonnees + factures du compte).
     *
     * @return DonneesLettre
     */
    private function donneesLettre(RelanceEnvoi $courrier): array
    {
        $compte = $courrier->getCompteCode();
        $niveau = $courrier->getNiveau();
        $med = $courrier->isMiseEnDemeure() || $niveau >= 3;

        // Meme document que le releve (table sobre + RIB + QR), construit par le meme
        // moteur (donc gel niveau facture applique) ; repli sur le montant fige si plus
        // aucune facture eligible (compte solde / tout gele entre temps).
        $groupe = $this->selection->groupeManuel($compte, null, $niveau, $med);
        $coordonnees = $this->coordonnees($compte);

        return [
            'courrier' => $courrier,
            'niveau' => $niveau,
            'groupe' => $groupe,
            'destinataire_nom' => (string) ($groupe['destinataire_nom'] ?? ('' !== $coordonnees['nom'] ? $coordonnees['nom'] : $compte)),
            'adresse' => $groupe['adresse'] ?? $coordonnees['adresse'],
            'formule_appel' => (string) ($groupe['formule_appel'] ?? $coordonnees['formule_appel']),
            'total' => (string) ($groupe['total'] ?? ($courrier->getMontantSolde() ?? '0')),
            'nb_factures' => (int) ($groupe['nb_factures'] ?? 0),
            'est_med' => $med,
            'date_jour' => new DateTimeImmutable(),
        ];
    }

    /**
     * Coordonnees du compte depuis v_impayes (nom d'affichage, adresse postale,
     * formule d'appel). Une seule ligne suffit : les champs tiers sont identiques
     * pour toutes les factures d'un meme compte.
     *
     * @return array{nom: string, adresse: ?string, formule_appel: string}
     */
    private function coordonnees(string $compte): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT raison_sociale, nom, prenom, civilite, adresse '
            .'FROM recouvrement.v_impayes WHERE compte = :compte LIMIT 1',
            ['compte' => $compte],
        );

        if (false === $row) {
            return ['nom' => $compte, 'adresse' => null, 'formule_appel' => 'Madame, Monsieur'];
        }

        return [
            'nom' => $this->composerNom($row),
            'adresse' => self::nullableString($row['adresse'] ?? null),
            'formule_appel' => $this->composerFormuleAppel($row),
        ];
    }

    /**
     * Nom d'affichage : raison sociale si presente, sinon "Prenom Nom", sinon le
     * code compte (jamais vide).
     *
     * @param array<string, mixed> $row
     */
    private function composerNom(array $row): string
    {
        $raisonSociale = trim((string) ($row['raison_sociale'] ?? ''));
        if ('' !== $raisonSociale) {
            return $raisonSociale;
        }

        $prenom = trim((string) ($row['prenom'] ?? ''));
        $nom = trim((string) ($row['nom'] ?? ''));
        $complet = trim($prenom.' '.$nom);

        return '' !== $complet ? $complet : '';
    }

    /**
     * Formule d'appel a partir du code civilite Progiciel (repli "Madame, Monsieur").
     *
     * @param array<string, mixed> $row
     */
    private function composerFormuleAppel(array $row): string
    {
        $code = strtoupper(trim((string) ($row['civilite'] ?? '')));

        return match ($code) {
            'M', 'MR', 'MONSIEUR', '1' => 'Monsieur',
            'MME', 'MADAME', '2' => 'Madame',
            'MLLE', 'MADEMOISELLE' => 'Madame',
            default => 'Madame, Monsieur',
        };
    }

    /**
     * Convertit le HTML en PDF A4 portrait (via le convertisseur partage).
     */
    private function rendre(string $html): string
    {
        return $this->htmlPdf->enPdf($html);
    }

    private static function nullableString(mixed $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }
}
