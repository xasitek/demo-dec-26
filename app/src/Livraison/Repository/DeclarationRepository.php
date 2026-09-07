<?php

declare(strict_types=1);

namespace App\Livraison\Repository;

use App\Livraison\Entity\Declaration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Declaration>
 */
final class DeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Declaration::class);
    }

    /**
     * Les identifiants de vehicule deja declares, pour retirer de la liste ce qui
     * l'a deja ete. C'est l'equivalent de la colonne « livre / non livre » du
     * tableur, tenu par la base au lieu d'un VLOOKUP.
     *
     * @param list<string> $identifiants
     *
     * @return array<string, true>
     */
    public function dejaDeclares(array $identifiants): array
    {
        if ([] === $identifiants) {
            return [];
        }

        /** @var list<string> $trouves */
        $trouves = $this->createQueryBuilder('d')
            ->select('d.identifiantVehicule')
            ->where('d.identifiantVehicule IN (:ids)')
            ->setParameter('ids', $identifiants)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($trouves, true);
    }

    /**
     * File de travail de la comptable : les declarations d'un statut donne, les plus
     * anciennes d'abord — un dossier qui attend depuis trois jours passe avant celui
     * d'aujourd'hui, sinon le loueur attend son paiement pour rien.
     *
     * @return list<Declaration>
     */
    public function parStatut(string $statut, int $limite = 200): array
    {
        /** @var list<Declaration> $lignes */
        $lignes = $this->createQueryBuilder('d')
            ->where('d.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('d.declareeLe', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $lignes;
    }

    /**
     * Compte par statut, pour les compteurs des onglets de l'ecran de controle.
     *
     * @return array<string, int>
     */
    public function comptesParStatut(): array
    {
        /** @var list<array{statut: string, n: int}> $lignes */
        $lignes = $this->createQueryBuilder('d')
            ->select('d.statut AS statut, COUNT(d.id) AS n')
            ->groupBy('d.statut')
            ->getQuery()
            ->getResult();

        $comptes = [];
        foreach ($lignes as $ligne) {
            $comptes[$ligne['statut']] = (int) $ligne['n'];
        }

        return $comptes;
    }

    /**
     * Les declarations d'une secretaire, les plus recentes d'abord.
     *
     * @return list<Declaration>
     */
    public function pour(string $email, int $limite = 200): array
    {
        /** @var list<Declaration> $lignes */
        $lignes = $this->createQueryBuilder('d')
            ->where('LOWER(d.declareePar) = :email')
            ->setParameter('email', mb_strtolower($email))
            ->orderBy('d.declareeLe', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $lignes;
    }
}
