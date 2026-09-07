<?php

declare(strict_types=1);

namespace App\Tests\Remboursement\Entity;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use PHPUnit\Framework\TestCase;

/**
 * Adresse mise en copie de l'attestation.
 *
 * La normalisation n'est pas cosmetique : le carnet de favoris est indexe en unique sur
 * (secretaire, adresse), et l'UPSERT compare des chaines. « A.B@Exemple.FR » et
 * « a.b@exemple.fr » doivent donc etre LA MEME adresse, sans quoi la meme personne
 * apparaitrait plusieurs fois dans le menu deroulant.
 */
final class EmailCopieTest extends TestCase
{
    public function testAdresseNormaliseeEnMinusculesEtSansEspaces(): void
    {
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setEmailCopie('  A.Dupont@Exemple.FR  ');

        self::assertSame('a.dupont@exemple.fr', $d->getEmailCopie());
    }

    public function testChampVideVautAbsenceDAdresse(): void
    {
        // Le formulaire soumet toujours le champ : une chaine vide ne doit pas devenir
        // une adresse vide, sinon le mailer croirait avoir une copie a mettre.
        $d = new Dossier(DossierMotif::TROP_PERCU);
        $d->setEmailCopie('   ');

        self::assertNull($d->getEmailCopie());
    }

    public function testAbsenceParDefaut(): void
    {
        self::assertNull((new Dossier(DossierMotif::RACHAT_SEC))->getEmailCopie());
    }

    public function testAdresseRetirable(): void
    {
        // Vider le champ lors d'une correction doit retirer la copie.
        $d = new Dossier(DossierMotif::RACHAT_SEC);
        $d->setEmailCopie('a@b.fr');
        $d->setEmailCopie(null);

        self::assertNull($d->getEmailCopie());
    }
}
