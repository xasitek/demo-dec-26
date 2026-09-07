<?php

declare(strict_types=1);

namespace App\GrandsComptes\Moteur;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Le fabricant de documents de demonstration.
 *
 * Il produit de vrais fichiers locaux -- un par piece -- a partir des VALEURS
 * de la piece, et de rien d'autre. C'est ce qui rend l'ecran de controle
 * honnete : le document qu'on voit a droite porte exactement ce que la regle
 * lit a gauche. Un numero de commande absent de la facture est absent du
 * document ; un PV non signe montre une case de signature vide.
 *
 * Tous ces documents sont FICTIFS et generes pour la demonstration. Chacun
 * porte le filigrane qui le dit, et aucun ne reprend un document reel.
 */
final class Fabricant
{
    /** Le nom du groupe synthetique. Aucun nom reel n'apparait nulle part. */
    private const GROUPE = 'GROUPE SYNTHAUTO';

    public function __construct(private readonly Connection $cnx)
    {
    }

    /**
     * Le document d'une piece, en HTML autonome.
     *
     * @return array{titre: string, html: string}|null
     */
    public function document(string $pieceId): ?array
    {
        $p = $this->cnx->fetchAssociative(
            'SELECT p.*, t.libelle AS type_libelle, d.*, l.nom AS loueur_nom, e.nom AS etablissement_nom
               FROM grands_comptes.piece p
               JOIN grands_comptes.type_piece t ON t.code = p.type_piece
               JOIN grands_comptes.dossier d ON d.id = p.dossier_id
               JOIN grands_comptes.loueur l ON l.loueur_id = d.loueur_id
               LEFT JOIN affectation.etablissement e ON e.id = d.etablissement_id
              WHERE p.id = ?', [$pieceId]);
        if (false === $p) {
            return null;
        }
        // La jointure ecrase `id` : on relit les deux identifiants proprement.
        $piece = $this->cnx->fetchAssociative(
            'SELECT * FROM grands_comptes.piece WHERE id = ?', [$pieceId]) ?: [];

        $type = (string) $piece['type_piece'];
        $corps = match ($type) {
            'PVL' => $this->pvLivraison($p, $piece),
            'BDC' => $this->bonDeCommande($p, $piece),
            'CPI' => $this->certificat($p, $piece),
            'F1', 'F2' => $this->facture($p, $piece, $type),
            'CG' => $this->carteGrise($p, $piece),
            'RIB' => $this->rib($p, $piece),
            default => '<p>Type de pièce inconnu.</p>',
        };

        $titre = sprintf('%s — %s', (string) $p['type_libelle'], (string) $p['immatriculation']);

        return ['titre' => $titre, 'html' => $this->envelopper($titre, $corps)];
    }

    /** Le squelette commun, avec le filigrane qui dit ce que c'est. */
    private function envelopper(string $titre, string $corps): string
    {
        $t = htmlspecialchars($titre, \ENT_QUOTES);

        return <<<HTML
            <!doctype html>
            <html lang="fr"><head><meta charset="utf-8">
            <title>{$t}</title>
            <style>
              :root { color-scheme: light; }
              body { margin: 0; padding: 22px; background: #eceae5;
                font: 13px/1.5 "Times New Roman", Georgia, serif; color: #1a1a1a; }
              .feuille { position: relative; max-width: 760px; margin: 0 auto; padding: 30px 34px;
                background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.14); overflow: hidden; }
              .filigrane { position: absolute; inset: 0; display: grid; place-items: center;
                pointer-events: none; }
              .filigrane span { transform: rotate(-24deg); font: 700 30px/1 Arial, sans-serif;
                letter-spacing: .16em; color: rgba(190,30,45,.13); white-space: nowrap; text-align: center; }
              h1 { margin: 0 0 2px; font-size: 19px; letter-spacing: .04em; text-transform: uppercase; }
              h2 { margin: 20px 0 6px; font-size: 13px; text-transform: uppercase;
                letter-spacing: .08em; border-bottom: 1px solid #bbb; padding-bottom: 3px; }
              .entete { display: flex; justify-content: space-between; align-items: flex-start;
                border-bottom: 2px solid #2d3250; padding-bottom: 10px; }
              .marque { font: 700 15px/1.2 Arial, sans-serif; color: #2d3250; letter-spacing: .1em; }
              table { width: 100%; border-collapse: collapse; margin: 6px 0; }
              td, th { padding: 4px 6px; vertical-align: top; }
              .cadre td, .cadre th { border: 1px solid #999; }
              th { background: #f2f1ee; text-align: left; font: 700 11px/1.4 Arial, sans-serif;
                text-transform: uppercase; letter-spacing: .05em; }
              .droite { text-align: right; }
              .totaux td { border-top: 1px solid #333; font-weight: 700; }
              .vide { color: #b91c1c; font-style: italic; }
              .signature { margin-top: 24px; display: flex; gap: 24px; }
              .signature div { flex: 1; border: 1px solid #999; height: 78px; padding: 4px 6px;
                font: 10px/1.3 Arial, sans-serif; text-transform: uppercase; color: #666; }
              .pied { margin-top: 22px; border-top: 1px solid #ccc; padding-top: 6px;
                font: 9.5px/1.4 Arial, sans-serif; color: #777; }
              .mention { font: 700 10px/1.4 Arial, sans-serif; color: #b91c1c;
                text-transform: uppercase; letter-spacing: .06em; }
            </style></head><body>
            <div class="feuille">
              <div class="filigrane"><span>DOCUMENT FICTIF — DÉMONSTRATION</span></div>
              {$corps}
              <p class="pied">Document synthétique produit pour la démonstration du mémoire.
                 Aucune donnée réelle, aucun client réel, aucune valeur réelle.</p>
            </div>
            </body></html>
            HTML;
    }

    /**
     * @param array<string, mixed> $d dossier et piece joints
     * @param array<string, mixed> $p la piece seule
     */
    private function pvLivraison(array $d, array $p): string
    {
        $date = null !== $p['date_lue']
            ? $this->jour($p['date_lue'])
            : '<span class="vide">non renseignée</span>';
        $signe = true === $p['signe'] ? 'Signature du client' : '<span class="vide">non signé</span>';
        $tampon = true === $p['tampon']
            ? 'Cachet du site apposé'
            : (false === $p['tampon'] ? '<span class="vide">aucun cachet</span>' : 'Cachet non exigé');
        $immat = htmlspecialchars((string) ($p['immatriculation_lue'] ?? ''), \ENT_QUOTES);

        return $this->entete('Procès-verbal de livraison')
            .'<table><tr><td><b>Loueur</b><br>'.$this->e($d['loueur_nom']).'</td>'
            .'<td><b>Site livreur</b><br>'.$this->e($d['etablissement_nom'] ?? $d['etablissement_id']).'</td>'
            .'<td><b>Date de livraison</b><br>'.$date.'</td></tr></table>'
            .'<h2>Véhicule livré</h2>'
            .'<table class="cadre"><tr><th>Immatriculation</th><th>Numéro de série (VIN)</th>'
            .'<th>Modèle</th><th>Énergie</th></tr>'
            .'<tr><td><b>'.$immat.'</b></td><td>'.$this->e($d['vin']).'</td>'
            .'<td>'.$this->e($d['modele']).'</td><td>'.$this->e($d['energie']).'</td></tr></table>'
            .'<p>Le locataire ou son mandataire reconnaît avoir pris livraison du véhicule '
            .'désigné ci-dessus, muni de tous ses accessoires et documents de bord.</p>'
            .'<div class="signature"><div>'.$signe.'</div><div>'.$tampon.'</div></div>';
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $p
     */
    private function bonDeCommande(array $d, array $p): string
    {
        $numero = null !== $p['numero_lu']
            ? '<b>'.$this->e($p['numero_lu']).'</b>'
            : '<span class="vide">numéro illisible</span>';
        $montant = null !== $p['montant_lu'] ? $this->euros($p['montant_lu']) : '—';

        return $this->entete('Bon de commande')
            .'<table><tr><td><b>Numéro de commande</b><br>'.$numero.'</td>'
            .'<td><b>Client facturé</b><br>'.$this->e($d['loueur_nom']).'</td>'
            .'<td><b>Date</b><br>'.$this->jour($p['date_lue'] ?? $d['date_livraison']).'</td></tr></table>'
            .'<h2>Désignation</h2>'
            .'<table class="cadre"><tr><th>Véhicule</th><th>Immatriculation</th>'
            .'<th class="droite">Montant TTC</th></tr>'
            .'<tr><td>'.$this->e($d['modele']).'</td><td>'.$this->e($d['immatriculation']).'</td>'
            .'<td class="droite">'.$montant.'</td></tr></table>'
            .'<p class="mention">Ce numéro de commande doit être repris sur la facture.</p>';
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $p
     */
    private function certificat(array $d, array $p): string
    {
        return $this->entete("Certificat provisoire d'immatriculation")
            .'<table class="cadre"><tr><th>Numéro du certificat</th><th>Immatriculation</th>'
            .'<th>Date de mise en circulation</th></tr>'
            .'<tr><td>'.$this->e($p['numero_lu']).'</td><td><b>'.$this->e($d['immatriculation']).'</b></td>'
            .'<td>'.$this->jour($p['date_lue'] ?? $d['date_livraison']).'</td></tr></table>'
            .'<p>Ce certificat autorise la circulation du véhicule dans l\'attente de la '
            .'carte grise définitive.</p>';
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $p
     */
    private function facture(array $d, array $p, string $type): string
    {
        $destinataire = 'client' === $p['adresse_facturation']
            ? '<span class="vide">'.$this->e($d['modele']).' — conducteur du véhicule</span>'
            : '<b>'.$this->e($d['loueur_nom']).'</b>';
        $commande = null !== $p['numero_commande_lu']
            ? $this->e($p['numero_commande_lu'])
            : '<span class="vide">aucun numéro de commande</span>';

        $lignes = '<tr><td>'.$this->e($d['modele']).' — '.$this->e($d['immatriculation']).'</td>'
            .'<td class="droite">'.$this->euros($p['montant_lu']).'</td></tr>';

        if (true === $p['ligne_batterie']) {
            if (null === $p['prix_batterie']) {
                $lignes .= '<tr><td>Batterie de traction'
                    .'</td><td class="droite vide">prix non porté</td></tr>';
            } else {
                $ht = null !== $p['prix_batterie_ht']
                    ? $this->euros($p['prix_batterie_ht']).(true === $p['mention_devise'] ? ' HT' : '')
                    : '<span class="vide">HT manquant</span>';
                $ttc = null !== $p['prix_batterie_ttc']
                    ? $this->euros($p['prix_batterie_ttc']).(true === $p['mention_devise'] ? ' TTC' : '')
                    : '<span class="vide">TTC manquant</span>';
                $lignes .= '<tr><td>Batterie de traction — montant hors taxes</td>'
                    .'<td class="droite">'.$ht.'</td></tr>'
                    .'<tr><td>Batterie de traction — montant toutes taxes</td>'
                    .'<td class="droite">'.$ttc.'</td></tr>';
            }
        }

        return $this->entete('F2' === $type ? 'Facture — accessoires et options' : 'Facture')
            .'<table><tr><td><b>Numéro de facture</b><br>'.$this->e($p['numero_lu']).'</td>'
            .'<td><b>Votre commande</b><br>'.$commande.'</td>'
            .'<td><b>Date</b><br>'.$this->jour($p['date_lue'] ?? $d['date_livraison']).'</td></tr></table>'
            .'<h2>Adressée à</h2><p>'.$destinataire.'</p>'
            .'<h2>Détail</h2>'
            .'<table class="cadre"><tr><th>Désignation</th><th class="droite">Montant</th></tr>'
            .$lignes
            .'<tr class="totaux"><td>Total à payer</td><td class="droite">'
            .$this->euros($p['montant_lu']).'</td></tr></table>';
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $p
     */
    private function carteGrise(array $d, array $p): string
    {
        return $this->entete('Certificat d\'immatriculation')
            .'<table class="cadre"><tr><th>A — Immatriculation</th><th>E — Numéro de série</th></tr>'
            .'<tr><td><b>'.$this->e($p['immatriculation_lue'] ?? $d['immatriculation']).'</b></td>'
            .'<td>'.$this->e($d['vin']).'</td></tr>'
            .'<tr><th>C.1 — Titulaire</th><th>B — Première immatriculation</th></tr>'
            .'<tr><td>'.$this->e($d['loueur_nom']).'</td>'
            .'<td>'.$this->jour($p['date_lue'] ?? $d['date_livraison']).'</td></tr>'
            .'<tr><th>D.2 — Type, variante, version</th><th>P.3 — Énergie</th></tr>'
            .'<tr><td>'.$this->e($d['modele']).'</td><td>'.$this->e($d['energie']).'</td></tr></table>';
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $p
     */
    private function rib(array $d, array $p): string
    {
        return $this->entete('Relevé d\'identité bancaire')
            .'<table class="cadre"><tr><th>Titulaire du compte</th></tr>'
            .'<tr><td>'.$this->e($d['loueur_nom']).'</td></tr>'
            .'<tr><th>IBAN</th></tr><tr><td><b>'.$this->e($p['numero_lu']).'</b></td></tr>'
            .'<tr><th>Domiciliation</th></tr><tr><td>BANQUE SYNTHÉTIQUE — agence de démonstration</td></tr></table>'
            .'<p class="mention">Pour la bonne prise en compte de votre règlement, indiquez le '
            .'numéro de commande, le numéro de facture ou l\'immatriculation.</p>';
    }

    private function entete(string $titre): string
    {
        return '<div class="entete"><div><h1>'.$this->e($titre).'</h1>'
            .'<div style="font:11px/1.4 Arial,sans-serif;color:#666">Pièce du dossier de livraison</div></div>'
            .'<div class="marque">'.self::GROUPE.'</div></div>';
    }

    private function e(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? '—'), \ENT_QUOTES);
    }

    private function jour(mixed $v): string
    {
        if (null === $v || '' === $v) {
            return '—';
        }
        $d = new DateTimeImmutable((string) $v);

        return $d->format('d/m/Y');
    }

    private function euros(mixed $v): string
    {
        if (null === $v) {
            return '—';
        }

        return number_format((float) $v, 2, ',', ' ').' €';
    }
}
