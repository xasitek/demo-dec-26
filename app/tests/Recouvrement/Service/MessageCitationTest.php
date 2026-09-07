<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Service;

use App\Recouvrement\Service\MessageCitation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageCitationTest extends TestCase
{
    public function testNullRenvoieNull(): void
    {
        self::assertNull(MessageCitation::principal(null));
    }

    public function testMessageSansCitationInchange(): void
    {
        $corps = "Bonjour,\n\nMerci pour votre relance, je regarde ca.\n\nCordialement";
        self::assertSame($corps, MessageCitation::principal($corps));
    }

    public function testCoupeLAttributionGmailFr(): void
    {
        $corps = "ou est le rib ?\n\n"
            ."Le mar. 7 juil. 2026 a 16:01, Groupe Synthauto - Recouvrement <recouvrement@relances.demonstration.invalid> a ecrit :\n"
            ."> GROUPE SYNTHAUTO Service recouvrement\n"
            ."> Madame, Monsieur,\n"
            .'> Sauf erreur ou omission, les factures suivantes demeurent impayees.';

        self::assertSame('ou est le rib ?', MessageCitation::principal($corps));
    }

    public function testCoupeLAttributionGmailRepliee(): void
    {
        // Gmail replie souvent l'attribution : le "<email>" passe a la ligne, donc
        // "a ecrit :" se retrouve sur une ligne differente de "Le ...".
        $corps = "ou est le rib ?\n\n"
            ."Le mar. 7 juil. 2026 a 16:01, Groupe Synthauto - Recouvrement <\n"
            ."recouvrement@relances.demonstration.invalid> a ecrit :\n"
            .'> GROUPE SYNTHAUTO Service recouvrement';

        self::assertSame('ou est le rib ?', MessageCitation::principal($corps));
    }

    public function testNeCoupePasUnePhraseAvecEcrit(): void
    {
        // "Le rapport que vous avez ecrit" ne doit pas etre pris pour une attribution.
        $corps = "Le rapport a été bien reçu, merci.\nJe reviens vers vous vite.";
        self::assertSame($corps, MessageCitation::principal($corps));
    }

    public function testCoupeLAttributionAvecAccent(): void
    {
        $corps = "Je paie demain.\n\nLe 7 juillet 2026 a 16:01, Service <x@y.fr> a écrit :\n> texte cite";
        self::assertSame('Je paie demain.', MessageCitation::principal($corps));
    }

    public function testCoupeLesLignesCitees(): void
    {
        $corps = "Merci.\n> ligne citee 1\n> ligne citee 2";
        self::assertSame('Merci.', MessageCitation::principal($corps));
    }

    public function testCoupeLenteteOutlook(): void
    {
        $corps = "Voir ci-dessous.\n\nDe : Recouvrement <x@y.fr>\nEnvoye : mardi 7 juillet\nObjet : Vos factures";
        self::assertSame('Voir ci-dessous.', MessageCitation::principal($corps));
    }

    public function testCoupeSeparateurOriginalMessage(): void
    {
        $corps = "Reponse rapide.\n\n----- Original Message -----\nFrom: x@y.fr";
        self::assertSame('Reponse rapide.', MessageCitation::principal($corps));
    }

    public function testReponseSansNouveauTexteRetombeSurLOriginal(): void
    {
        // Reponse "top-quote" sans texte au-dessus : on prefere l'original nettoye
        // plutot qu'une chaine vide.
        $corps = "> tout est cite\n> rien au-dessus";
        self::assertSame($corps, MessageCitation::principal($corps));
    }

    #[DataProvider('finsDeLigne')]
    public function testNormaliseLesFinsDeLigne(string $corps): void
    {
        self::assertSame('Bonjour', MessageCitation::principal($corps));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function finsDeLigne(): iterable
    {
        yield 'CRLF' => ["Bonjour\r\n> cite"];
        yield 'CR' => ["Bonjour\r> cite"];
        yield 'LF' => ["Bonjour\n> cite"];
    }
}
