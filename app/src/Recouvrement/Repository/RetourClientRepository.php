<?php

declare(strict_types=1);

namespace App\Recouvrement\Repository;

use App\Recouvrement\Entity\RetourClient;
use App\Recouvrement\Enum\RetourCategorie;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture / ecriture des retours clients (recouvrement.retour_client).
 *
 * Ecritures en Doctrine ORM (entites RetourClient). Les controles d'existence
 * (dedoublonnage IMAP par message_id) passent par une requete scalaire ciblee
 * pour eviter d'hydrater une entite inutilement.
 *
 * @extends ServiceEntityRepository<RetourClient>
 */
final class RetourClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetourClient::class);
    }

    public function save(RetourClient $retour, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($retour);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Page paginee des retours non traites (scroll infini de la vue comptable),
     * avec recherche (email ou compte), plage de dates de reception et sens de tri.
     *
     * @param 'asc'|'desc' $dir sens du tri sur la date de reception
     *
     * @return list<RetourClient>
     */
    public function findNonTraites(
        int $page = 1,
        int $parPage = 20,
        ?string $recherche = null,
        ?DateTimeImmutable $du = null,
        ?DateTimeImmutable $au = null,
        string $dir = 'desc',
    ): array {
        $page = max(1, $page);
        $parPage = max(1, $parPage);
        $direction = 'asc' === strtolower($dir) ? 'ASC' : 'DESC';

        /** @var list<RetourClient> $rows */
        $rows = $this->qbNonTraites($recherche, $du, $au)
            ->orderBy('r.recuLe', $direction)
            ->addOrderBy('r.id', $direction)
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Compte les retours non traites correspondant aux filtres (pour la pagination
     * et le total affiche). Sans filtre = nombre global (cf. compterNonTraites()).
     */
    public function compterFiltre(
        ?string $recherche = null,
        ?DateTimeImmutable $du = null,
        ?DateTimeImmutable $au = null,
    ): int {
        /** @var int|string $count */
        $count = $this->qbNonTraites($recherche, $du, $au)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function compterNonTraites(): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.traite = false')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Supprime definitivement un retour client.
     */
    public function supprimer(RetourClient $retour): void
    {
        $em = $this->getEntityManager();
        $em->remove($retour);
        $em->flush();
    }

    /**
     * Cle de conversation d'un retour : le code compte s'il existe, sinon l'email
     * de l'expediteur. Regroupe tous les echanges d'un meme client sur une ligne.
     */
    private const CLE_CONVERSATION = "COALESCE(NULLIF(compte_code, ''), expediteur)";

    /**
     * Conversations non traitees, une par client (cle = compte ou email), du
     * dernier message recu au plus ancien. Chaque conversation porte son DERNIER
     * message (pour l'extrait) et le nombre de messages non traites. Paginee +
     * filtres (recherche compte/email, plage de dates).
     *
     * @param 'asc'|'desc' $dir
     *
     * @return list<array{cle: string, dernier_id: int, compte_code: ?string, expediteur: ?string, corps_texte: ?string, corps_html: ?string, recu_le: DateTimeImmutable, nb: int, net: ?string, retard: ?int, nb_factures: int, niveau_max: ?int}>
     */
    public function findConversationsNonTraitees(
        int $page,
        int $parPage,
        ?string $recherche,
        ?DateTimeImmutable $du,
        ?DateTimeImmutable $au,
        string $dir,
        string $filtre = 'tous',
    ): array {
        $page = max(1, $page);
        $parPage = max(1, $parPage);
        $direction = 'asc' === strtolower($dir) ? 'ASC' : 'DESC';
        [$where, $params, $types] = $this->filtreDbal($recherche, $du, $au, $filtre);
        $params['limit'] = $parPage;
        $params['offset'] = ($page - 1) * $parPage;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $cle = self::CLE_CONVERSATION;
        $jointures = self::jointuresCompte();
        $sql = <<<SQL
            SELECT p.*, vi.net, vi.retard, vi.nb_factures, rel.niveau_max
            FROM (
                SELECT r.id AS dernier_id, r.compte_code, r.expediteur, r.corps_texte, r.corps_html, r.recu_le, g.nb, g.cle
                FROM recouvrement.retour_client r
                JOIN (
                    SELECT {$cle} AS cle, COUNT(*) AS nb, MAX(id) AS max_id
                    FROM recouvrement.retour_client
                    WHERE traite = false{$where}
                    GROUP BY {$cle}
                ) g ON g.max_id = r.id
                ORDER BY r.recu_le {$direction}, r.id {$direction}
                LIMIT :limit OFFSET :offset
            ) p
            {$jointures}
            ORDER BY p.recu_le {$direction}, p.dernier_id {$direction}
            SQL;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);

        return array_map([self::class, 'mapConversation'], $rows);
    }

    /**
     * Nombre de conversations non traitees (pour la pagination).
     */
    public function compterConversations(?string $recherche, ?DateTimeImmutable $du, ?DateTimeImmutable $au, string $filtre = 'tous'): int
    {
        [$where, $params, $types] = $this->filtreDbal($recherche, $du, $au, $filtre);
        $cle = self::CLE_CONVERSATION;
        $sql = <<<SQL
            SELECT COUNT(*) FROM (
                SELECT 1 FROM recouvrement.retour_client
                WHERE traite = false{$where}
                GROUP BY {$cle}
            ) x
            SQL;

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params, $types);
    }

    /**
     * La conversation (ligne groupee) a laquelle appartient un retour, pour
     * l'insertion temps reel. null si la conversation est desormais entierement
     * traitee (plus rien a afficher).
     *
     * @return array{cle: string, dernier_id: int, compte_code: ?string, expediteur: ?string, corps_texte: ?string, corps_html: ?string, recu_le: DateTimeImmutable, nb: int, net: ?string, retard: ?int, nb_factures: int, niveau_max: ?int}|null
     */
    public function findConversationParRetour(int $retourId): ?array
    {
        $conn = $this->getEntityManager()->getConnection();
        $cle = self::CLE_CONVERSATION;

        $cleValeur = $conn->fetchOne("SELECT {$cle} FROM recouvrement.retour_client WHERE id = :id", ['id' => $retourId]);
        if (false === $cleValeur || null === $cleValeur) {
            return null;
        }

        $jointures = self::jointuresCompte();
        $sql = <<<SQL
            SELECT p.*, vi.net, vi.retard, vi.nb_factures, rel.niveau_max
            FROM (
                SELECT r.id AS dernier_id, r.compte_code, r.expediteur, r.corps_texte, r.corps_html, r.recu_le, g.nb, g.cle
                FROM recouvrement.retour_client r
                JOIN (
                    SELECT {$cle} AS cle, COUNT(*) AS nb, MAX(id) AS max_id
                    FROM recouvrement.retour_client
                    WHERE traite = false AND {$cle} = :cle
                    GROUP BY {$cle}
                ) g ON g.max_id = r.id
                LIMIT 1
            ) p
            {$jointures}
            LIMIT 1
            SQL;

        /** @var array<string, mixed>|false $row */
        $row = $conn->fetchAssociative($sql, ['cle' => (string) $cleValeur]);

        return false === $row ? null : self::mapConversation($row);
    }

    /**
     * Marque TOUS les messages non traites d'une conversation comme traites.
     *
     * @return int nombre de messages traites
     */
    public function marquerConversationTraitee(string $cle, ?string $traitePar): int
    {
        $sqlCle = self::CLE_CONVERSATION;

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE recouvrement.retour_client SET traite = true, traite_par = :par, traite_le = :now WHERE {$sqlCle} = :cle AND traite = false",
            ['par' => $traitePar, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'cle' => $cle],
        );
    }

    /**
     * Clause WHERE (SQL brut) des filtres de conversation + params/types.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function filtreDbal(?string $recherche, ?DateTimeImmutable $du, ?DateTimeImmutable $au, string $filtre = 'tous'): array
    {
        $where = '';
        $params = [];
        $types = [];

        $recherche = null !== $recherche ? trim($recherche) : '';
        if ('' !== $recherche) {
            $where .= ' AND (LOWER(compte_code) LIKE :q OR LOWER(expediteur) LIKE :q)';
            $params['q'] = '%'.strtolower($recherche).'%';
        }
        if (null !== $du) {
            $where .= ' AND recu_le >= :du';
            $params['du'] = $du->format('Y-m-d H:i:s');
        }
        if (null !== $au) {
            $where .= ' AND recu_le <= :au';
            $params['au'] = $au->format('Y-m-d H:i:s');
        }
        // Rattachement a une relance : orphelins = reponses non reliees a une relance
        // (relance_envoi_id NULL), rattaches = reliees, tous = les deux.
        if ('orphelins' === $filtre) {
            $where .= ' AND relance_envoi_id IS NULL';
        } elseif ('rattaches' === $filtre) {
            $where .= ' AND relance_envoi_id IS NOT NULL';
        }

        return [$where, $params, $types];
    }

    /**
     * Jointures d'enrichissement d'une conversation par son compte : encours net,
     * retard max et nb de factures echues (v_impayes) + niveau de relance atteint
     * (relance_envoi, cycle en cours). LEFT JOIN sur r.compte_code -> NULL si orphelin.
     */
    private static function jointuresCompte(): string
    {
        // LATERAL correle : n'agrege QUE le compte de chaque ligne de la page (page
        // deja limitee -> alias p), via des lookups indexes sur v_impayes(compte) et
        // relance_envoi(compte_code). Reste instantane meme si v_impayes explose (on
        // n'agrege jamais toute la table), contrairement a un GROUP BY global.
        return <<<'SQL'
            LEFT JOIN LATERAL (
                SELECT COALESCE(sum(montant_solde), 0) AS net,
                       max(jours_retard) FILTER (WHERE montant_solde > 0) AS retard,
                       count(*) FILTER (WHERE jours_retard > 0 AND montant_solde > 0) AS nb_factures
                FROM recouvrement.v_impayes v
                WHERE v.compte = p.compte_code
                  AND ((v.jours_retard > 0 AND v.montant_solde > 0) OR v.montant_solde < 0)
            ) vi ON TRUE
            LEFT JOIN LATERAL (
                SELECT max(niveau) AS niveau_max
                FROM recouvrement.relance_envoi re
                WHERE re.compte_code = p.compte_code AND re.statut = 'envoye' AND re.cycle_clos = false
            ) rel ON TRUE
            SQL;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{cle: string, dernier_id: int, compte_code: ?string, expediteur: ?string, corps_texte: ?string, corps_html: ?string, recu_le: DateTimeImmutable, nb: int, net: ?string, retard: ?int, nb_factures: int, niveau_max: ?int}
     */
    private static function mapConversation(array $r): array
    {
        return [
            'cle' => (string) $r['cle'],
            'dernier_id' => (int) $r['dernier_id'],
            'compte_code' => self::nullableStr($r['compte_code'] ?? null),
            'expediteur' => self::nullableStr($r['expediteur'] ?? null),
            'corps_texte' => self::nullableStr($r['corps_texte'] ?? null),
            'corps_html' => self::nullableStr($r['corps_html'] ?? null),
            'recu_le' => new DateTimeImmutable((string) $r['recu_le']),
            'nb' => (int) $r['nb'],
            'net' => isset($r['net']) ? (string) $r['net'] : null,
            'retard' => isset($r['retard']) ? (int) $r['retard'] : null,
            'nb_factures' => isset($r['nb_factures']) ? (int) $r['nb_factures'] : 0,
            'niveau_max' => isset($r['niveau_max']) ? (int) $r['niveau_max'] : null,
        ];
    }

    private static function nullableStr(mixed $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }
        $texte = (string) $valeur;

        return '' === $texte ? null : $texte;
    }

    /**
     * Query builder de base des retours non traites + filtres optionnels :
     * recherche insensible a la casse sur l'email OU le code compte, et bornes de
     * date de reception (du >= , au <=).
     */
    private function qbNonTraites(
        ?string $recherche,
        ?DateTimeImmutable $du,
        ?DateTimeImmutable $au,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('r')->andWhere('r.traite = false');

        $recherche = null !== $recherche ? trim($recherche) : '';
        if ('' !== $recherche) {
            $qb->andWhere('LOWER(r.expediteur) LIKE :q OR LOWER(r.compteCode) LIKE :q')
                ->setParameter('q', '%'.strtolower($recherche).'%');
        }
        if (null !== $du) {
            $qb->andWhere('r.recuLe >= :du')->setParameter('du', $du);
        }
        if (null !== $au) {
            $qb->andWhere('r.recuLe <= :au')->setParameter('au', $au);
        }

        return $qb;
    }

    /**
     * Répartition des retours non traités par catégorie (valeur de l'enum).
     * Les retours sans catégorie (NULL en base) ressortent sous la clé ''.
     *
     * @return array<string, int> valeur de catégorie => nombre
     */
    public function compterNonTraitesParCategorie(): array
    {
        // r.categorie est typee enumType : la projection DQL l'hydrate en objet
        // RetourCategorie (ou null), pas en chaine. On lit donc ->value.
        /** @var list<array{categorie: RetourCategorie|null, nombre: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.categorie AS categorie', 'COUNT(r.id) AS nombre')
            ->andWhere('r.traite = false')
            ->groupBy('r.categorie')
            ->getQuery()
            ->getResult();

        $repartition = [];
        foreach ($rows as $row) {
            $categorie = $row['categorie'];
            $cle = $categorie instanceof RetourCategorie ? $categorie->value : '';
            $repartition[$cle] = (int) $row['nombre'];
        }

        return $repartition;
    }

    /**
     * Existe-t-il deja un retour portant ce message_id ? (dedoublonnage IMAP).
     */
    public function existsByMessageId(string $messageId): bool
    {
        if ('' === trim($messageId)) {
            return false;
        }

        /** @var int|string $count */
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.messageId = :mid')
            ->setParameter('mid', $messageId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Retours rattaches a un compte client, du plus recent au plus ancien.
     *
     * @return list<RetourClient>
     */
    public function findByCompte(string $compteCode, int $limit = 50): array
    {
        /** @var list<RetourClient> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.compteCode = :code')
            ->setParameter('code', $compteCode)
            ->orderBy('r.recuLe', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Marque un retour comme traite (qui, quand, commentaire), puis persiste.
     */
    public function marquerTraite(
        RetourClient $retour,
        ?string $traitePar = null,
        ?string $commentaire = null,
    ): void {
        $retour->setTraite(true);
        $retour->setTraitePar($traitePar);
        $retour->setTraiteLe(new DateTimeImmutable());
        if (null !== $commentaire && '' !== trim($commentaire)) {
            $retour->setCommentaireTraitement($commentaire);
        }

        $this->save($retour);
    }
}
