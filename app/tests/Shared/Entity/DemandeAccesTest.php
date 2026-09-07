<?php

declare(strict_types=1);

namespace App\Tests\Shared\Entity;

use App\Shared\Entity\DemandeAcces;
use App\Shared\Entity\StatutDemande;
use App\Shared\Entity\User;
use PHPUnit\Framework\TestCase;

final class DemandeAccesTest extends TestCase
{
    public function testNouvelleDemandeEstEnAttente(): void
    {
        $demande = new DemandeAcces(new User());

        self::assertTrue($demande->isEnAttente());
        self::assertSame(StatutDemande::EnAttente, $demande->getStatut());
        self::assertNull($demande->getDecideur());
        self::assertNull($demande->getDecidedAt());
    }

    public function testApprouverAttribueRolesEtDecideur(): void
    {
        $demande = new DemandeAcces(new User());
        $decideur = new User();

        $demande->approuver($decideur, ['ROLE_COMPTABLE']);

        self::assertFalse($demande->isEnAttente());
        self::assertSame(StatutDemande::Approuvee, $demande->getStatut());
        self::assertSame(['ROLE_COMPTABLE'], $demande->getRolesAttribues());
        self::assertSame($decideur, $demande->getDecideur());
        self::assertNotNull($demande->getDecidedAt());
    }

    public function testRefuser(): void
    {
        $demande = new DemandeAcces(new User());
        $decideur = new User();

        $demande->refuser($decideur);

        self::assertFalse($demande->isEnAttente());
        self::assertSame(StatutDemande::Refusee, $demande->getStatut());
        self::assertSame($decideur, $demande->getDecideur());
    }

    public function testLabelsDeStatut(): void
    {
        self::assertSame('En attente', StatutDemande::EnAttente->label());
        self::assertSame('Approuvée', StatutDemande::Approuvee->label());
        self::assertSame('Refusée', StatutDemande::Refusee->label());
    }
}
