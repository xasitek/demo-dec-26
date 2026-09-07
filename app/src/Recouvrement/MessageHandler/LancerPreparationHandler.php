<?php

declare(strict_types=1);

namespace App\Recouvrement\MessageHandler;

use App\Recouvrement\Enum\RelanceVecteur;
use App\Recouvrement\Enum\StatutPreparation;
use App\Recouvrement\Message\EnvoyerRelance;
use App\Recouvrement\Message\LancerPreparation;
use App\Recouvrement\Repository\PreparationRunRepository;
use App\Recouvrement\Service\EnvoiRelanceService;
use App\Recouvrement\Service\RecouvrementRealtime;
use App\Recouvrement\Service\SelectionRelanceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Lancement MANUEL d'une strategie (bouton "Lancer maintenant") execute cote
 * worker : prepare les relances de la regle ciblee (evaluee SEULE, meme si elle
 * est en pause) et les dispatch en asynchrone, tout en alimentant une barre de
 * progression PERSISTANTE (PreparationRun + Mercure) qui survit au changement de
 * page.
 *
 * On NE rafraichit PAS v_impayes ici (contrairement a la commande cron) : le
 * lancement manuel travaille sur le dernier snapshot, sans refresh lourd a chaque
 * clic.
 *
 * Robustesse :
 *   - le suivi (total / traites / statut) est ecrit en DBAL DIRECT (repository) :
 *     independant de l'UnitOfWork, donc fiable meme si un flush ferme l'EM ->
 *     jamais de run coince en EN_COURS a cause d'un statut final non ecrit ;
 *   - em->clear() periodique pour borner la memoire sur gros volume ;
 *   - heartbeat (dernier_signe_le) mis a jour a chaque palier -> un run dont le
 *     worker meurt est detecte comme zombie et n'empeche pas un relancement.
 *
 * Idempotence / anti-doublon : garantie par EnvoiRelanceService (un palier deja
 * prepare/envoye est ignore ; le worker re-verifie juste avant l'envoi reel).
 *
 * @phpstan-import-type GroupeARelancer from SelectionRelanceService
 */
#[AsMessageHandler]
final class LancerPreparationHandler
{
    /** Frequence de publication de la progression + heartbeat (1 sur N comptes). */
    private const PAS_PROGRESSION = 5;

    /** Frequence du clear de l'UnitOfWork (borne la memoire sur gros volume). */
    private const PAS_CLEAR = 50;

    public function __construct(
        private readonly SelectionRelanceService $selection,
        private readonly EnvoiRelanceService $envoi,
        private readonly MessageBusInterface $bus,
        private readonly PreparationRunRepository $runs,
        private readonly RecouvrementRealtime $realtime,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LancerPreparation $message): void
    {
        $runId = $message->runId;
        $run = $this->runs->find($runId);
        if (null === $run || StatutPreparation::EN_COURS !== $run->getStatut()) {
            // Run introuvable ou deja termine/expire (rejeu) : rien a faire.
            return;
        }

        $regleNom = $run->getRegleNom();
        $traites = 0;
        $total = 0;
        $statut = StatutPreparation::TERMINE;

        try {
            $groupes = $this->selection->aRelancer($message->limit, $message->regleId);
            $total = \count($groupes);
            $this->runs->definirTotal($runId, $total);
            $this->realtime->signalerProgressionPreparation($runId, $regleNom, StatutPreparation::EN_COURS, $total, 0);

            foreach ($groupes as $index => $groupe) {
                $this->preparerEtDispatcher($groupe);
                ++$traites;

                // Si un flush a ferme l'EM, inutile de continuer : on bascule en echec.
                if (!$this->em->isOpen()) {
                    throw new RuntimeException('EntityManager ferme pendant la preparation');
                }

                if (0 === ($index + 1) % self::PAS_PROGRESSION) {
                    $this->runs->progresser($runId, $traites);
                    $this->realtime->signalerProgressionPreparation($runId, $regleNom, StatutPreparation::EN_COURS, $total, $traites);
                }

                if (0 === ($index + 1) % self::PAS_CLEAR) {
                    $this->em->clear();
                }
            }
        } catch (Throwable $e) {
            $statut = StatutPreparation::ECHEC;
            $this->logger->error('Lancement de strategie echoue', [
                'run' => $runId,
                'regle' => $message->regleId,
                'traites' => $traites,
                'erreur' => $e->getMessage(),
            ]);
        }

        // Statut final en DBAL direct : fiable meme EM ferme (jamais de zombie).
        $this->runs->terminer($runId, $statut, $traites);
        $this->realtime->signalerProgressionPreparation($runId, $regleNom, $statut, $total, $traites);
    }

    /**
     * Pre-cree le RelanceEnvoi puis, pour un vecteur EMAIL, dispatch l'envoi
     * asynchrone. Un vecteur COURRIER reste en attente (impression manuelle). Un
     * doublon (palier deja pris en charge) est ignore sans casser le run.
     *
     * @param GroupeARelancer $groupe
     */
    private function preparerEtDispatcher(array $groupe): void
    {
        try {
            $relance = $this->envoi->preparer($groupe);
            $relanceId = $relance->getId();
            if (null === $relanceId) {
                return; // ignore (doublon detecte)
            }

            if (RelanceVecteur::COURRIER !== $groupe['vecteur']) {
                $this->bus->dispatch(new EnvoyerRelance($relanceId, $groupe));
            }
        } catch (Throwable $e) {
            // Cas nominal : palier (compte, niveau) deja prepare/envoye -> on ignore.
            $this->logger->warning('Lancement : relance non preparee (probable doublon)', [
                'compte_code' => $groupe['compte_code'],
                'niveau' => $groupe['niveau'],
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
