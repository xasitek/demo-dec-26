<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Entity\DossierTransition;
use App\Remboursement\Enum\DossierStatut;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Machine a etats du dossier de remboursement (implementation maison, sans
 * dependance a symfony/workflow). Porte la table des TRANSITIONS, leurs GARDES
 * par role, et applique chaque franchissement en tracant un DossierTransition
 * (audit) + en posant les jalons dates. Voir docs/MODULE_REMBOURSEMENT.md (4.1).
 *
 * Transitions AUTO (acteur SYSTEME, declenchees par des handlers Messenger) :
 * seulement via $parSysteme = true, jamais exposees a un endpoint humain.
 */
final class WorkflowRemboursement
{
    public const SYSTEME = 'SYSTEME';

    /**
     * Table des transitions. Chaque entree :
     *   from  : etats de depart autorises
     *   to    : etat d'arrivee
     *   roles : roles humains autorises (vide si transition auto)
     *   auto  : true = declenchee par le SYSTEME (handler), pas par un humain
     *
     * @var array<string, array{from: list<DossierStatut>, to: DossierStatut, roles: list<string>, auto: bool}>
     */
    private const DEFS = [
        'deposer' => ['from' => [DossierStatut::BROUILLON, DossierStatut::COMPLEMENT_REQUIS], 'to' => DossierStatut::DEPOSE, 'roles' => ['ROLE_SECRETAIRE'], 'auto' => false],
        'demarrer_extraction' => ['from' => [DossierStatut::DEPOSE], 'to' => DossierStatut::EXTRACTION_IA, 'roles' => [], 'auto' => true],
        'terminer_extraction' => ['from' => [DossierStatut::EXTRACTION_IA], 'to' => DossierStatut::A_VERIFIER, 'roles' => [], 'auto' => true],
        'echec_extraction' => ['from' => [DossierStatut::EXTRACTION_IA], 'to' => DossierStatut::A_VERIFIER, 'roles' => [], 'auto' => true],
        'demander_complement' => ['from' => [DossierStatut::A_VERIFIER], 'to' => DossierStatut::COMPLEMENT_REQUIS, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        // La secretaire renvoie sa correction -> repasse DIRECTEMENT en verification comptable.
        'renvoyer_correction' => ['from' => [DossierStatut::COMPLEMENT_REQUIS], 'to' => DossierStatut::A_VERIFIER, 'roles' => ['ROLE_SECRETAIRE'], 'auto' => false],
        'basculer_icar' => ['from' => [DossierStatut::A_VERIFIER], 'to' => DossierStatut::CAS_ICAR, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'resoudre_icar' => ['from' => [DossierStatut::CAS_ICAR], 'to' => DossierStatut::A_VERIFIER, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'envoyer_directeur' => ['from' => [DossierStatut::A_VERIFIER], 'to' => DossierStatut::A_VALIDER_DIRECTEUR, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'refuser_comptable' => ['from' => [DossierStatut::A_VERIFIER, DossierStatut::CAS_ICAR], 'to' => DossierStatut::REFUSE, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'valider_directeur' => ['from' => [DossierStatut::A_VALIDER_DIRECTEUR], 'to' => DossierStatut::VALIDE_DIRECTEUR, 'roles' => ['ROLE_DIRECTEUR'], 'auto' => false],
        'refuser_directeur' => ['from' => [DossierStatut::A_VALIDER_DIRECTEUR], 'to' => DossierStatut::REFUSE, 'roles' => ['ROLE_DIRECTEUR'], 'auto' => false],
        'confirmer' => ['from' => [DossierStatut::VALIDE_DIRECTEUR], 'to' => DossierStatut::CONFIRME, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'refuser_apres_validation' => ['from' => [DossierStatut::VALIDE_DIRECTEUR], 'to' => DossierStatut::REFUSE, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'demarrer_generation' => ['from' => [DossierStatut::CONFIRME], 'to' => DossierStatut::GENERATION_EN_COURS, 'roles' => [], 'auto' => true],
        'terminer_generation' => ['from' => [DossierStatut::GENERATION_EN_COURS], 'to' => DossierStatut::PAYE, 'roles' => [], 'auto' => true],
        'echec_generation' => ['from' => [DossierStatut::GENERATION_EN_COURS], 'to' => DossierStatut::ERREUR_GENERATION, 'roles' => [], 'auto' => true],
        'relancer_generation' => ['from' => [DossierStatut::ERREUR_GENERATION], 'to' => DossierStatut::CONFIRME, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'lettrer' => ['from' => [DossierStatut::PAYE], 'to' => DossierStatut::LETTRE, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'marquer_doublon' => ['from' => [DossierStatut::A_VERIFIER, DossierStatut::CAS_ICAR, DossierStatut::A_VALIDER_DIRECTEUR, DossierStatut::VALIDE_DIRECTEUR], 'to' => DossierStatut::DOUBLON, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'rouvrir_doublon' => ['from' => [DossierStatut::DOUBLON], 'to' => DossierStatut::A_VERIFIER, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'marquer_fraude' => ['from' => [DossierStatut::A_VERIFIER, DossierStatut::CAS_ICAR, DossierStatut::A_VALIDER_DIRECTEUR, DossierStatut::VALIDE_DIRECTEUR], 'to' => DossierStatut::FRAUDE, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
        'rouvrir' => ['from' => [DossierStatut::REFUSE], 'to' => DossierStatut::A_VERIFIER, 'roles' => ['ROLE_COMPTABLE'], 'auto' => false],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RemboursementRealtime $realtime,
    ) {
    }

    /**
     * Franchit une transition : valide l'etat de depart et l'autorisation, pose
     * l'etat + les jalons, trace l'audit, persiste. Leve DomainException si la
     * transition n'est pas applicable ou permise.
     */
    public function appliquer(Dossier $dossier, string $transition, ?string $par = null, ?string $commentaire = null, bool $parSysteme = false, bool $flush = true, bool $sansGarde = false): void
    {
        $def = self::DEFS[$transition] ?? throw new DomainException(sprintf('Transition inconnue : %s', $transition));

        if (!\in_array($dossier->getStatut(), $def['from'], true)) {
            throw new DomainException(sprintf('Transition "%s" impossible depuis l\'etat "%s".', $transition, $dossier->getStatut()->value));
        }

        if ($def['auto']) {
            if (!$parSysteme) {
                throw new DomainException(sprintf('La transition "%s" est reservee au systeme.', $transition));
            }
        } elseif (!$sansGarde && !$this->autorise($def['roles'])) {
            // $sansGarde : action deja autorisee autrement (ex. URL directeur signee).
            throw new DomainException(sprintf('Role insuffisant pour la transition "%s".', $transition));
        }

        $de = $dossier->getStatut();
        $dossier->definirStatut($def['to']);
        $this->poserJalon($dossier, $def['to']);
        $dossier->toucher($par);

        $this->em->persist(new DossierTransition($dossier, $de, $def['to'], $transition, $par, $commentaire));
        if ($flush) {
            $this->em->flush();
            // Etat commite : on pousse le nouveau badge vers "Mes dossiers" du deposant.
            $this->realtime->signalerChangementStatut($dossier);
        }
    }

    /**
     * Transitions HUMAINES actuellement possibles pour l'acteur courant (pour
     * construire les boutons d'action). Exclut les transitions auto.
     *
     * @return list<string>
     */
    public function transitionsPossibles(Dossier $dossier): array
    {
        $possibles = [];
        foreach (self::DEFS as $nom => $def) {
            if ($def['auto'] || !\in_array($dossier->getStatut(), $def['from'], true)) {
                continue;
            }
            if ($this->autorise($def['roles'])) {
                $possibles[] = $nom;
            }
        }

        return $possibles;
    }

    public function peut(Dossier $dossier, string $transition): bool
    {
        $def = self::DEFS[$transition] ?? null;
        if (null === $def || $def['auto'] || !\in_array($dossier->getStatut(), $def['from'], true)) {
            return false;
        }

        return $this->autorise($def['roles']);
    }

    /**
     * @param list<string> $roles
     */
    private function autorise(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    private function poserJalon(Dossier $dossier, DossierStatut $versStatut): void
    {
        $maintenant = new DateTimeImmutable();
        match ($versStatut) {
            DossierStatut::DEPOSE => $dossier->setDeposeLe($maintenant),
            DossierStatut::VALIDE_DIRECTEUR => $dossier->setValideDirecteurLe($maintenant),
            DossierStatut::CONFIRME => $dossier->setConfirmeLe($maintenant),
            DossierStatut::PAYE => $dossier->setPayeLe($maintenant),
            default => null,
        };
    }
}
