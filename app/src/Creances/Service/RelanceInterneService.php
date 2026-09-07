<?php

declare(strict_types=1);

namespace App\Creances\Service;

use App\Creances\Entity\Action;
use App\Creances\Enum\ActionType;
use App\Creances\Repository\ActionRepository;
use App\Creances\Repository\CreancesRepository;
use App\Shared\Entity\User;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Envoi de relance INTERNE : on ne contacte pas le client, on alerte
 * l'equipe Synthauto qui le connait (secretaire qui a saisi la facture,
 * vendeur, directeur d'etablissement). Plus efficace qu'une relance
 * client a froid car le commercial connait son client.
 *
 * Le service :
 *  - liste les destinataires internes connus pour un compte (e-mail
 *    secretaire issu de Progiciel, vendeur, eventuellement directeur si on
 *    a un annuaire interne en place)
 *  - genere un mail synthese (vehicule, montant, anciennete, lien fiche)
 *  - envoie via Symfony Mailer et trace une `Action` dans la fiche tiers
 *    pour conservaer l'historique
 */
final class RelanceInterneService
{
    public function __construct(
        private readonly CreancesRepository $creances,
        private readonly ActionRepository $actions,
        private readonly MailerInterface $mailer,
        private readonly RecouvrementNotifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly string $expediteur = 'recouvrement@demonstration.invalid',
        private readonly string $urlAppPrefix = 'https://fc-finance',
    ) {
    }

    /**
     * Renvoie les destinataires internes deja connus pour un compte
     * (emails extraits du mirror Progiciel : secretaire ayant saisi les
     * ecritures).
     *
     * @return list<array{role: string, nom: string, email: string}>
     */
    public function destinatairesConnus(string $compteCode): array
    {
        $ecritures = $this->creances->creancesOuvertesDuTiers($compteCode);
        $vus = [];
        $out = [];
        foreach ($ecritures as $row) {
            $d = is_array($row['donnees']) ? $row['donnees'] : (is_string($row['donnees']) ? (json_decode($row['donnees'], true) ?: []) : []);
            $email = isset($d['emailsecr']) && is_string($d['emailsecr']) ? trim($d['emailsecr']) : '';
            if ('' !== $email && filter_var($email, \FILTER_VALIDATE_EMAIL) && !isset($vus[$email])) {
                $vus[$email] = true;
                $nom = (string) ($d['nomsecretaire'] ?? $email);
                $out[] = ['role' => 'Secretaire', 'nom' => $nom, 'email' => $email];
            }
        }

        return $out;
    }

    /**
     * Envoie un mail interne aux destinataires choisis + cree une action
     * de tracabilite dans la fiche tiers.
     *
     * @param list<string> $emails
     *
     * @return array{envoyes: int, erreurs: int}
     */
    public function alerter(string $compteCode, array $emails, string $commentaire, ?User $auteur): array
    {
        $contexte = $this->preparerContexte($compteCode);
        $sujet = sprintf('[Recouvrement] Compte %s en retard - %s EUR', $compteCode, number_format($contexte['encours'], 0, ',', ' '));
        $corps = $this->corpsHtml($compteCode, $contexte, $commentaire, $auteur);

        $envoyes = 0;
        $erreurs = 0;
        $destinatairesValides = [];
        foreach ($emails as $email) {
            $email = trim($email);
            if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $destinatairesValides[] = $email;
            try {
                $mail = (new Email())
                    ->from($this->expediteur)
                    ->to($email)
                    ->subject($sujet)
                    ->html($corps);
                if (null !== $auteur && '' !== $auteur->getEmail()) {
                    $mail->replyTo($auteur->getEmail());
                }
                $this->mailer->send($mail);
                ++$envoyes;
            } catch (Throwable $e) {
                $this->logger->warning('Echec relance interne pour {compte} vers {email} : {message}', [
                    'compte' => $compteCode,
                    'email' => $email,
                    'message' => $e->getMessage(),
                ]);
                ++$erreurs;
            }
        }

        if (count($destinatairesValides) > 0) {
            $action = new Action(
                $compteCode,
                ActionType::Email,
                'Alerte equipe interne',
                new DateTimeImmutable('today'),
                $auteur,
                null,
                null,
                'Destinataires : '.implode(', ', $destinatairesValides)."\n\n".$commentaire,
            );
            $this->actions->save($action);

            $this->notifier->notifierTiers($compteCode, 'alerte_interne', [
                'destinataires' => count($destinatairesValides),
                'envoyes' => $envoyes,
                'erreurs' => $erreurs,
            ]);
        }

        return ['envoyes' => $envoyes, 'erreurs' => $erreurs];
    }

    /**
     * @return array{tiers: array<string, mixed>, encours: float, ecritures: list<array<string, mixed>>, anciennete_max: string}
     */
    private function preparerContexte(string $compteCode): array
    {
        $tiers = $this->creances->tiers($compteCode);
        $d = [];
        if (null !== $tiers) {
            $brut = $tiers['donnees'] ?? null;
            $d = is_array($brut) ? $brut : (is_string($brut) ? (json_decode($brut, true) ?: []) : []);
        }
        $ecritures = $this->creances->creancesOuvertesDuTiers($compteCode);
        $encours = 0.0;
        $piresOrdre = ['>240' => 7, '>180' => 6, '>120' => 5, '>90' => 4, '>60' => 3, '>30' => 2, '<30' => 1];
        $pireTranche = '';
        $rangPire = -1;
        $ecrituresMappees = [];
        foreach ($ecritures as $row) {
            $ee = is_array($row['donnees']) ? $row['donnees'] : (is_string($row['donnees']) ? (json_decode($row['donnees'], true) ?: []) : []);
            $montant = (float) ($ee['Montant (valeur absolue)'] ?? 0);
            $encours += $montant;
            $tranche = (string) ($ee['retard'] ?? '');
            $rang = $piresOrdre[$tranche] ?? -1;
            if ($rang > $rangPire) {
                $rangPire = $rang;
                $pireTranche = $tranche;
            }
            $ecrituresMappees[] = [
                'numpiece' => (string) ($ee['numpiece'] ?? ''),
                'date' => isset($ee['dateecriture']) && is_string($ee['dateecriture']) ? substr($ee['dateecriture'], 0, 10) : '',
                'montant' => $montant,
                'retard' => $tranche,
                'marque' => (string) ($ee['marque'] ?? ''),
                'numimmat' => (string) ($ee['numimmat'] ?? ''),
                'nomvendeur' => (string) ($ee['nomvendeur'] ?? ''),
            ];
        }

        return [
            'tiers' => $d,
            'encours' => $encours,
            'ecritures' => $ecrituresMappees,
            'anciennete_max' => $pireTranche,
        ];
    }

    /**
     * @param array{tiers: array<string, mixed>, encours: float, ecritures: list<array<string, mixed>>, anciennete_max: string} $contexte
     */
    private function corpsHtml(string $compteCode, array $contexte, string $commentaire, ?User $auteur): string
    {
        $tiers = $contexte['tiers'];
        $nom = trim(((string) ($tiers['prenom'] ?? '')).' '.((string) ($tiers['nom'] ?? '')));
        $auteurNom = null !== $auteur ? trim($auteur->getFirstName().' '.$auteur->getLastName()) : 'Service Comptabilite';

        $ecrituresHtml = '';
        foreach ($contexte['ecritures'] as $e) {
            $ecrituresHtml .= sprintf(
                '<tr style="border-top:1px solid #e5e7eb;"><td style="padding:6px 8px;">%s</td><td style="padding:6px 8px;">%s</td><td style="padding:6px 8px;">%s%s</td><td style="padding:6px 8px;">%s</td><td style="padding:6px 8px;text-align:right;font-weight:600;">%s EUR</td></tr>',
                htmlspecialchars($e['numpiece'], \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($e['date'], \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($e['marque'], \ENT_QUOTES, 'UTF-8'),
                $e['numimmat'] ? ' / '.htmlspecialchars($e['numimmat'], \ENT_QUOTES, 'UTF-8') : '',
                htmlspecialchars($e['retard'] ?: '—', \ENT_QUOTES, 'UTF-8'),
                number_format($e['montant'], 2, ',', ' '),
            );
        }

        $lien = $this->urlAppPrefix.'/creances/tiers/'.$compteCode;
        $commentaireEch = nl2br(htmlspecialchars($commentaire, \ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<table style="width:100%;max-width:700px;font-family:Arial,sans-serif;color:#0e2540;">
  <tr><td style="padding:24px 0;border-bottom:2px solid #d4b16c;">
    <strong style="font-size:18px;letter-spacing:1px;">GROUPE SYNTHAUTO</strong>
    <p style="margin:4px 0 0;color:#6b7280;font-size:12px;">Alerte interne — Recouvrement</p>
  </td></tr>
  <tr><td style="padding:24px 0;">
    <p>Bonjour,</p>
    <p>Le compte <strong>{$compteCode}</strong> ({$nom}) presente un retard. Tu connais ce client : peux-tu le contacter pour qu'il regularise ?</p>

    <p><strong>Encours total :</strong> <span style="font-size:18px;color:#0e2540;">{$contexte['encours']} EUR</span><br>
    <strong>Anciennete max :</strong> {$contexte['anciennete_max']}</p>

    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
      <thead><tr style="background:#f3f4f6;">
        <th style="padding:8px;text-align:left;">Piece</th>
        <th style="padding:8px;text-align:left;">Date</th>
        <th style="padding:8px;text-align:left;">Vehicule</th>
        <th style="padding:8px;text-align:left;">Retard</th>
        <th style="padding:8px;text-align:right;">Montant</th>
      </tr></thead>
      <tbody>{$ecrituresHtml}</tbody>
    </table>

    <div style="margin-top:24px;padding:12px;background:#f3f4f6;border-left:3px solid #d4b16c;">
      <p style="margin:0 0 8px;font-weight:600;color:#0e2540;">Commentaire :</p>
      <p style="margin:0;color:#0e2540;">{$commentaireEch}</p>
    </div>

    <p style="margin-top:24px;">
      <a href="{$lien}" style="display:inline-block;padding:10px 20px;background:#0e2540;color:white;text-decoration:none;border-radius:4px;font-weight:600;">Voir la fiche complete</a>
    </p>

    <p style="margin-top:32px;color:#6b7280;font-size:13px;">
      {$auteurNom}<br>
      Service Comptabilite — Recouvrement
    </p>
  </td></tr>
</table>
HTML;
    }
}
