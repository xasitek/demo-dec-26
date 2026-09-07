<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use App\Remboursement\Message\GenererFichiers;
use App\Remboursement\Repository\DossierRepository;
use App\Shared\Repository\EtablissementRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * R04, SECOND ETAGE — le garde-fou de dernier ressort, devant la caisse.
 *
 * POURQUOI DEUX ETAGES. Le controle a l'entree protege les dossiers qui entrent
 * par le formulaire. Il ne protege pas les autres : un dossier depose avant ce
 * renforcement, une reprise, un appel technique. Une validation de formulaire
 * n'est pas une defense — c'est une politesse faite a celui qui saisit. La
 * defense, elle, se pose la ou l'argent part.
 *
 * CE QU'IL VERIFIE. Les DEUX IBAN qui figureront dans le virement : celui du
 * beneficiaire, tel qu'il partirait — la valeur retenue par le comptable, la
 * saisie a defaut — et celui de l'etablissement debiteur. Si l'un des deux
 * porte une cle de controle fausse, la demande n'atteint pas le handler : aucun
 * XML, aucun fichier de paiement, aucun CSV comptable, et la tentative est
 * TRACEE avec son motif.
 *
 * CE QU'IL NE PRETEND PAS. Il ne protege pas un appel direct au service de
 * generation qui contournerait le bus : la formule exacte est « garde-fou sur
 * le circuit applicatif normal de generation via la file de messages ». Il ne
 * remplace aucun controle herite : C42 et C44 restent en place et refusent
 * toujours ce qui est hors structure.
 *
 * IL N'A JAMAIS EXISTE DANS LE MODULE HISTORIQUE.
 */
final readonly class MiddlewareGardeIbanMod97 implements MiddlewareInterface
{
    public function __construct(
        private DossierRepository $dossiers,
        private EtablissementRepository $etablissements,
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
        if (null === $dossier) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        $beneficiaire = (string) ($dossier->getValideIban() ?: $dossier->getIbanClient());
        // L'etablissement debiteur est resolu comme le fait le service de
        // generation lui-meme : meme depot, meme clef. Un IBAN debiteur absent
        // n'est pas l'affaire de R04 — le module herite le refuse deja.
        $code = trim((string) $dossier->getEtablissementCode());
        $etab = '' !== $code ? $this->etablissements->findOneBy(['codeEtab' => $code]) : null;
        $debiteur = (string) $etab?->getIban();

        $fautes = [];
        if (null !== ControleIbanMod97::reproche($beneficiaire)) {
            $fautes[] = 'beneficiaire';
        }
        if (null !== ControleIbanMod97::reproche($debiteur)) {
            $fautes[] = 'debiteur';
        }
        if ([] === $fautes) {
            return $pile->next()->handle($enveloppe, $pile);
        }

        $texte = sprintf('%s — %s (%s : %s)',
            ControleIbanMod97::CODE,
            ControleIbanMod97::MESSAGE,
            implode(' et ', $fautes),
            \count($fautes) > 1 ? 'les deux IBAN' : 'un IBAN');

        $this->cnx->insert('remboursement.rejeu_evenement', [
            'cle_fonctionnelle' => hash('sha256', 'iban-mod97|'.$dossier->getReference()),
            'dossier_id' => (int) $dossier->getId(),
            'reference' => (string) $dossier->getReference(),
            'source' => 'garde-iban-mod97',
            'decision' => 'refuse_cle_iban',
            'message' => $texte,
        ]);

        $this->journal->error('Garde IBAN MOD 97 : {message}', [
            'message' => $texte, 'dossier' => $dossier->getReference(),
        ]);

        return $enveloppe;
    }
}
