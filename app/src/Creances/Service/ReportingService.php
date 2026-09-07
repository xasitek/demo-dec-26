<?php

declare(strict_types=1);

namespace App\Creances\Service;

use App\Creances\Entity\EnvoiProgramme;
use App\Creances\Repository\CreancesRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;

/**
 * Genere des rapports HTML (balance agee, top 10, indicateurs globaux,
 * DSO) et les envoie par e-mail aux destinataires configures.
 *
 * Types de rapports supportes (champ EnvoiProgramme.type) :
 *  - balance_agee         : tableau complet par tranche d'anciennete
 *  - top10                : top 10 clients par encours
 *  - indicateurs_globaux  : DSO + BPDSO + retard moyen + score + encours
 *  - synthese             : combinaison des 3 ci-dessus dans un seul email
 */
final class ReportingService
{
    public function __construct(
        private readonly CreancesRepository $creances,
        private readonly IndicateursService $indicateurs,
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $expediteur = 'recouvrement@demonstration.invalid',
    ) {
    }

    /**
     * Genere le HTML d'un rapport selon son type et sa configuration.
     */
    public function genererHtml(string $type, ?string $libelle = null): string
    {
        return match ($type) {
            'balance_agee' => $this->htmlBalanceAgee($libelle),
            'top10' => $this->htmlTop10($libelle),
            'indicateurs_globaux' => $this->htmlIndicateurs($libelle),
            'synthese' => $this->htmlSynthese($libelle),
            default => '<p>Type de rapport non reconnu : '.htmlspecialchars($type, \ENT_QUOTES, 'UTF-8').'</p>',
        };
    }

    /**
     * Envoie un rapport a tous les destinataires configures. Renvoie le
     * nombre d'envois reussis.
     */
    public function envoyer(EnvoiProgramme $envoi): int
    {
        $destinataires = $envoi->getDestinataires();
        if (empty($destinataires)) {
            return 0;
        }
        $html = $this->genererHtml($envoi->getType(), $envoi->getLibelle());
        $sujet = sprintf('Rapport Recouvrement - %s - %s', $envoi->getLibelle(), date('d/m/Y'));

        $reussis = 0;
        foreach ($destinataires as $destinataire) {
            if (!filter_var($destinataire, \FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            try {
                $email = (new Email())
                    ->from($this->expediteur)
                    ->to($destinataire)
                    ->subject($sujet)
                    ->html($html);
                $this->mailer->send($email);
                ++$reussis;
            } catch (Throwable $e) {
                $this->logger->warning('Echec envoi rapport {id} a {dest} : {message}', [
                    'id' => $envoi->getId(),
                    'dest' => $destinataire,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        $envoi->enregistrerExecution();

        return $reussis;
    }

    // ============================================================
    // Generateurs de rapports
    // ============================================================

    private function htmlBalanceAgee(?string $libelle): string
    {
        $bal = $this->creances->balanceAgeeGlobale();
        $tranches = CreancesRepository::TRANCHES;
        $libelles = CreancesRepository::TRANCHES_LIBELLE;

        $lignes = [];
        foreach ($tranches as $cle => $sageValue) {
            $montant = $bal['tranches'][$sageValue] ?? 0.0;
            $pct = $bal['total'] > 0 ? ($montant / $bal['total'] * 100) : 0;
            $lignes[] = [
                'libelle' => $libelles[$cle],
                'montant' => $montant,
                'pct' => $pct,
            ];
        }

        return $this->enveloppe($libelle ?? 'Balance agee', $this->twig->createTemplate(
            '<h2 style="color:#0e2540;">Balance agee globale</h2>'
            .'<p>Date du rapport : <strong>{{ "now"|date("d/m/Y") }}</strong></p>'
            .'<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">'
            .'<thead><tr style="background:#0e2540;color:white;">'
            .'<th style="padding:8px;text-align:left;">Tranche</th>'
            .'<th style="padding:8px;text-align:right;">Montant</th>'
            .'<th style="padding:8px;text-align:right;">% du total</th>'
            .'</tr></thead><tbody>'
            .'{% for l in lignes %}<tr style="border-bottom:1px solid #e5e7eb;">'
            .'<td style="padding:8px;">{{ l.libelle }}</td>'
            .'<td style="padding:8px;text-align:right;">{{ l.montant|number_format(2, ",", " ") }} EUR</td>'
            .'<td style="padding:8px;text-align:right;">{{ l.pct|number_format(1, ",", " ") }}%</td>'
            .'</tr>{% endfor %}'
            .'<tr style="background:#f3f4f6;font-weight:bold;">'
            .'<td style="padding:8px;">TOTAL</td>'
            .'<td style="padding:8px;text-align:right;">{{ total|number_format(2, ",", " ") }} EUR</td>'
            .'<td style="padding:8px;text-align:right;">100%</td>'
            .'</tr></tbody></table>'
        )->render(['lignes' => $lignes, 'total' => $bal['total']]));
    }

    private function htmlTop10(?string $libelle): string
    {
        $top = $this->creances->topComptesParEncours(10);

        return $this->enveloppe($libelle ?? 'Top 10 clients par encours', $this->twig->createTemplate(
            '<h2 style="color:#0e2540;">Top 10 clients par encours</h2>'
            .'<p>Date du rapport : <strong>{{ "now"|date("d/m/Y") }}</strong></p>'
            .'<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">'
            .'<thead><tr style="background:#0e2540;color:white;">'
            .'<th style="padding:8px;text-align:left;">Rang</th>'
            .'<th style="padding:8px;text-align:left;">Client</th>'
            .'<th style="padding:8px;text-align:left;">Compte</th>'
            .'<th style="padding:8px;text-align:left;">Etablissement</th>'
            .'<th style="padding:8px;text-align:left;">Pire tranche</th>'
            .'<th style="padding:8px;text-align:right;">Encours</th>'
            .'</tr></thead><tbody>'
            .'{% for tc in top %}<tr style="border-bottom:1px solid #e5e7eb;">'
            .'<td style="padding:8px;">{{ loop.index }}</td>'
            .'<td style="padding:8px;font-weight:600;">{{ tc.nom }}{% if tc.prenom %} {{ tc.prenom }}{% endif %}</td>'
            .'<td style="padding:8px;font-family:monospace;color:#6b7280;">{{ tc.compte }}</td>'
            .'<td style="padding:8px;color:#6b7280;">{{ tc.codeetab|default("—") }}</td>'
            .'<td style="padding:8px;">{{ tc.pire_tranche|default("—") }}</td>'
            .'<td style="padding:8px;text-align:right;font-weight:600;">{{ tc.encours|number_format(2, ",", " ") }} EUR</td>'
            .'</tr>{% endfor %}'
            .'</tbody></table>'
        )->render(['top' => $top]));
    }

    private function htmlIndicateurs(?string $libelle): string
    {
        $i = $this->indicateurs->syntheseGlobale();

        return $this->enveloppe($libelle ?? 'Indicateurs globaux', $this->twig->createTemplate(
            '<h2 style="color:#0e2540;">Indicateurs globaux</h2>'
            .'<p>Date du rapport : <strong>{{ "now"|date("d/m/Y") }}</strong></p>'
            .'<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">Encours total</td><td style="padding:8px;text-align:right;font-weight:600;">{{ i.encours_total|number_format(2, ",", " ") }} EUR</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">Encours echu</td><td style="padding:8px;text-align:right;color:#ea580c;">{{ i.encours_echu|number_format(2, ",", " ") }} EUR</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">Encours non echu</td><td style="padding:8px;text-align:right;">{{ i.encours_non_echu|number_format(2, ",", " ") }} EUR</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">Encaissement du mois</td><td style="padding:8px;text-align:right;color:#059669;">{{ i.encaissement_mois|number_format(2, ",", " ") }} EUR</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">DSO</td><td style="padding:8px;text-align:right;">{{ i.dso|number_format(1, ",", " ") }} jours</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">BPDSO</td><td style="padding:8px;text-align:right;">{{ i.bpdso|number_format(1, ",", " ") }} jours</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">Retard moyen</td><td style="padding:8px;text-align:right;">{{ i.retard_moyen_jours|number_format(0, ",", " ") }} jours</td></tr>'
            .'<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px;">Score sante (sur 10)</td><td style="padding:8px;text-align:right;font-weight:600;">{{ i.score|number_format(1, ",", " ") }}</td></tr>'
            .'<tr><td style="padding:8px;">Nombre de comptes</td><td style="padding:8px;text-align:right;">{{ i.nb_comptes }}</td></tr>'
            .'</table>'
        )->render(['i' => $i]));
    }

    private function htmlSynthese(?string $libelle): string
    {
        return $this->enveloppe(
            $libelle ?? 'Synthese recouvrement',
            $this->htmlIndicateurs(null).'<hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">'
            .$this->htmlBalanceAgee(null).'<hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">'
            .$this->htmlTop10(null),
            false,
        );
    }

    private function enveloppe(string $titre, string $contenu, bool $entete = true): string
    {
        if (!$entete) {
            return $contenu;
        }

        return '<table style="width:100%;font-family:Arial,sans-serif;max-width:700px;margin:0 auto;">'
            .'<tr><td style="padding:24px 0;border-bottom:2px solid #d4b16c;">'
            .'<strong style="font-size:18px;letter-spacing:1px;color:#0e2540;">GROUPE SYNTHAUTO</strong>'
            .'<p style="margin:4px 0 0;color:#6b7280;font-size:12px;">Service Comptabilite — Recouvrement</p>'
            .'</td></tr>'
            .'<tr><td style="padding:24px 0;">'.$contenu.'</td></tr>'
            .'<tr><td style="padding:16px 0;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center;">'
            .'Rapport automatique - Finance Créances - genere le '.date('d/m/Y à H:i')
            .'</td></tr>'
            .'</table>';
    }
}
