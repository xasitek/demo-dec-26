<?php

declare(strict_types=1);

namespace App\Recouvrement\MessageHandler;

use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Enum\StatutCourrier;
use App\Recouvrement\Enum\TypeCourrier;
use App\Recouvrement\Message\EnvoyerCourrierPostal;
use App\Recouvrement\Postal\CourrierAEnvoyer;
use App\Recouvrement\Postal\CourrierPostalSender;
use App\Recouvrement\Repository\RelanceEnvoiRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Depose un courrier chez le prestataire postal (via CourrierPostalSender) pour une
 * relance de vecteur COURRIER, puis enregistre le suivi (reference, statut) sur le
 * RelanceEnvoi. Le produit depend du niveau : mise en demeure -> recommande avec AR,
 * sinon lettre simple.
 *
 * Tant que le prestataire configure est le SIMULATEUR, rien n'est reellement envoye
 * (garde-fou). Idempotence : une relance deja envoyee (statut ENVOYE + reference
 * courrier) n'est pas re-deposee.
 */
#[AsMessageHandler]
final class EnvoyerCourrierPostalHandler
{
    public function __construct(
        private readonly RelanceEnvoiRepository $relances,
        private readonly CourrierPostalSender $sender,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnvoyerCourrierPostal $message): void
    {
        $relance = $this->relances->find($message->relanceId);
        if (null === $relance) {
            $this->logger->warning('Courrier : relance introuvable', ['id' => $message->relanceId]);

            return;
        }

        if (RelanceVecteur::COURRIER !== $relance->getVecteur()) {
            $this->logger->warning('Courrier : relance non-COURRIER ignoree', ['id' => $message->relanceId]);

            return;
        }

        // Idempotence : deja depose chez un prestataire -> on ne renvoie pas.
        if (null !== $relance->getCourrierRef()) {
            return;
        }

        $pdf = $relance->getCourrierPdf();
        if (null === $pdf || '' === $pdf) {
            // Le PDF fusionne (releve + factures) n'a pas ete genere : impossible d'envoyer.
            $relance->enregistrerDepotCourrier($this->sender->nom(), null, StatutCourrier::ERREUR);
            $relance->setStatut(RelanceStatut::ECHEC);
            $relance->setErreurMessage('Courrier sans PDF fusionne (generer-courriers non passe).');
            $this->em->flush();
            $this->logger->warning('Courrier sans PDF fusionne', ['id' => $message->relanceId]);

            return;
        }

        $type = $relance->isMiseEnDemeure() ? TypeCourrier::RECOMMANDE : TypeCourrier::LETTRE;

        $resultat = $this->sender->envoyer(new CourrierAEnvoyer(
            $pdf,
            $message->destinataireNom,
            $message->adresse,
            $type,
            $relance->getCompteCode().' N'.$relance->getNiveau(),
        ));

        $relance->setCourrierType($type);
        $relance->enregistrerDepotCourrier($resultat->prestataire, $resultat->reference, $resultat->statut);

        if (StatutCourrier::ERREUR === $resultat->statut) {
            $relance->setStatut(RelanceStatut::ECHEC);
            $relance->setErreurMessage($resultat->erreur ?? 'Echec du depot du courrier.');
            $this->logger->error('Courrier : depot en echec', [
                'id' => $message->relanceId,
                'prestataire' => $resultat->prestataire,
                'erreur' => $resultat->erreur,
            ]);
        } else {
            // Depose chez le prestataire = envoye de notre point de vue (le suivi
            // postal, lui, evoluera depose -> poste -> distribue via la commande de suivi).
            $relance->setStatut(RelanceStatut::ENVOYE);
            $relance->setEnvoyeLe(new DateTimeImmutable());
        }

        $this->em->flush();
    }
}
