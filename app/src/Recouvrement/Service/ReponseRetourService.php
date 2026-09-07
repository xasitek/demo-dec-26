<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\MessageSortant;
use App\Recouvrement\Entity\RetourClient;
use App\Recouvrement\Repository\MessageSortantRepository;
use App\Recouvrement\Repository\RetourClientRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Envoie la réponse d'un comptable à un retour client, puis clôture le retour.
 *
 * Le mail part vers l'adresse du client (expéditeur du retour, ou à défaut le
 * destinataire de la relance d'origine), avec le From et le Reply-To du service
 * recouvrement. Threading via In-Reply-To/References sur le Message-Id du client.
 * Pièce jointe optionnelle.
 *
 * En dev/test, l'override d'enveloppe (config/packages/mailer.yaml) force tout le
 * courrier vers l'adresse interne : aucun vrai client n'est touché.
 */
final class ReponseRetourService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RetourClientRepository $retours,
        private readonly MessageSortantRepository $messagesSortants,
        private readonly Environment $twig,
        private readonly LogoRecouvrement $logo,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $replyTo,
    ) {
    }

    /**
     * Envoie la réponse et clôture le retour (traité + commentaire = la réponse).
     *
     * @param list<UploadedFile> $pieces pièces jointes (déjà validées par l'appelant)
     * @param list<string>       $cc     adresses en copie (déjà validées)
     * @param list<string>       $cci    adresses en copie cachée (déjà validées)
     *
     * @throws RuntimeException si aucune adresse client n'est connue
     */
    public function repondre(RetourClient $retour, string $message, array $pieces, ?string $auteur, array $cc = [], array $cci = []): void
    {
        $destinataire = $this->destinataire($retour);
        if (null === $destinataire) {
            throw new RuntimeException('Aucune adresse client connue pour répondre à ce retour.');
        }

        $sujet = $this->sujetReponse($retour->getSujet());
        $reference = trim((string) $retour->getCompteCode());
        $corps = $this->twig->render('recouvrement/email/reponse.html.twig', [
            'message' => $message,
            'auteur' => $auteur,
            'reference' => '' !== $reference ? $reference : null,
            // Logo en CID (comme les relances) : Gmail affiche le CID, bloque les data-URI.
            'logo_src' => $this->logo->srcCid(),
        ]);

        // Token de la relance d'origine : on le propage dans la réponse (en-tête
        // X-Recouvrement-Token + threading In-Reply-To) pour qu'une RE-réponse du
        // client se rattache à la même conversation. À défaut, repli matching email.
        $token = $retour->getRelanceEnvoi()?->getToken();

        $email = (new Email())
            ->from(new Address($this->fromEmail, 'Service recouvrement'))
            ->to($destinataire)
            ->replyTo(new Address($this->replyTo))
            ->subject($sujet)
            ->html($corps);

        // Logo SYNTHAUTO embarque en inline (CID), reference par l'en-tete du layout.
        if (is_file($this->logo->chemin())) {
            $email->embedFromPath($this->logo->chemin(), LogoRecouvrement::CID, 'image/png');
        }

        foreach ($cc as $adresse) {
            $email->addCc($adresse);
        }
        foreach ($cci as $adresse) {
            $email->addBcc($adresse);
        }

        $headers = $email->getHeaders();

        // Transport dedie Mailjet, comme les relances.
        $headers->addTextHeader('X-Transport', 'relances');

        // Threading : rattacher la réponse au fil du message du client.
        $messageId = $retour->getMessageId();
        if (null !== $messageId && '' !== trim($messageId)) {
            $headers->addTextHeader('In-Reply-To', $messageId);
            $headers->addTextHeader('References', $messageId);
        }

        // En-tête token : double sécurité pour relier les re-réponses.
        if (null !== $token && '' !== $token) {
            $headers->addTextHeader('X-Recouvrement-Token', $token);
        }

        foreach ($pieces as $piece) {
            $email->attach(
                (string) file_get_contents($piece->getPathname()),
                $piece->getClientOriginalName(),
                $piece->getMimeType() ?: 'application/octet-stream',
            );
        }

        $this->mailer->send($email);

        // Journal du message sortant : pour l'historique des échanges (avec les
        // noms des pièces jointes).
        $journal = new MessageSortant($destinataire, new DateTimeImmutable());
        $journal->setRetourId($retour->getId());
        $journal->setCompteCode($retour->getCompteCode());
        $journal->setEcritureId($retour->getEcritureId());
        $journal->setSujet($sujet);
        $journal->setCorpsHtml($corps);
        $journal->setCc([] !== $cc ? implode(', ', $cc) : null);
        $journal->setCci([] !== $cci ? implode(', ', $cci) : null);
        $journal->setPiecesJointes(array_map(
            static fn (UploadedFile $piece): string => $piece->getClientOriginalName(),
            $pieces,
        ));
        $journal->setAuteur($auteur);
        $this->messagesSortants->save($journal);

        // Clôture du retour, avec trace de la réponse envoyée.
        $retour->setTraite(true);
        $retour->setTraitePar($auteur);
        $retour->setTraiteLe(new DateTimeImmutable());
        $retour->setCommentaireTraitement('Réponse envoyée à '.$destinataire." :\n".$message);
        $this->retours->save($retour);

        $this->logger->info('Réponse à un retour client envoyée', [
            'retour' => $retour->getId(),
            'destinataire' => $destinataire,
            'pieces' => \count($pieces),
        ]);
    }

    /**
     * Adresse de réponse : l'expéditeur du retour, sinon le destinataire de la
     * relance d'origine. null si aucune des deux n'est exploitable.
     */
    private function destinataire(RetourClient $retour): ?string
    {
        $expediteur = trim((string) $retour->getExpediteur());
        if ('' !== $expediteur) {
            return $expediteur;
        }

        $relance = $retour->getRelanceEnvoi();
        if (null !== $relance) {
            $destinataire = trim((string) $relance->getDestinataire());
            if ('' !== $destinataire) {
                return $destinataire;
            }
        }

        return null;
    }

    /**
     * Sujet de la réponse : "Re: <sujet du retour>", sans empiler les "Re:".
     */
    private function sujetReponse(?string $sujet): string
    {
        $base = trim((string) $sujet);
        if ('' === $base) {
            return 'Votre message - Service recouvrement Groupe Synthauto';
        }

        return 1 === preg_match('/^re\s*:/i', $base) ? $base : 'Re: '.$base;
    }
}
