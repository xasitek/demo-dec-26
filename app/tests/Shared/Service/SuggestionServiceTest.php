<?php

declare(strict_types=1);

namespace App\Tests\Shared\Service;

use App\Shared\Entity\User;
use App\Shared\Enum\Module;
use App\Shared\Enum\StatutSuggestion;
use App\Shared\Enum\TypeSuggestion;
use App\Shared\Exception\LimiteSuggestionsAtteinte;
use App\Shared\Repository\SuggestionRepository;
use App\Shared\Service\SuggestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Boite a idees, cote service : la deduction du contexte (quel module a produit
 * la remontee) et le garde-fou anti-abus.
 */
final class SuggestionServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, Module|null}>
     */
    public static function cheminsProvider(): iterable
    {
        yield 'chemin de module' => ['/remboursement/deposer', Module::REMBOURSEMENT];
        yield 'racine de module' => ['/recouvrement', Module::RECOUVREMENT];
        yield 'module a tiret' => ['/bonus-eco/liste', Module::BONUS_ECO];
        yield 'url absolue' => ['https://finance.demonstration.invalid/creances/12', Module::CREANCES];
        yield 'hors module' => ['/admin/utilisateurs', null];
        yield 'tableau de bord' => ['/', null];
        yield 'vide' => ['', null];
        yield 'absent' => [null, null];
    }

    #[DataProvider('cheminsProvider')]
    public function testModuleDeduitDuChemin(?string $url, ?Module $attendu): void
    {
        self::assertSame($attendu, SuggestionService::moduleDepuisUrl($url));
    }

    public function testGardeFouRefuseAuDelaDuQuotaHoraire(): void
    {
        $service = self::serviceAvecCompteur(SuggestionService::MAX_PAR_HEURE);

        $this->expectException(LimiteSuggestionsAtteinte::class);

        $service->creer(self::auteur(), TypeSuggestion::IDEE, 'Encore une idee');
    }

    public function testVueEtNouvelleNeNotifientPasLAuteur(): void
    {
        // « Vue » ne dit rien de plus que « recu » : pas de notification.
        self::assertFalse(StatutSuggestion::NOUVELLE->meriteNotification());
        self::assertFalse(StatutSuggestion::VUE->meriteNotification());

        self::assertTrue(StatutSuggestion::EN_COURS->meriteNotification());
        self::assertTrue(StatutSuggestion::FAITE->meriteNotification());
        self::assertTrue(StatutSuggestion::REFUSEE->meriteNotification());
    }

    public function testStatutsDeCloture(): void
    {
        self::assertTrue(StatutSuggestion::FAITE->estCloturee());
        self::assertTrue(StatutSuggestion::REFUSEE->estCloturee());
        self::assertFalse(StatutSuggestion::EN_COURS->estCloturee());
        self::assertFalse(StatutSuggestion::NOUVELLE->estCloturee());
    }

    /**
     * Service instancie sans son constructeur (qui exige Mercure, Doctrine et le
     * service de notifications) : seul le depot est injecte, car le garde-fou
     * tranche avant tout autre appel. Meme approche que SageMirrorServiceTest.
     */
    private static function serviceAvecCompteur(int $envoisDeLHeure): SuggestionService
    {
        $depot = self::createStub(SuggestionRepository::class);
        $depot->method('compterDepuis')->willReturn($envoisDeLHeure);

        $reflexion = new ReflectionClass(SuggestionService::class);
        $service = $reflexion->newInstanceWithoutConstructor();

        $propriete = $reflexion->getProperty('suggestions');
        $propriete->setValue($service, $depot);

        return $service;
    }

    private static function auteur(): User
    {
        $user = new User();
        $user->setFirstName('Kirdan');
        $user->setLastName('Dupont');

        return $user;
    }
}
