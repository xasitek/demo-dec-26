<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Message\GenererFichiers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION — la porte de l'anti-rejeu.
 *
 * Ce middleware N'EXISTE PAS dans le module historique.
 *
 * Pourquoi ici. Le service de generation est `final`, et un renforcement ne
 * justifie pas de le rouvrir. Le bus de messages, lui, est un point d'entree
 * prevu pour cela : toute demande de generation y passe, qu'elle vienne du lien
 * signe du directeur, d'une reprise, ou d'un appel manuel. Une demande dont le
 * paiement porte deja une empreinte n'atteint donc jamais le handler -- elle
 * n'est meme pas mise en file.
 *
 * Ce qu'il fait exactement, et ce qu'il ne fait pas. Il REFUSE la generation et
 * TRACE la tentative ; il ne rejoue rien, ne regenere rien, ne renvoie pas un
 * nouveau fichier. Le fichier existant reste le seul, avec son MsgId et son
 * condensat d'origine. Et il ne remplace pas la garde de statut du module :
 * celle-ci reste en place et travaille avant lui dans le parcours normal.
 */
final readonly class MiddlewareAntiRejeu implements MiddlewareInterface
{
    public function __construct(
        private GardeAntiRejeu $garde,
        private LoggerInterface $journal,
    ) {
    }

    public function handle(Envelope $enveloppe, StackInterface $pile): Envelope
    {
        $message = $enveloppe->getMessage();
        if (!$message instanceof GenererFichiers) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        $empreinte = $this->garde->empreinte($message->dossierId);
        if (null === $empreinte) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        // Le paiement existe : la demande s'arrete ici. On ne la transmet pas.
        $texte = $this->garde->tracerRejeu($empreinte, 'bus-de-messages');
        $this->journal->warning('Anti-rejeu : {message}', [
            'message' => $texte,
            'dossier' => $empreinte['reference'],
        ]);

        return $enveloppe;
    }
}
