<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\RelanceEnvoi;
use App\Recouvrement\Enum\RelanceStatut;
use App\Recouvrement\Enum\RelanceVecteur;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture des relances envoyées (recouvrement.relance_envoi).
 *
 * @extends ServiceEntityRepository<RelanceEnvoi>
 */
final class RelanceEnvoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelanceEnvoi::class);
    }

    /**
     * Clot le cycle de relance des comptes SOLDES : marque cycle_clos=true les
     * relances envoyees des comptes qui n'ont plus AUCUNE facture echue dans
     * v_impayes. Une nouvelle dette (re-facturation) repart ainsi au niveau 1,
     * sans perdre l'historique (la mise en demeure reste une preuve). A appeler
     * avant chaque preparation de relances.
     *
     * @return int nombre de relances archivees
     */
    public function cloreCyclesSoldes(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(<<<'SQL'
            UPDATE recouvrement.relance_envoi AS r
            SET cycle_clos = TRUE
            WHERE r.statut = 'envoye' AND r.cycle_clos = FALSE
              AND NOT EXISTS (
                  SELECT 1 FROM recouvrement.v_impayes v
                  WHERE v.compte = r.compte_code AND v.montant_solde > 0 AND v.jours_retard > 0
              )
            SQL);
    }

    /**
     * Journal : relances "actives" un jour donné (envoyées, en échec ou annulées
     * ce jour-là), de la plus récente à la plus ancienne. Date d'activité =
     * COALESCE(envoye_le, prepare_le, cree_le) — un échec n'a pas d'envoye_le, on
     * le rattache au jour de la tentative. Bornes demi-ouvertes [début, fin[ pour
     * rester indexable (idx_relance_activite). Nom du client joint depuis
     * mirror.tiers en LATERAL (peu de lignes par jour -> lookups indexés).
     *
     * @return list<array{id: int, compte: string, client: string, email: ?string, niveau: int, vecteur: string, statut: string, montant: string, erreur: ?string, heure: DateTimeImmutable}>
     */
    public function findDuJour(DateTimeImmutable $debut, DateTimeImmutable $fin): array
    {
        $sql = <<<'SQL'
            SELECT r.id, r.compte_code, r.destinataire, r.niveau, r.vecteur, r.statut,
                   r.montant_solde, r.erreur_message,
                   COALESCE(r.envoye_le, r.prepare_le, r.cree_le) AS activite,
                   t.raison_sociale, t.nom, t.prenom
            FROM recouvrement.relance_envoi r
            LEFT JOIN LATERAL (
                SELECT donnees->>'Raison Sociale' AS raison_sociale,
                       donnees->>'nom' AS nom, donnees->>'prenom' AS prenom
                FROM mirror.tiers
                WHERE present_dans_sage AND donnees->>'code' = r.compte_code
                ORDER BY cle LIMIT 1
            ) t ON TRUE
            WHERE r.statut IN ('envoye', 'echec', 'annule')
              AND COALESCE(r.envoye_le, r.prepare_le, r.cree_le) >= :debut
              AND COALESCE(r.envoye_le, r.prepare_le, r.cree_le) < :fin
            ORDER BY activite DESC, r.id DESC
            SQL;

        return $this->mapLignes($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'debut' => $debut->format('Y-m-d H:i:s'),
            'fin' => $fin->format('Y-m-d H:i:s'),
        ]));
    }

    /**
     * Journal : toutes les relances encore À ENVOYER (email pas encore parti ou
     * courrier à poster), niveau décroissant puis compte. Bloc "en attente" du
     * jour courant. Nom du client joint (LATERAL).
     *
     * @return list<array{id: int, compte: string, client: string, email: ?string, niveau: int, vecteur: string, statut: string, montant: string, erreur: ?string, heure: DateTimeImmutable}>
     */
    public function findEnAttente(): array
    {
        $sql = <<<'SQL'
            SELECT r.id, r.compte_code, r.destinataire, r.niveau, r.vecteur, r.statut,
                   r.montant_solde, r.erreur_message,
                   COALESCE(r.prepare_le, r.cree_le) AS activite,
                   t.raison_sociale, t.nom, t.prenom
            FROM recouvrement.relance_envoi r
            LEFT JOIN LATERAL (
                SELECT donnees->>'Raison Sociale' AS raison_sociale,
                       donnees->>'nom' AS nom, donnees->>'prenom' AS prenom
                FROM mirror.tiers
                WHERE present_dans_sage AND donnees->>'code' = r.compte_code
                ORDER BY cle LIMIT 1
            ) t ON TRUE
            WHERE r.statut = 'a_envoyer'
            ORDER BY r.niveau DESC, r.compte_code ASC, r.id ASC
            SQL;

        return $this->mapLignes($this->getEntityManager()->getConnection()->fetchAllAssociative($sql));
    }

    /**
     * Journal : synthèse chiffrée d'une journée (barre de résumé). Une seule
     * requête agrégée sur les mêmes bornes que findDuJour().
     *
     * @return array{nb: int, total: string, emails: int, courriers: int, echecs: int, annules: int, mises_en_demeure: int}
     */
    public function syntheseDuJour(DateTimeImmutable $debut, DateTimeImmutable $fin): array
    {
        $sql = <<<'SQL'
            SELECT
                count(*) FILTER (WHERE statut = 'envoye') AS envoyes,
                count(*) FILTER (WHERE statut = 'echec') AS echecs,
                count(*) FILTER (WHERE statut = 'annule') AS annules,
                count(*) FILTER (WHERE statut = 'envoye' AND vecteur = 'email') AS emails,
                count(*) FILTER (WHERE statut = 'envoye' AND vecteur = 'courrier') AS courriers,
                count(*) FILTER (WHERE statut = 'envoye' AND niveau >= 3) AS med,
                COALESCE(SUM(montant_solde) FILTER (WHERE statut = 'envoye'), 0) AS total
            FROM recouvrement.relance_envoi
            WHERE COALESCE(envoye_le, prepare_le, cree_le) >= :debut
              AND COALESCE(envoye_le, prepare_le, cree_le) < :fin
            SQL;

        /** @var array<string, mixed>|false $row */
        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, [
            'debut' => $debut->format('Y-m-d H:i:s'),
            'fin' => $fin->format('Y-m-d H:i:s'),
        ]);
        $row = false === $row ? [] : $row;

        return [
            'nb' => (int) ($row['envoyes'] ?? 0),
            'total' => (string) ($row['total'] ?? '0'),
            'emails' => (int) ($row['emails'] ?? 0),
            'courriers' => (int) ($row['courriers'] ?? 0),
            'echecs' => (int) ($row['echecs'] ?? 0),
            'annules' => (int) ($row['annules'] ?? 0),
            'mises_en_demeure' => (int) ($row['med'] ?? 0),
        ];
    }

    /**
     * Normalise les lignes brutes du journal (types + nom d'affichage du client :
     * raison sociale, sinon nom+prénom, sinon code compte).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: int, compte: string, client: string, email: ?string, niveau: int, vecteur: string, statut: string, montant: string, erreur: ?string, heure: DateTimeImmutable}>
     */
    private function mapLignes(array $rows): array
    {
        return array_map(static function (array $r): array {
            $raison = trim((string) ($r['raison_sociale'] ?? ''));
            $nomPrenom = trim(((string) ($r['nom'] ?? '')).' '.((string) ($r['prenom'] ?? '')));
            $compte = (string) ($r['compte_code'] ?? '');
            $client = '' !== $raison ? $raison : ('' !== $nomPrenom ? $nomPrenom : $compte);

            $activite = $r['activite'] ?? 'now';
            $heure = $activite instanceof DateTimeImmutable ? $activite : new DateTimeImmutable((string) $activite);

            return [
                'id' => (int) ($r['id'] ?? 0),
                'compte' => $compte,
                'client' => $client,
                'email' => isset($r['destinataire']) ? (string) $r['destinataire'] : null,
                'niveau' => (int) ($r['niveau'] ?? 0),
                'vecteur' => (string) ($r['vecteur'] ?? ''),
                'statut' => (string) ($r['statut'] ?? ''),
                'montant' => (string) ($r['montant_solde'] ?? '0'),
                'erreur' => isset($r['erreur_message']) ? (string) $r['erreur_message'] : null,
                'heure' => $heure,
            ];
        }, $rows);
    }

    /**
     * Relances réellement envoyées à un compte, de la plus ancienne à la plus
     * récente (pour la timeline des échanges, lecture chronologique).
     *
     * @return list<RelanceEnvoi>
     */
    public function findEnvoyeesParCompte(string $compteCode, int $limit = 100): array
    {
        /** @var list<RelanceEnvoi> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.compteCode = :code')
            ->andWhere('r.statut = :statut')
            ->setParameter('code', $compteCode)
            ->setParameter('statut', RelanceStatut::ENVOYE)
            ->orderBy('r.envoyeLe', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Courriers en attente de mise sous pli : relances de vecteur COURRIER encore
     * au statut A_ENVOYER (le client n'a pas d'email, la lettre attend d'etre
     * imprimee et postee par le comptable). Triees par compte puis niveau pour
     * un lot PDF lisible.
     *
     * @return list<RelanceEnvoi>
     */
    public function findCourriersEnAttente(): array
    {
        /** @var list<RelanceEnvoi> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.vecteur = :vecteur')
            ->andWhere('r.statut = :statut')
            ->setParameter('vecteur', RelanceVecteur::COURRIER)
            ->setParameter('statut', RelanceStatut::A_ENVOYER)
            ->orderBy('r.compteCode', 'ASC')
            ->addOrderBy('r.niveau', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Courriers dont le PDF (relevé + factures) doit etre genere par la machine
     * interne : vecteur COURRIER, statut A_ENVOYER. Sans $force, uniquement ceux
     * qui n'ont pas encore de PDF stocke (courrier_pdf IS NULL).
     *
     * @return list<RelanceEnvoi>
     */
    public function findCourriersAGenerer(bool $force = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.vecteur = :vecteur')
            ->andWhere('r.statut = :statut')
            ->setParameter('vecteur', RelanceVecteur::COURRIER)
            ->setParameter('statut', RelanceStatut::A_ENVOYER)
            ->orderBy('r.compteCode', 'ASC')
            ->addOrderBy('r.id', 'ASC');

        if (!$force) {
            $qb->andWhere('r.courrierPdf IS NULL');
        }

        /** @var list<RelanceEnvoi> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Courriers DEJA POSTES (historique), du plus recent au plus ancien, pagine
     * (l'historique grandit sans limite -> scroll infini cote vue).
     *
     * @return list<RelanceEnvoi>
     */
    public function findCourriersEnvoyes(int $page = 1, int $taille = 35): array
    {
        /** @var list<RelanceEnvoi> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.vecteur = :vecteur')
            ->andWhere('r.statut = :statut')
            ->setParameter('vecteur', RelanceVecteur::COURRIER)
            ->setParameter('statut', RelanceStatut::ENVOYE)
            ->orderBy('r.envoyeLe', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $taille))
            ->setMaxResults($taille)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Nombre de courriers en attente (badge de l'onglet "A envoyer").
     */
    public function compterCourriersEnAttente(): int
    {
        return $this->compterCourriers(RelanceStatut::A_ENVOYER);
    }

    /**
     * Nombre de courriers postes (badge de l'onglet "Envoyes").
     */
    public function compterCourriersEnvoyes(): int
    {
        return $this->compterCourriers(RelanceStatut::ENVOYE);
    }

    private function compterCourriers(RelanceStatut $statut): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.vecteur = :vecteur')
            ->andWhere('r.statut = :statut')
            ->setParameter('vecteur', RelanceVecteur::COURRIER)
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre total de relances effectivement envoyees (tous vecteurs confondus).
     */
    public function compterEnvoyees(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', RelanceStatut::ENVOYE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Synthese de la pile a poster (barre de resume de l'onglet "A envoyer") :
     * nombre de courriers, encours total, et nombre de mises en demeure (niveau
     * >= 3, l'acte sensible). Une seule requete agregee.
     *
     * @return array{nb: int, total: string, mises_en_demeure: int}
     */
    public function syntheseEnAttente(): array
    {
        /** @var array{nb: int|string, total: int|string|null, med: int|string|null} $row */
        $row = $this->createQueryBuilder('r')
            ->select(
                'COUNT(r.id) AS nb',
                'COALESCE(SUM(r.montantSolde), 0) AS total',
                'SUM(CASE WHEN r.niveau >= 3 THEN 1 ELSE 0 END) AS med',
            )
            ->andWhere('r.vecteur = :vecteur')
            ->andWhere('r.statut = :statut')
            ->setParameter('vecteur', RelanceVecteur::COURRIER)
            ->setParameter('statut', RelanceStatut::A_ENVOYER)
            ->getQuery()
            ->getSingleResult();

        return [
            'nb' => (int) $row['nb'],
            'total' => (string) ($row['total'] ?? '0'),
            'mises_en_demeure' => (int) ($row['med'] ?? 0),
        ];
    }

    /**
     * Marque un courrier comme poste : statut ENVOYE, date d'envoi (now) et nom
     * de l'operateur. Centralise ici pour que le controleur reste sans logique
     * metier. Idempotent : un courrier deja ENVOYE n'est pas re-trace.
     */
    public function marquerEnvoye(
        RelanceEnvoi $courrier,
        string $par,
        ?int $parUserId = null,
        ?string $snapshotHtml = null,
        bool $flush = true,
    ): void {
        if (RelanceStatut::ENVOYE === $courrier->getStatut()) {
            return;
        }

        $courrier->setStatut(RelanceStatut::ENVOYE);
        $courrier->setEnvoyeLe(new DateTimeImmutable());
        $courrier->setEnvoyePar($par);
        $courrier->setEnvoyeParUserId($parUserId);
        // Snapshot du document reellement emis (valeur probante) : fige une seule
        // fois, on n'ecrase pas un eventuel contenu deja present.
        if (null !== $snapshotHtml && '' !== $snapshotHtml && null === $courrier->getCorpsHtml()) {
            $courrier->setCorpsHtml($snapshotHtml);
        }

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Flush explicite (utilise par le marquage de lot : on marque N courriers
     * sans flush, puis un seul flush final).
     */
    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Remet un courrier au statut A_ENVOYER (rattrapage d'un marquage errone /
     * annulation). Efface la trace d'envoi (date + operateur). Idempotent : un
     * courrier deja A_ENVOYER n'est pas touche.
     */
    public function remettreAEnvoyer(RelanceEnvoi $courrier): void
    {
        if (RelanceStatut::A_ENVOYER === $courrier->getStatut()) {
            return;
        }

        $courrier->setStatut(RelanceStatut::A_ENVOYER);
        $courrier->setEnvoyeLe(null);
        $courrier->setEnvoyePar(null);
        $courrier->setEnvoyeParUserId(null);
        $courrier->setCorpsHtml(null);

        $this->getEntityManager()->flush();
    }

    /**
     * Annule EN LOT le marquage : remet A_ENVOYER (efface trace + snapshot) les
     * courriers dont les ids sont fournis, en UNE seule requete UPDATE. Borne aux
     * courriers actuellement ENVOYE. Retourne le nombre de lignes remises.
     *
     * @param list<int> $ids
     */
    public function remettreLotAEnvoyer(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.statut', ':aenvoyer')
            ->set('r.envoyeLe', 'NULL')
            ->set('r.envoyePar', 'NULL')
            ->set('r.envoyeParUserId', 'NULL')
            ->set('r.corpsHtml', 'NULL')
            ->andWhere('r.id IN (:ids)')
            ->andWhere('r.statut = :envoye')
            ->setParameter('aenvoyer', RelanceStatut::A_ENVOYER)
            ->setParameter('envoye', RelanceStatut::ENVOYE)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
