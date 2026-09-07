<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\FacturePdf;
use App\Recouvrement\Repository\FacturePdfRepository;
use App\Shared\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Factures echues SANS PDF a televerser manuellement.
 *
 * Perimetre = celui des strategies de relance actives (Standard VN + APV) restreint
 * aux VRAIES factures : collectifs 41x, montant facturation > 0, hors etablissements
 * 111/112, journaux de vente (A2/V1-V5), type de piece FC ou AC. On ne liste que les
 * lignes sans PDF Progiciel (chemin_pdf vide) ET pas encore televersees ici. Le PDF
 * televerse est ensuite utilise par le moteur de relance a defaut du PDF Progiciel.
 */
final class FactureSansPdfService
{
    /** @var list<string> collectifs des regles actives (VN + APV) */
    private const COLLECTIFS = ['4111000', '4161000', '4114000', '4164000'];

    /** @var list<string> journaux de vente = vraies factures */
    private const JOURNAUX = ['A2', 'V1', 'V2', 'V3', 'V4', 'V5'];

    /** @var list<string> types de piece = facture / avoir */
    private const TYPES = ['FC', 'AC'];

    /** Garde-fou taille d'un PDF televerse (20 Mo). */
    private const TAILLE_MAX = 20_000_000;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly FacturePdfRepository $facturePdfs,
        private readonly RecouvrementRealtime $realtime,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Liste des factures echues sans PDF, triees par journal puis retard decroissant.
     *
     * @return list<array<string, mixed>>
     */
    public function lister(?string $recherche = null): array
    {
        [$where, $params, $types] = $this->filtres($recherche);

        $sql = 'SELECT v.ecriture_id, v.compte, v.numpiece, v.reference_facture, '
            .'v.raison_sociale, v.nom, v.prenom, v.montant_solde, v.date_echeance, '
            .'v.jours_retard, v.codeetab, v.marque, b.donnees->>\'codejournal\' AS journal '
            .'FROM recouvrement.v_impayes v '
            .'JOIN mirror.bal_eloficash b ON b.cle = v.cle '
            .'LEFT JOIN recouvrement.facture_pdf fp ON fp.ecriture_id = v.ecriture_id '
            .'LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte '
            .'WHERE '.$where.' '
            .'ORDER BY journal, v.jours_retard DESC, v.montant_solde DESC';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $rows;
    }

    public function compter(): int
    {
        [$where, $params, $types] = $this->filtres(null);

        $sql = 'SELECT count(*) FROM recouvrement.v_impayes v '
            .'JOIN mirror.bal_eloficash b ON b.cle = v.cle '
            .'LEFT JOIN recouvrement.facture_pdf fp ON fp.ecriture_id = v.ecriture_id '
            .'LEFT JOIN recouvrement.compte_exclusion e ON e.compte_code = v.compte '
            .'WHERE '.$where;

        return (int) $this->connection->fetchOne($sql, $params, $types);
    }

    /**
     * Contenu du PDF televerse pour une ligne (ecriture_id), ou null. Utilise par le
     * moteur de relance a defaut du PDF Progiciel.
     */
    public function pdfManuel(string $ecritureId): ?string
    {
        return $this->facturePdfs->findParEcriture($ecritureId)?->getContenu();
    }

    /**
     * Enregistre (ou remplace) le PDF televerse d'une facture, puis signale en temps
     * reel que la ligne sort de la liste.
     *
     * @throws RuntimeException si le contenu n'est pas un PDF valide ou trop volumineux
     */
    public function televerser(string $ecritureId, string $contenu, string $nomFichier, ?User $user): FacturePdf
    {
        if (!str_starts_with($contenu, '%PDF')) {
            throw new RuntimeException('Le fichier n\'est pas un PDF valide.');
        }
        if (\strlen($contenu) > self::TAILLE_MAX) {
            throw new RuntimeException('Fichier trop volumineux (20 Mo maximum).');
        }

        /** @var array<string, mixed>|false $meta */
        $meta = $this->connection->fetchAssociative(
            'SELECT numpiece, compte FROM recouvrement.v_impayes WHERE ecriture_id = :e LIMIT 1',
            ['e' => $ecritureId],
        );

        $facture = $this->facturePdfs->findParEcriture($ecritureId) ?? new FacturePdf($ecritureId, $contenu, $nomFichier);
        $facture->setContenu($contenu)->setNomFichier($nomFichier);
        if (false !== $meta) {
            $facture->setNumpiece(self::texte($meta['numpiece'] ?? null))
                ->setCompteCode(self::texte($meta['compte'] ?? null));
        }
        if (null !== $user) {
            $facture->setUploadedPar($user->getFullName())->setUploadedParUserId($user->getId());
        }

        $this->entityManager->persist($facture);
        $this->entityManager->flush();

        $this->realtime->signalerFactureUploadee($ecritureId);
        $this->logger->info('Recouvrement : PDF de facture televerse', [
            'ecriture_id' => $ecritureId,
            'octets' => $facture->getTailleOctets(),
        ]);

        return $facture;
    }

    /**
     * Clause WHERE commune + parametres (perimetre actif restreint aux factures sans PDF).
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    private function filtres(?string $recherche): array
    {
        $where = 'v.collectif IN (:collectifs) '
            .'AND v.montant_initial_facturation > 0 '
            .'AND (v.codeetab IS NULL OR btrim(v.codeetab) NOT IN (\'111\', \'112\')) '
            .'AND v.jours_retard > 0 AND v.montant_solde > 0 '
            .'AND (e.etat IS NULL OR e.etat <> \'ecarte\') '
            .'AND v.type_piece IN (:types) '
            .'AND (b.donnees->>\'codejournal\') IN (:journaux) '
            .'AND (v.chemin_pdf IS NULL OR btrim(v.chemin_pdf) = \'\') '
            .'AND fp.id IS NULL';

        $params = ['collectifs' => self::COLLECTIFS, 'types' => self::TYPES, 'journaux' => self::JOURNAUX];
        $types = [
            'collectifs' => ArrayParameterType::STRING,
            'types' => ArrayParameterType::STRING,
            'journaux' => ArrayParameterType::STRING,
        ];

        if (null !== $recherche && '' !== trim($recherche)) {
            $where .= ' AND (lower(v.compte) LIKE :q OR lower(v.raison_sociale) LIKE :q '
                .'OR lower(v.numpiece) LIKE :q OR lower(v.reference_facture) LIKE :q)';
            $params['q'] = '%'.strtolower(trim($recherche)).'%';
        }

        return [$where, $params, $types];
    }

    private static function texte(mixed $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }
}
