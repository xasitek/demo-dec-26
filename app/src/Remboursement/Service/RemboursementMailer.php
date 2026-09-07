<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Repository\DossierPieceRepository;
use App\Remboursement\Repository\DossierRepository;
use App\Shared\Repository\EtablissementContactRepository;
use App\Shared\Repository\UserRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Twig\Environment;

/**
 * Envoi REEL des e-mails du module Remboursement (design deja valide) :
 *   - refus / correction -> la SECRETAIRE qui a depose (dossier.creePar) ;
 *   - validation -> le DIRECTEUR de l'etablissement, avec sa file (groupee par
 *     directeur) et des liens SIGNES (valider / refuser / tout valider / voir pieces).
 *
 * Best-effort : un envoi qui echoue ne casse jamais la transition metier (logue).
 */
final class RemboursementMailer
{
    private const FROM_EMAIL = 'copilote@demonstration.invalid';
    private const FROM_NOM = 'Remboursement client';
    /** Directeur du pole qui confirme les paiements. En dev, l'e-mail est redirige (ForcerDestinataireMailer). */
    private const DIRECTEUR_PAIEMENTS = 'directeur-comptable@demonstration.invalid';

    public function __construct(
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly UriSigner $signer,
        private readonly EtablissementContactRepository $contacts,
        private readonly DossierRepository $dossiers,
        private readonly DossierPieceRepository $pieces,
        private readonly AttestationRemboursement $attestation,
        private readonly LoggerInterface $logger,
        private readonly UserRepository $utilisateurs,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @var array<string, string> e-mail de la secretaire => nom affiche (memo d'envoi) */
    private array $deposants = [];

    public function refus(Dossier $dossier, string $motif): void
    {
        $to = trim((string) $dossier->getCreePar());
        if ('' === $to) {
            return;
        }
        $vars = $this->infos($dossier, 'Refusé le') + ['message' => $motif];
        $this->envoyer($to, sprintf('Dossier %s refusé', $this->client($dossier)), 'emails/remboursement/refus.html.twig', $vars, $dossier);
    }

    /**
     * @param list<string> $documents libelles des pieces a corriger (facultatif)
     */
    public function correction(Dossier $dossier, string $message, array $documents): void
    {
        $to = trim((string) $dossier->getCreePar());
        if ('' === $to) {
            return;
        }
        $vars = $this->infos($dossier, 'Correction demandée le') + [
            'message' => $message,
            'documents' => $documents,
            'lien_edition' => $this->urls->generate('app_remboursement_mon_dossier', ['id' => $dossier->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        $this->envoyer($to, sprintf('Dossier %s : correction demandée', $this->client($dossier)), 'emails/remboursement/correction.html.twig', $vars, $dossier);
    }

    /**
     * Previent la SECRETAIRE (dossier.creePar) que son dossier est valide et que le
     * remboursement partira en paiement sous 48h. Appele apres la generation des fichiers.
     */
    public function paiementEnCours(Dossier $dossier): void
    {
        $to = trim((string) $dossier->getCreePar());
        if ('' === $to) {
            return;
        }
        $vars = $this->infos($dossier, 'Validé le');

        // Attestation PDF (preuve pour le client), en piece jointe. Best-effort.
        $joints = [];
        try {
            $joints[] = [
                'nom' => sprintf('Attestation-%s.pdf', $dossier->getReference()),
                'contenu' => $this->attestation->pdf($dossier),
                'mime' => 'application/pdf',
            ];
        } catch (Throwable $e) {
            $this->logger->warning('Attestation PDF non generee : {message}', ['message' => $e->getMessage()]);
        }

        // Adresse saisie au depot, s'il y en a une : elle recoit la MEME piece jointe, une
        // copie suffit donc, sans second envoi a maintenir. C'est le SEUL e-mail du module
        // qui la met en copie — refus et demandes de correction restent internes.
        $this->envoyer($to, sprintf('Dossier %s validé', $this->client($dossier)), 'emails/remboursement/paiement_en_cours.html.twig', $vars, null, $joints, $dossier->getEmailCopie());
    }

    /**
     * Previent le directeur de l'etablissement du dossier : recap de SA file
     * (nouveau + autres en attente pour lui), liens signes. Rien si pas de directeur.
     */
    public function directeur(Dossier $nouveau): void
    {
        $code = trim((string) $nouveau->getEtablissementCode());
        $email = '' !== $code ? ($this->contacts->emailsActifs($code, 'directeur')[0] ?? null) : null;
        if (null === $email || '' === trim($email)) {
            $this->logger->warning('Remboursement : pas de directeur pour {code}, e-mail non envoye.', ['code' => $code]);

            return;
        }
        $email = trim($email);

        $map = $this->contacts->directeursParEtablissement();
        $autres = [];
        foreach ($this->dossiers->parStatut(DossierStatut::A_VALIDER_DIRECTEUR, 500) as $d) {
            if ($d->getId() === $nouveau->getId()) {
                continue;
            }
            $c = (string) $d->getEtablissementCode();
            if ('' !== $c && strtolower((string) ($map[$c] ?? '')) === strtolower($email)) {
                $autres[] = $this->ligneDirecteur($d);
            }
        }

        $vars = [
            'logo_src' => 'cid:logosynth',
            'nouveau' => $this->ligneDirecteur($nouveau),
            'autres' => $autres,
            'lien_tout_valider' => $this->signer->sign($this->urls->generate('app_remboursement_directeur_tout_valider', ['email' => $email], UrlGeneratorInterface::ABSOLUTE_URL)),
        ];
        $this->envoyer($email, sprintf('Dossier %s à valider', $this->client($nouveau)), 'emails/remboursement/validation_directeur.html.twig', $vars, null);
    }

    /**
     * Previent le DIRECTEUR DU POLE qu'un lot de paiements vient d'etre prepare
     * (fichiers SEPA/OD telecharges) par le manager, en attente de sa confirmation. Un
     * bouton mene au « Journal des paiements » ou il confirme (marque « Payé »). Envoye
     * une fois par jour (le telechargement est limite a 1x/jour).
     *
     * @param list<Dossier> $dossiers lot telecharge ce jour
     */
    public function paiementsPrepares(string $parNomComplet, array $dossiers): void
    {
        if ([] === $dossiers) {
            return;
        }
        $total = \count($dossiers);
        $montant = 0.0;
        foreach ($dossiers as $d) {
            $montant += (float) ($d->getValideMontant() ?: $d->getControleMontant() ?: $d->getMontant());
        }

        $vars = [
            'logo_src' => 'cid:logosynth',
            'par' => '' !== trim($parNomComplet) ? trim($parNomComplet) : 'Le service comptabilité',
            'total' => $total,
            'montant' => number_format($montant, 2, ',', ' '),
            'date' => (new DateTimeImmutable('today'))->format('d/m/Y'),
            'lien_journal' => $this->urls->generate('app_remboursement_paiements_journal', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        $sujet = 1 === $total
            ? '1 paiement en attente de votre confirmation'
            : sprintf('%d paiements en attente de votre confirmation', $total);
        $this->envoyer(self::DIRECTEUR_PAIEMENTS, $sujet, 'emails/remboursement/paiements_prepares.html.twig', $vars, null);
    }

    /**
     * Rappel quotidien a UNE secretaire : ses dossiers en correction requise (avec le
     * lien vers "mes dossiers") + ses dossiers en attente de validation directeur (pour
     * info, SANS bouton). Rien si les deux listes sont vides.
     *
     * @param list<Dossier> $corrections dossiers en correction requise deposes par elle
     * @param list<Dossier> $attente     dossiers en attente directeur deposes par elle
     */
    public function rappelSecretaire(string $to, array $corrections, array $attente): void
    {
        $to = trim($to);
        if ('' === $to || ([] === $corrections && [] === $attente)) {
            return;
        }

        $vars = [
            'logo_src' => 'cid:logosynth',
            'corrections' => array_map($this->ligneInfo(...), $corrections),
            'attente' => array_map($this->ligneInfo(...), $attente),
            'lien_mes_dossiers' => $this->urls->generate('app_remboursement_mes_dossiers', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        $this->envoyer($to, 'Rappel : vos dossiers de remboursement', 'emails/remboursement/rappel_secretaire.html.twig', $vars, null);
    }

    /**
     * Rappel quotidien a UN directeur : tous ses dossiers en attente de validation, avec
     * les liens signes (valider / refuser / voir pieces) et "tout valider". Rien si vide.
     *
     * @param list<Dossier> $dossiers dossiers en attente pour ce directeur
     */
    public function rappelDirecteur(string $email, array $dossiers): void
    {
        $email = trim($email);
        if ('' === $email || [] === $dossiers) {
            return;
        }

        $total = \count($dossiers);
        $vars = [
            'logo_src' => 'cid:logosynth',
            'dossiers' => array_map($this->ligneDirecteur(...), $dossiers),
            'total' => $total,
            'lien_tout_valider' => $this->signer->sign($this->urls->generate('app_remboursement_directeur_tout_valider', ['email' => $email], UrlGeneratorInterface::ABSOLUTE_URL)),
        ];
        $sujet = 1 === $total ? 'Rappel : 1 dossier à valider' : sprintf('Rappel : %d dossiers à valider', $total);
        $this->envoyer($email, $sujet, 'emails/remboursement/rappel_directeur.html.twig', $vars, null);
    }

    /**
     * Ligne "pour info" (sans lien d'action) utilisee dans le rappel secretaire.
     *
     * @return array<string, mixed>
     */
    private function ligneInfo(Dossier $d): array
    {
        return [
            'reference' => $d->getReference(),
            'client' => $this->client($d),
            'montant' => number_format((float) ($d->getValideMontant() ?: $d->getControleMontant() ?: $d->getMontant()), 2, ',', ' '),
            'motif' => $d->getMotif()->libelle(),
            'cleLabel' => DossierMotif::RACHAT_SEC === $d->getMotif() ? 'Immat.' : 'Code ICAR',
            'cle' => $this->cle($d),
            'depuis' => ($d->getDeposeLe() ?? $d->getCreeLe())->format('d/m/Y'),
        ];
    }

    /**
     * @param array<string, mixed>                                    $vars
     * @param list<array{nom: string, contenu: string, mime: string}> $fichiersJoints pieces jointes ad-hoc (ex. attestation PDF)
     */
    private function envoyer(string $to, string $sujet, string $template, array $vars, ?Dossier $avecPieces, array $fichiersJoints = [], ?string $copie = null): void
    {
        try {
            $email = (new Email())
                ->from(new Address(self::FROM_EMAIL, self::FROM_NOM))
                ->to(new Address($to))
                ->subject($sujet)
                ->html($this->twig->render($template, $vars));

            // Copie eventuelle. Verifiee ICI et pas seulement a la saisie : une adresse
            // invalide ferait lever Address et le catch plus bas avalerait TOUT l'envoi,
            // y compris le destinataire principal. Mieux vaut partir sans la copie et le
            // dire au journal. Un forcage REMBOURSEMENT_FORCE_TO reecrit l'enveloppe
            // entiere, copie comprise : rien ne fuit pendant les essais.
            $copie = trim((string) $copie);
            if ('' !== $copie) {
                if (filter_var($copie, \FILTER_VALIDATE_EMAIL)) {
                    $email->addCc(new Address($copie));
                } else {
                    $this->logger->warning('Adresse en copie ignoree (format invalide) pour {template}', ['template' => $template]);
                }
            }

            // Module Remboursement : transport dedie "copilote" (SMTP Gmail), isole du
            // Mailjet de Recouvrement (cf. config/packages/mailer.yaml).
            $email->getHeaders()->addTextHeader('X-Transport', 'copilote');
            // Marque le module : interrupteur d'envoi dedie (REMBOURSEMENT_FORCE_TO) et
            // le forcage global (Recouvrement) ignore ces e-mails -> les deux dissocies.
            $email->getHeaders()->addTextHeader('X-Synthauto-Module', 'remboursement');

            $logo = $this->projectDir.'/assets/images/logo-fc-automobile.jpg';
            if (is_file($logo)) {
                $email->embedFromPath($logo, 'logosynth', 'image/jpeg');
            }

            if ($avecPieces instanceof Dossier) {
                foreach ($this->pieces->pourDossier($avecPieces) as $piece) {
                    $octets = $piece->getContenu();
                    if ('' !== $octets) {
                        $email->attach($octets, $piece->getNomFichier(), $piece->getMimeType());
                    }
                }
            }

            foreach ($fichiersJoints as $joint) {
                $email->attach($joint['contenu'], $joint['nom'], $joint['mime']);
            }

            $this->mailer->send($email);
        } catch (Throwable $e) {
            $this->logger->error('Envoi e-mail remboursement echoue : {message}', ['message' => $e->getMessage(), 'to' => $to, 'template' => $template]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function infos(Dossier $dossier, string $actionLabel): array
    {
        $rachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();

        return [
            'logo_src' => 'cid:logosynth',
            'reference' => $dossier->getReference(),
            'client' => $this->client($dossier),
            'motif' => $dossier->getMotif()->libelle(),
            'cleLabel' => $rachat ? 'Immatriculation' : 'Code ICAR',
            'cle' => $this->cle($dossier),
            'montant' => number_format((float) ($dossier->getValideMontant() ?: $dossier->getControleMontant() ?: $dossier->getMontant()), 2, ',', ' '),
            'dateDepot' => ($dossier->getDeposeLe() ?? $dossier->getCreeLe())->format('d/m/Y'),
            'dateActionLabel' => $actionLabel,
            'dateAction' => (new DateTimeImmutable())->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneDirecteur(Dossier $d): array
    {
        $id = (int) $d->getId();

        return [
            'reference' => $d->getReference(),
            'client' => $this->client($d),
            'montant' => number_format((float) ($d->getValideMontant() ?: $d->getControleMontant() ?: $d->getMontant()), 2, ',', ' '),
            'motif' => $d->getMotif()->libelle(),
            'cleLabel' => DossierMotif::RACHAT_SEC === $d->getMotif() ? 'Immat.' : 'Code ICAR',
            'cle' => $this->cle($d),
            'secretaire' => $this->deposant($d),
            'lien_valider' => $this->signer->sign($this->urls->generate('app_remboursement_directeur_valider', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL)),
            'lien_refuser' => $this->signer->sign($this->urls->generate('app_remboursement_directeur_refuser', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL)),
            'lien_pieces' => $this->signer->sign($this->urls->generate('app_remboursement_directeur_pieces', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL)),
        ];
    }

    /**
     * Qui a depose le dossier, sous une forme lisible par le directeur : son nom complet
     * si la secretaire a un compte, son e-mail sinon (dossier importe, compte supprime).
     * Les noms sont memoises pour la duree de l'envoi : la file du directeur affiche
     * souvent plusieurs dossiers de la MEME secretaire, une requete suffit.
     */
    private function deposant(Dossier $d): string
    {
        $email = strtolower(trim((string) $d->getCreePar()));
        if ('' === $email) {
            return '';
        }

        return $this->deposants[$email] ??= trim($this->utilisateurs->findOneByEmail($email)?->getFullName() ?? '') ?: $email;
    }

    private function client(Dossier $d): string
    {
        $c = trim((string) ($d->getValideNom() ?: $d->getControleNom() ?: $d->getNomClient()));

        return '' !== $c ? $c : '—';
    }

    private function cle(Dossier $d): string
    {
        if (DossierMotif::RACHAT_SEC === $d->getMotif()) {
            return (string) ($d->getValideImmatriculation() ?: $d->getControleImmatriculation() ?: $d->getImmatriculation());
        }

        return (string) ($d->getValideIcar() ?: $d->getControleIcar() ?: $d->getCodeIcar());
    }
}
