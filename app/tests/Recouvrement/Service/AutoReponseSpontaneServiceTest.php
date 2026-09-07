<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Service;

use App\Recouvrement\Service\AutoReponseSpontaneService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutoReponseSpontaneServiceTest extends TestCase
{
    private const FROM = 'recouvrement@relances.demonstration.invalid';
    private const REPLY = 'relances@demonstration.invalid';

    public function testAdresseValideNormaliseEtValide(): void
    {
        self::assertSame('jean@client.fr', AutoReponseSpontaneService::adresseValide('  Jean@Client.FR '));
        self::assertNull(AutoReponseSpontaneService::adresseValide(null));
        self::assertNull(AutoReponseSpontaneService::adresseValide(''));
        self::assertNull(AutoReponseSpontaneService::adresseValide('pas-une-adresse'));
    }

    #[DataProvider('adressesAutomatiques')]
    public function testAdressesAutomatiquesExclues(string $adresse): void
    {
        self::assertTrue(AutoReponseSpontaneService::estAutomatique($adresse, self::FROM, self::REPLY));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function adressesAutomatiques(): iterable
    {
        yield 'mailer-daemon' => ['mailer-daemon@gmail.com'];
        yield 'postmaster' => ['postmaster@client.fr'];
        yield 'no-reply' => ['no-reply@fournisseur.com'];
        yield 'noreply' => ['noreply@fournisseur.com'];
        yield 'ne-pas-repondre' => ['ne-pas-repondre@banque.fr'];
        yield 'bounce' => ['bounce+123@mailjet.com'];
        yield 'notre from' => [self::FROM];
        yield 'notre reply-to' => [self::REPLY];
        yield 'notre domaine (interne)' => ['collegue@relances.demonstration.invalid'];
    }

    public function testClientNormalNonAutomatique(): void
    {
        self::assertFalse(AutoReponseSpontaneService::estAutomatique('jean.dupont@client.fr', self::FROM, self::REPLY));
        self::assertFalse(AutoReponseSpontaneService::estAutomatique('compta@garage-legrand.com', self::FROM, self::REPLY));
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('enTetesEnMasseOuAuto')]
    public function testEnMasseOuAutoDetecte(array $headers): void
    {
        self::assertTrue(AutoReponseSpontaneService::estEnMasseOuAuto($headers));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function enTetesEnMasseOuAuto(): iterable
    {
        yield 'auto-submitted auto-replied' => [['auto-submitted' => 'auto-replied']];
        yield 'auto-submitted auto-generated' => [['auto-submitted' => 'auto-generated']];
        yield 'precedence bulk' => [['precedence' => 'bulk']];
        yield 'precedence list' => [['precedence' => 'list']];
        yield 'list-id' => [['list-id' => '<news.exemple.com>']];
        yield 'list-unsubscribe' => [['list-unsubscribe' => '<https://x/u>']];
        yield 'x-autoreply' => [['x-autoreply' => 'yes']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('enTetesHumains')]
    public function testMailHumainNonExclu(array $headers): void
    {
        self::assertFalse(AutoReponseSpontaneService::estEnMasseOuAuto($headers));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function enTetesHumains(): iterable
    {
        yield 'aucun en-tete' => [[]];
        yield 'auto-submitted no' => [['auto-submitted' => 'no']];
        yield 'mail normal' => [['subject' => 'Question sur ma facture', 'from' => 'jean@client.fr']];
    }
}
