<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\ControleBuyBack;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * RENFORCEMENT DE LA COPIE DE DEMONSTRATION — le surpaiement rebarre avant le paiement.
 *
 * CE QUE LE MODULE HISTORIQUE FAIT, ET OU IL S'ARRETE. Le controle
 * anti-surpaiement Buy Back existe, il est reel, et il bloque durement : un
 * depot dont le montant depasse l'engagement de reprise de plus de trois euros
 * est refuse par le formulaire de la secretaire. Mais il vit UNIQUEMENT a la
 * porte du depot. Un dossier entre autrement -- import, reprise, dossier
 * depose avant que l'engagement soit connu -- n'est plus jamais confronte a son
 * contrat avant que l'argent ne parte.
 *
 * C'est un vrai trou, et il est facile a nommer : le controle est a l'entree,
 * pas devant la caisse.
 *
 * CE QUE LE RENFORCEMENT AJOUTE. Le meme service, `ControleBuyBack`, avec le
 * meme seuil, rejoue juste avant la generation du paiement. Un surpaiement ne
 * peut donc pas atteindre le fichier SEPA, quelle que soit la porte par
 * laquelle le dossier est entre. La demande est refusee et TRACEE.
 *
 * Aucune regle n'est inventee : le seuil, la comparaison et la notion de
 * surpaiement viennent du code herite. Ce qui est ajoute, c'est le MOMENT.
 */
final readonly class MiddlewareGardeSurpaiement implements MiddlewareInterface
{
    /** Ce que le renforcement repond quand le contrat interdit le paiement. */
    public const MESSAGE = 'PAIEMENT BLOQUÉ — SURPAIEMENT DE L\'ENGAGEMENT DE REPRISE';

    public function __construct(
        private DossierRepository $dossiers,
        private ControleBuyBack $controle,
        private Connection $cnx,
        private LoggerInterface $journal,
    ) {
    }

    public function handle(Envelope $enveloppe, StackInterface $pile): Envelope
    {
        $message = $enveloppe->getMessage();
        if (!$message instanceof GenererFichiers) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        $dossier = $this->dossiers->find($message->dossierId);
        if (null === $dossier || DossierMotif::RACHAT_SEC !== $dossier->getMotif()) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        // Le montant confronte est celui qui PARTIRAIT : la valeur retenue par
        // le comptable, la saisie a defaut.
        $montant = (float) ($dossier->getValideMontant() ?: $dossier->getMontant());
        $c = $this->controle->pour($dossier->getImmatriculation(), $montant);
        if (null === $c || true !== $c['surpaiement']) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        $texte = sprintf(
            '%s — demande %s €, engagement %s €, ecart %s €, tolerance %s €',
            self::MESSAGE,
            number_format($montant, 2, ',', ' '),
            number_format($c['erTtc'], 2, ',', ' '),
            number_format((float) $c['ecart'], 2, ',', ' '),
            number_format(ControleBuyBack::SEUIL, 2, ',', ' '));

        $this->cnx->insert('remboursement.rejeu_evenement', [
            'cle_fonctionnelle' => hash('sha256', 'surpaiement|'.$dossier->getReference()),
            'dossier_id' => (int) $dossier->getId(),
            'reference' => (string) $dossier->getReference(),
            'source' => 'garde-surpaiement',
            'decision' => 'refuse_surpaiement',
            'message' => $texte,
        ]);

        $this->journal->error('Garde surpaiement : {message}', [
            'message' => $texte, 'dossier' => $dossier->getReference(),
        ]);

        return $enveloppe;
    }
}
