<?php

declare(strict_types=1);

namespace App\Remboursement\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

/**
 * Interrupteur de securite d'envoi PROPRE au module Remboursement, DISSOCIE de
 * Recouvrement.
 *
 * Si REMBOURSEMENT_FORCE_TO est rempli, redirige l'enveloppe SMTP des SEULS e-mails
 * Remboursement (marques par l'en-tete "X-Synthauto-Module: remboursement") vers cette
 * adresse, dans n'importe quel environnement (prod comprise). Permet de tester le
 * module en prod (mails chez soi) SANS toucher aux relances Recouvrement, qui ont leur
 * propre interrupteur (RECOUVREMENT_FORCE_TO). Vide => vraie production.
 *
 * Le forcage global (App\Recouvrement\EventListener\ForcerDestinataireMailer) ignore
 * ces e-mails (meme en-tete), ce qui garantit la dissociation.
 */
#[AsEventListener(event: MessageEvent::class)]
final class ForcerDestinataireRemboursement
{
    public function __construct(private readonly ?string $forceTo)
    {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Message) {
            return;
        }
        if ('remboursement' !== $message->getHeaders()->get('X-Synthauto-Module')?->getBodyAsString()) {
            return; // Pas un e-mail Remboursement : ne nous concerne pas.
        }

        $adresse = trim((string) $this->forceTo);
        if ('' === $adresse) {
            return; // Vraie production (vide/null) : on laisse les vrais destinataires.
        }

        // Redirige uniquement la livraison (RCPT TO), pas l'en-tete "To" affiche.
        $event->getEnvelope()->setRecipients([new Address($adresse)]);
    }
}
