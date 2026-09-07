<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\PreparationRun;
use App\Recouvrement\Enum\StatutPreparation;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PreparationRun>
 */
final class PreparationRunRepository extends ServiceEntityRepository
{
    /**
     * Au-dela de ce delai SANS signe de vie (heartbeat), un run encore EN_COURS est
     * considere comme "zombie" (worker tue avant terminer()) : on cesse de l'afficher
     * et il n'empeche plus un nouveau lancement. Large devant le rythme de heartbeat
     * (a chaque palier), mais court pour que l'UI se debloque vite.
     */
    public const DELAI_ZOMBIE_MINUTES = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreparationRun::class);
    }

    public function save(PreparationRun $run, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($run);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Le lancement reellement en cours (le plus recent ET vivant), ou null. Sert a la
     * barre de progression globale. Un run zombie (heartbeat trop vieux) est ignore
     * pour que la barre ne tourne pas indefiniment.
     */
    public function enCours(): ?PreparationRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.statut = :statut')
            ->andWhere('r.dernierSigneLe > :seuil')
            ->setParameter('statut', StatutPreparation::EN_COURS)
            ->setParameter('seuil', $this->seuilVivant())
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Un lancement VIVANT est-il deja en cours pour cette strategie ? (anti-doublon).
     * Un zombie ne compte pas -> on peut relancer une strategie dont le worker a plante.
     */
    public function enCoursPourRegle(int $regleId): bool
    {
        $n = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.statut = :statut')
            ->andWhere('r.regleId = :regle')
            ->andWhere('r.dernierSigneLe > :seuil')
            ->setParameter('statut', StatutPreparation::EN_COURS)
            ->setParameter('regle', $regleId)
            ->setParameter('seuil', $this->seuilVivant())
            ->getQuery()
            ->getSingleScalarResult();

        return $n > 0;
    }

    /**
     * Passe en ECHEC les runs zombies (EN_COURS sans heartbeat recent). Appele avant
     * un nouveau lancement pour nettoyer l'etat. Renvoie le nombre de runs expires.
     */
    public function expirerZombies(): int
    {
        return (int) $this->connection()->executeStatement(
            <<<'SQL'
                UPDATE recouvrement.preparation_run
                SET statut = :echec, termine_le = now()
                WHERE statut = :encours
                  AND (dernier_signe_le IS NULL OR dernier_signe_le < :seuil)
                SQL,
            [
                'echec' => StatutPreparation::ECHEC->value,
                'encours' => StatutPreparation::EN_COURS->value,
                'seuil' => $this->seuilVivant()->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /**
     * Fixe le nombre total de comptes a traiter + heartbeat (demarrage du run).
     * Ecriture DBAL directe : independante de l'UnitOfWork, donc fiable meme si
     * l'EntityManager a ete ferme par un flush en echec cote handler.
     */
    public function definirTotal(int $id, int $total): void
    {
        $this->connection()->executeStatement(
            'UPDATE recouvrement.preparation_run SET total = :total, dernier_signe_le = now() WHERE id = :id',
            ['total' => $total, 'id' => $id],
        );
    }

    /**
     * Met a jour l'avancement + heartbeat (appele periodiquement pendant le run).
     */
    public function progresser(int $id, int $traites): void
    {
        $this->connection()->executeStatement(
            'UPDATE recouvrement.preparation_run SET traites = :traites, dernier_signe_le = now() WHERE id = :id',
            ['traites' => $traites, 'id' => $id],
        );
    }

    /**
     * Ecrit le statut FINAL (termine / echec) + le nombre traite. DBAL direct pour
     * garantir l'ecriture meme si l'EntityManager est ferme (sinon run zombie).
     */
    public function terminer(int $id, StatutPreparation $statut, int $traites): void
    {
        $this->connection()->executeStatement(
            'UPDATE recouvrement.preparation_run SET statut = :statut, traites = :traites, termine_le = now(), dernier_signe_le = now() WHERE id = :id',
            ['statut' => $statut->value, 'traites' => $traites, 'id' => $id],
        );
    }

    private function seuilVivant(): DateTimeImmutable
    {
        return new DateTimeImmutable('-'.self::DELAI_ZOMBIE_MINUTES.' minutes');
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
