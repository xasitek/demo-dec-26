<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\BuyBackVehicule;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Service\Ia\ChampDocument;
use App\Remboursement\Service\Ia\ProviderOcrLocal;
use RuntimeException;

/**
 * Fabrique les pieces justificatives SYNTHETIQUES d'un dossier de demonstration.
 *
 * Un document est produit depuis une SOURCE CANONIQUE UNIQUE : une liste de
 * ChampDocument. Le contenu visible et le bloc machine en sortent tous les deux,
 * par la meme boucle. C'est ce qui garantit l'invariant : on ne peut pas
 * afficher « lu sur la piece : X » quand la piece porte Y, parce que le visible
 * et le structure ne sont jamais construits separement.
 *
 * L'invariant est verifie APRES rendu, sur le document lui-meme : chaque valeur
 * visible declaree doit se retrouver dans le texte du document, et chaque champ
 * du bloc machine doit avoir une contrepartie visible. Une divergence fait
 * echouer la fabrication -- elle ne produit pas un document douteux.
 *
 * Tous ces documents sont FICTIFS. Chacun porte son filigrane.
 */
final class FabricantPieces
{
    private const GROUPE = 'GROUPE SYNTHAUTO';

    /**
     * Le document d'une piece : contenu visible, bloc machine, et invariant tenu.
     *
     * @param array<string, mixed> $contexte donnees complementaires du scenario
     *
     * @return array{html: string, champs: list<ChampDocument>, nom: string, mime: string}
     */
    public function pour(
        Dossier $dossier,
        string $typePiece,
        ?BuyBackVehicule $buyBack = null,
        array $contexte = [],
    ): array {
        $champs = $this->champs($dossier, $typePiece, $buyBack, $contexte);
        if ([] === $champs) {
            throw new RuntimeException('Type de pièce non pris en charge : '.$typePiece);
        }

        $titre = $this->titre($typePiece);
        $html = $this->rendre($dossier, $titre, $typePiece, $champs);
        $this->verifierInvariant($html, $champs, $typePiece);

        return [
            'html' => $html,
            'champs' => $champs,
            'nom' => sprintf('%s-%s.html', $dossier->getReference(), $typePiece),
            'mime' => 'text/html',
        ];
    }

    /**
     * La source canonique, par type de piece.
     *
     * Les cles sont EXACTEMENT celles que le gabarit d'extraction du module
     * attend : le fournisseur local ne rend que ce que le gabarit demande, et
     * un champ mal nomme serait donc silencieusement ignore. On aligne ici.
     *
     * @param array<string, mixed> $contexte
     *
     * @return list<ChampDocument>
     */
    private function champs(
        Dossier $dossier,
        string $type,
        ?BuyBackVehicule $buyBack,
        array $contexte,
    ): array {
        $montant = (float) $dossier->getMontant();
        $immat = (string) $dossier->getImmatriculation();
        $icar = (string) $dossier->getCodeIcar();
        $nom = $dossier->getNomClient();

        // Le scenario peut demander une divergence : c'est le document qui porte
        // l'autre valeur, et le triptyque la montrera pour de vrai.
        $ibanDocument = (string) ($contexte['iban_document'] ?? $dossier->getIbanClient());
        $montantDocument = (float) ($contexte['montant_document'] ?? $montant);

        return match ($type) {
            'rib' => [
                ChampDocument::texte('nom', 'Titulaire du compte', $nom),
                ChampDocument::texte('iban', 'IBAN', $ibanDocument),
                ChampDocument::texte('bic', 'BIC', (string) ($contexte['bic_document'] ?? $dossier->getBicClient())),
            ],
            'facture_achat_vo' => [
                ChampDocument::texte('num_facture', 'Numéro de facture',
                    (string) ($contexte['num_facture'] ?? 'FA-'.substr($dossier->getReference(), 5))),
                ChampDocument::montant('montant', 'Montant TTC', $montantDocument),
                ChampDocument::texte('immatriculation', 'Immatriculation du véhicule', $immat),
                ChampDocument::texte('role_tiers', 'Rôle du tiers', (string) ($contexte['role_tiers'] ?? 'COMPTANT')),
                ChampDocument::texte('code_comptable', 'Compte comptable', (string) ($contexte['code_comptable'] ?? '4111000')),
                ChampDocument::texte('code_icar', 'Code client ICAR', '' !== $icar ? $icar : (string) ($contexte['code_icar'] ?? '')),
                ChampDocument::fait('document_invalide', 'Lisibilité du document',
                    (bool) ($contexte['document_invalide'] ?? false),
                    'document illisible', 'document lisible'),
                // Marqueur TECHNIQUE, et il est VISIBLE lui aussi : le document
                // declare que le fournisseur local ne peut pas le lire. Deux
                // situations a ne jamais confondre — un document illisible est
                // un fait documentaire que le comptable arbitre ; une panne du
                // fournisseur est une indisponibilite technique, et le module
                // la traite autrement. Le nom de la cle est celui que le
                // fournisseur cherche (ProviderOcrLocal::MARQUEUR_PANNE).
                ChampDocument::fait(ProviderOcrLocal::MARQUEUR_PANNE, 'Lecture par le fournisseur local',
                    (bool) ($contexte['panne_extraction'] ?? false),
                    'indisponible sur cette pièce', 'disponible'),
            ],
            'estimation_salesforce' => [
                ChampDocument::montant('montant_coherence', 'Montant de reprise proposé',
                    (float) ($contexte['montant_coherence'] ?? $montantDocument)),
                ChampDocument::fait('modifications_detectees', 'Modifications après édition',
                    (bool) ($contexte['modifications_detectees'] ?? false),
                    'modifications détectées', 'aucune modification'),
            ],
            'carte_grise' => [
                ChampDocument::texte('immatriculation', 'A — Immatriculation', $immat),
                ChampDocument::fait('ecriture_manuscrite', 'Mentions manuscrites',
                    (bool) ($contexte['ecriture_manuscrite'] ?? false),
                    'mentions manuscrites présentes', 'aucune mention manuscrite'),
                ChampDocument::fait('document_invalide', 'Lisibilité du document',
                    (bool) ($contexte['document_invalide'] ?? false),
                    'document illisible', 'document lisible'),
            ],
            'certificat_situation' => [
                ChampDocument::texte('immatriculation', 'Immatriculation', $immat),
                ChampDocument::fait('vehicule_libre', 'Situation administrative',
                    (bool) ($contexte['vehicule_libre'] ?? true),
                    'véhicule libre de tout gage et opposition', 'véhicule gagé ou frappé d\'opposition'),
                ChampDocument::texte('raison', 'Observation',
                    (string) ($contexte['raison'] ?? 'Aucune opposition enregistrée')),
            ],
            'releve_icar' => [
                ChampDocument::texte('releve_icar', 'Compte client ICAR', $icar),
                ChampDocument::montant('montant', 'Solde créditeur du compte', $montantDocument),
                ChampDocument::montant('total_non_lettre', 'Total non lettré',
                    (float) ($contexte['total_non_lettre'] ?? $montantDocument)),
                ChampDocument::texte('societe', 'Société', (string) ($contexte['societe'] ?? self::GROUPE)),
                // Le trop-percu porte la PANNE ici : c'est sa piece maitresse,
                // comme la facture l'est au rachat sec.
                //
                // On n'y met PAS de champ « document illisible » : le gabarit
                // d'extraction du module ne l'attend pas pour ce type de piece,
                // le fournisseur ne le rendrait donc pas, et le document
                // afficherait une mention dont rien ne tiendrait compte. Un
                // document illisible se joue donc sur un dossier de rachat,
                // dont la facture porte ce champ. Le monde en decide.
                ChampDocument::fait(ProviderOcrLocal::MARQUEUR_PANNE, 'Lecture par le fournisseur local',
                    (bool) ($contexte['panne_extraction'] ?? false),
                    'indisponible sur cette pièce', 'disponible'),
            ],
            'petits_comptes' => [
                ChampDocument::texte('nom', 'Nom du client final', $nom),
                ChampDocument::texte('icar', 'Numéro de client ICAR', $icar),
                ChampDocument::montant('petits_comptes', 'Solde à rembourser', $montantDocument),
                ChampDocument::fait('absent', 'Présence de la fiche',
                    (bool) ($contexte['absent'] ?? false),
                    'fiche absente du dossier', 'fiche présente'),
            ],
            // L'engagement de reprise : ce n'est pas une piece exigee par le
            // module, mais le jury doit pouvoir l'ouvrir pour comprendre le
            // controle de surpaiement. Il est donc joint au dossier.
            'engagement_buyback' => null === $buyBack ? [] : [
                ChampDocument::texte('contrat', 'Numéro de contrat', $buyBack->getContrat()),
                ChampDocument::texte('immatriculation', 'Immatriculation', $buyBack->getImmatriculation()),
                ChampDocument::texte('vin', 'Numéro de série (VIN)', $buyBack->getVin()),
                ChampDocument::montant('er_ht', 'Engagement de reprise HT',
                    null === $buyBack->getErHt() ? null : (float) $buyBack->getErHt()),
                ChampDocument::montant('er_ttc', 'Engagement de reprise TTC',
                    null === $buyBack->getErTtc() ? null : (float) $buyBack->getErTtc()),
                ChampDocument::texte('financeur', 'Financeur', $buyBack->getFinanceur()),
                ChampDocument::texte('echeance', 'Échéance du contrat',
                    $buyBack->getDateEcheance()?->format('d/m/Y')),
            ],
            default => [],
        };
    }

    /**
     * Rend le document : le visible et le bloc machine, depuis les memes champs.
     *
     * @param list<ChampDocument> $champs
     */
    private function rendre(Dossier $dossier, string $titre, string $type, array $champs): string
    {
        $lignes = '';
        $machine = [];
        foreach ($champs as $c) {
            $lignes .= sprintf(
                '<tr><th>%s</th><td class="valeur">%s</td></tr>',
                htmlspecialchars($c->libelle, \ENT_QUOTES),
                htmlspecialchars($c->visible, \ENT_QUOTES));
            $machine[$c->cle] = $c->structure;
        }

        $json = json_encode($machine,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $t = htmlspecialchars($titre.' — '.$dossier->getReference(), \ENT_QUOTES);
        $balise = ProviderOcrLocal::BALISE;

        return <<<HTML
            <!doctype html>
            <html lang="fr"><head><meta charset="utf-8"><title>{$t}</title>
            <style>
              body { margin:0; padding:22px; background:#eceae5;
                font:13px/1.55 "Times New Roman", Georgia, serif; color:#1a1a1a; }
              .feuille { position:relative; max-width:720px; margin:0 auto; padding:30px 34px;
                background:#fff; box-shadow:0 2px 14px rgba(0,0,0,.14); overflow:hidden; }
              .filigrane { position:absolute; inset:0; display:grid; place-items:center; pointer-events:none; }
              .filigrane span { transform:rotate(-24deg); font:700 28px/1 Arial,sans-serif;
                letter-spacing:.16em; color:rgba(190,30,45,.13); white-space:nowrap; }
              .entete { display:flex; justify-content:space-between; align-items:flex-start;
                border-bottom:2px solid #2d3250; padding-bottom:10px; margin-bottom:14px; }
              h1 { margin:0; font-size:18px; letter-spacing:.04em; text-transform:uppercase; }
              .marque { font:700 15px/1.2 Arial,sans-serif; color:#2d3250; letter-spacing:.1em; }
              table { width:100%; border-collapse:collapse; }
              th, td { padding:6px 8px; border:1px solid #b9b9b9; vertical-align:top; }
              th { width:42%; background:#f2f1ee; text-align:left;
                font:700 11px/1.4 Arial,sans-serif; text-transform:uppercase; letter-spacing:.04em; }
              .valeur { font-family:"Courier New",monospace; }
              .pied { margin-top:18px; border-top:1px solid #ccc; padding-top:6px;
                font:9.5px/1.45 Arial,sans-serif; color:#777; }
            </style></head><body>
            <div class="feuille">
              <div class="filigrane"><span>DOCUMENT FICTIF — DÉMONSTRATION</span></div>
              <div class="entete">
                <div><h1>{$titre}</h1>
                  <div style="font:11px/1.4 Arial,sans-serif;color:#666">
                    Dossier {$dossier->getReference()} · pièce « {$type} »</div></div>
                <div class="marque">GROUPE SYNTHAUTO</div>
              </div>
              <table>{$lignes}</table>
              <p class="pied">Document synthétique produit pour la démonstration du mémoire.
                 Aucune donnée réelle, aucun client réel, aucune coordonnée bancaire réelle.
                 Les informations affichées ci-dessus sont également portées, sous forme
                 structurée, à la fin de ce fichier : c'est cette représentation que la lecture
                 locale déterministe restitue.</p>
            </div>
            <script type="application/json" id="{$balise}">
            {$json}
            </script>
            </body></html>
            HTML;
    }

    /**
     * Le meme document, en IMAGE, pour passer par le formulaire de depot.
     *
     * Le formulaire n'accepte que des PDF et des images -- c'est un garde-fou
     * du module, et on ne le contourne pas : on lui donne une image. Un SVG est
     * une image (mime image/svg+xml) et reste du texte, donc il peut porter le
     * meme bloc structure que la version HTML. Memes champs, meme source
     * canonique, meme invariant : seule l'enveloppe change.
     *
     * @param array<string, mixed> $contexte
     *
     * @return array{svg: string, champs: list<ChampDocument>, nom: string, mime: string}
     */
    public function pourDepot(
        Dossier $dossier,
        string $typePiece,
        ?BuyBackVehicule $buyBack = null,
        array $contexte = [],
    ): array {
        $champs = $this->champs($dossier, $typePiece, $buyBack, $contexte);
        if ([] === $champs) {
            throw new RuntimeException('Type de pièce non pris en charge : '.$typePiece);
        }

        $titre = $this->titre($typePiece);
        $svg = $this->rendreSvg($dossier, $titre, $champs);
        $this->verifierInvariant($svg, $champs, $typePiece);

        return [
            'svg' => $svg,
            'champs' => $champs,
            'nom' => sprintf('%s-%s.svg', $dossier->getReference(), $typePiece),
            'mime' => 'image/svg+xml',
        ];
    }

    /**
     * Le rendu image : une ligne de texte par champ, et le bloc structure.
     *
     * @param list<ChampDocument> $champs
     */
    private function rendreSvg(Dossier $dossier, string $titre, array $champs): string
    {
        $hauteur = 120 + \count($champs) * 26;
        $e = static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_XML1);
        $lignes = '';
        $y = 108;
        $machine = [];
        foreach ($champs as $c) {
            $lignes .= sprintf(
                '<text x="40" y="%d" font-family="Arial" font-size="13" fill="#555">%s</text>'
                .'<text x="330" y="%d" font-family="Courier New" font-size="13" fill="#111">%s</text>',
                $y, $e($c->libelle), $y, $e($c->visible));
            $machine[$c->cle] = $c->structure;
            $y += 26;
        }
        $json = json_encode($machine,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $balise = ProviderOcrLocal::BALISE;
        $t = $e($titre.' — '.(string) $dossier->getReference());

        return <<<SVG
            <?xml version="1.0" encoding="UTF-8"?>
            <svg xmlns="http://www.w3.org/2000/svg" width="760" height="{$hauteur}" viewBox="0 0 760 {$hauteur}">
              <rect width="760" height="{$hauteur}" fill="#ffffff"/>
              <text x="40" y="46" font-family="Arial" font-size="17" font-weight="bold" fill="#2d3250">{$t}</text>
              <text x="40" y="70" font-family="Arial" font-size="12" fill="#be1e2d">DOCUMENT FICTIF — DÉMONSTRATION</text>
              <line x1="40" y1="82" x2="720" y2="82" stroke="#2d3250" stroke-width="2"/>
              {$lignes}
              <text x="40" y="{$hauteur}" font-family="Arial" font-size="9" fill="#888">Document synthétique produit pour la démonstration du mémoire.</text>
              <script type="application/json" id="{$balise}">
            {$json}
              </script>
            </svg>
            SVG;
    }

    /**
     * L'INVARIANT : visible et structure ne peuvent pas diverger.
     *
     * On relit le document produit -- pas la source -- et on verifie trois
     * choses. Une seule qui cede fait echouer la fabrication : mieux vaut
     * aucun document qu'un document dont la lecture mentirait.
     *
     * @param list<ChampDocument> $champs
     */
    private function verifierInvariant(string $html, array $champs, string $type): void
    {
        $fautes = [];

        // 1. Le bloc machine est-il present et decodable ?
        if (1 !== preg_match(
            '/<script type="application\/json" id="'.preg_quote(ProviderOcrLocal::BALISE, '/').'">(.*?)<\/script>/s',
            $html, $m)) {
            throw new RuntimeException($type.' : aucun bloc structuré dans le document produit.');
        }
        /** @var array<string, mixed> $machine */
        $machine = json_decode(trim($m[1]), true, 8, \JSON_THROW_ON_ERROR);

        // 2. Le texte visible du document, sans balises ni bloc machine.
        $sansBloc = (string) preg_replace('/<script.*?<\/script>/s', '', $html);
        $sansStyle = (string) preg_replace('/<style.*?<\/style>/s', '', $sansBloc);
        $visible = html_entity_decode((string) preg_replace('/\s+/', ' ',
            strip_tags($sansStyle)), \ENT_QUOTES, 'UTF-8');

        foreach ($champs as $c) {
            // 3. Chaque valeur declaree visible doit vraiment etre lisible.
            if (!str_contains($visible, $c->visible)) {
                $fautes[] = sprintf('la valeur « %s » du champ %s ne figure pas dans le document visible',
                    $c->visible, $c->cle);
            }
            // 4. Chaque champ visible doit avoir sa contrepartie structuree.
            if (!\array_key_exists($c->cle, $machine)) {
                $fautes[] = sprintf('le champ %s est affiché mais absent du bloc structuré', $c->cle);
            }
        }

        // 5. Et l'inverse : aucun champ structure sans contrepartie visible.
        $declares = array_map(static fn (ChampDocument $c): string => $c->cle, $champs);
        foreach (array_keys($machine) as $cle) {
            if (!\in_array((string) $cle, $declares, true)) {
                $fautes[] = sprintf('le champ %s est structuré sans être affiché', (string) $cle);
            }
        }

        if ([] !== $fautes) {
            throw new RuntimeException(sprintf("%s : l'invariant « visible = structuré » a cédé — %s", $type, implode(' ; ', $fautes)));
        }
    }

    private function titre(string $type): string
    {
        return match ($type) {
            'rib' => 'Relevé d\'identité bancaire',
            'facture_achat_vo' => 'Facture d\'achat de véhicule d\'occasion',
            'estimation_salesforce' => 'Offre de reprise',
            'carte_grise' => 'Certificat d\'immatriculation',
            'certificat_situation' => 'Certificat de situation administrative',
            'releve_icar' => 'Relevé de compte client',
            'petits_comptes' => 'Fiche petits comptes',
            'engagement_buyback' => 'Engagement de reprise — contrat Buy Back',
            default => 'Pièce du dossier',
        };
    }

    /** Le libelle affiche d'un type de piece, pour l'ecran et le journal. */
    public function libelle(string $type): string
    {
        return $this->titre($type);
    }

    /**
     * Les pieces a joindre pour un motif, y compris l'engagement de reprise.
     *
     * @return list<string>
     */
    public function typesPour(DossierMotif $motif, bool $avecEngagement): array
    {
        $types = array_keys($motif->piecesRequises());
        if ($avecEngagement && DossierMotif::RACHAT_SEC === $motif) {
            $types[] = 'engagement_buyback';
        }

        /* @var list<string> $types */
        return $types;
    }
}
