<?php

declare(strict_types=1);

namespace App\Recouvrement\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

/**
 * Interrupteur de securite d'envoi (mode pre-prod / staging).
 *
 * Si RECOUVREMENT_FORCE_TO est rempli, redirige l'ENVELOPPE SMTP de tout email
 * sortant vers cette seule adresse, dans N'IMPORTE QUEL environnement (y compris
 * prod). L'en-tete "To" affiche reste le vrai destinataire : on voit a qui le
 * message etait destine, mais il n'est livre qu'a l'adresse de test.
 *
 * Permet de faire tourner le worker sur le serveur interne en conditions reelles
 * (APP_ENV=prod, vraie base, vrai Progiciel, vrai SMTP) SANS jamais contacter un vrai
 * client, tant que la variable n'est pas videe. Vide => vraie production.
 *
 * Liste blanche (MAIL_PASSTHROUGH) : adresses INTERNES livrees telles quelles
 * meme en mode redirige. Un message n'est laisse passer que si TOUS ses vrais
 * destinataires y figurent (sinon redirection vers FORCE_TO : on ne fuit jamais
 * vers une adresse non listee). Permet, en dev, d'envoyer les e-mails secretaire
 * a la vraie boite relances@ tout en redirigeant les e-mails directeur vers la
 * boite de test.
 */
#[AsEventListener(event: MessageEvent::class)]
final class ForcerDestinataireMailer
{
    /** @var list<string> adresses internes (minuscules) livrees telles quelles */
    private readonly array $passthrough;

    public function __construct(private readonly string $forceTo, string $passthrough = '')
    {
        $this->passthrough = array_values(array_filter(array_map(
            static fn (string $a): string => strtolower(trim($a)),
            explode(',', $passthrough),
        )));
    }

    public function __invoke(MessageEvent $event): void
    {
        // Les e-mails du module Remboursement gerent leur PROPRE interrupteur
        // (ForcerDestinataireRemboursement / REMBOURSEMENT_FORCE_TO) : le forcage global
        // les ignore, pour que les deux modules soient dissocies.
        $message = $event->getMessage();
        if ($message instanceof Message && 'remboursement' === $message->getHeaders()->get('X-Synthauto-Module')?->getBodyAsString()) {
            return;
        }

        $adresse = trim($this->forceTo);
        if ('' === $adresse) {
            return; // Vraie production : on laisse les vrais destinataires.
        }

        // Liste blanche : si tous les vrais destinataires sont internes, on livre
        // sans rien changer (ex. e-mails secretaire vers relances@ en dev).
        $reels = $event->getEnvelope()->getRecipients();
        if ([] !== $reels && [] !== $this->passthrough) {
            $tousInternes = true;
            foreach ($reels as $r) {
                if (!in_array(strtolower($r->getAddress()), $this->passthrough, true)) {
                    $tousInternes = false;
                    break;
                }
            }
            if ($tousInternes) {
                return;
            }
        }

        // Redirige uniquement la livraison (RCPT TO), pas l'en-tete To affiche.
        $event->getEnvelope()->setRecipients([new Address($adresse)]);
    }
}
