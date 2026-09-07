<?php

declare(strict_types=1);

namespace App\Tests\Garanties\Entity;

use App\Garanties\Entity\Note;
use App\Shared\Entity\User;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Une note porte l'un OU l'autre ancrage — la DG rapprochee, ou l'ecriture Sage quand
 * il n'y en a aucune — mais jamais aucun des deux. La contrainte CHECK le garantit en
 * base ; les fabriques doivent l'interdire avant d'y arriver.
 */
final class NoteTest extends TestCase
{
    public function testNoteSurDossierNAPasDAncrageEcriture(): void
    {
        $note = Note::surDossier(42, self::auteur(), 'Facture à réclamer au constructeur.');

        self::assertSame(42, $note->getDossierId());
        self::assertNull($note->getCleEcriture());
        self::assertNull($note->getOidech());
        self::assertSame('Facture à réclamer au constructeur.', $note->getTexte());
    }

    public function testNoteSurEcritureNAPasDeDossier(): void
    {
        $note = Note::surEcriture('ECR12345', '778899', self::auteur(), 'Orpheline : aucun DG trouvé.');

        self::assertNull($note->getDossierId());
        self::assertSame('ECR12345', $note->getCleEcriture());
        self::assertSame('778899', $note->getOidech());
    }

    /**
     * L'oidech vide doit devenir NULL, pas la chaine vide : la jointure de la liste
     * compare `COALESCE(oidech, '')` des deux cotes, et le repository filtre sur
     * `IS NULL`. Deux representations du « pas d'oidech » feraient disparaitre la note.
     */
    public function testOidechVideDevientNull(): void
    {
        self::assertNull(Note::surEcriture('ECR1', '', self::auteur(), 'texte')->getOidech());
        self::assertNull(Note::surEcriture('ECR1', '   ', self::auteur(), 'texte')->getOidech());
        self::assertNull(Note::surEcriture('ECR1', null, self::auteur(), 'texte')->getOidech());
    }

    public function testCleEcritureEstNettoyee(): void
    {
        self::assertSame('ECR1', Note::surEcriture('  ECR1  ', null, self::auteur(), 'texte')->getCleEcriture());
    }

    public function testDossierSansIdentifiantEstRefuse(): void
    {
        $this->expectException(LogicException::class);

        Note::surDossier(0, self::auteur(), 'texte');
    }

    public function testEcritureSansCleEstRefusee(): void
    {
        $this->expectException(LogicException::class);

        Note::surEcriture('   ', null, self::auteur(), 'texte');
    }

    private static function auteur(): User
    {
        $user = new User();
        $user->setEmail('auditeur@demonstration.invalid')
            ->setFirstName('Kirdan')
            ->setLastName('Martin');

        return $user;
    }
}
