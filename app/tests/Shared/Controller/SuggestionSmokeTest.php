<?php

declare(strict_types=1);

namespace App\Tests\Shared\Controller;

/*
 * Smoke test de bout en bout de la boite a idees, joue contre une VRAIE base.
 *
 * Ignore par defaut : il n'entre pas dans la suite. Pour le lancer sur ce poste
 * (dont .env.local pointe la base Render) :
 *
 *   1. creer config/packages/test/zzz_local.yaml avec
 *      doctrine.dbal.connections.default.dbname_suffix: ''
 *      (sinon la cible est fc_finance_db_test, inexistante) ;
 *   2. supprimer var/cache/test (le conteneur compile garde l'ancien suffixe) ;
 *   3. SYNTH_SMOKE_DB=1 vendor/bin/phpunit tests/Shared/Controller/SuggestionSmokeTest.php
 *   4. SUPPRIMER zzz_local.yaml apres.
 *
 * Tout se passe dans une transaction rejouee en arriere (rollBack) : le DDL de la
 * migration et les ecritures du test ne laissent AUCUNE trace dans la base.
 * Cf. docs/DEVELOPMENT.md et la migration Version20260901190000.
 */

use App\Shared\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SuggestionSmokeTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        // Interrupteur explicite : ce test ecrit (puis annule) dans une base reelle,
        // il ne doit jamais partir par accident avec la suite.
        if ('1' !== ($_SERVER['SYNTH_SMOKE_DB'] ?? getenv('SYNTH_SMOKE_DB'))) {
            self::markTestSkipped('Smoke test base reelle : poser SYNTH_SMOKE_DB=1 pour le jouer.');
        }

        // .env.local n'est pas charge en env test : on recopie a la main les SEULES
        // variables de connexion. Surtout pas tout le fichier : il contient
        // APP_ENV=prod, qui ferait booter le mauvais environnement.
        $lignes = file(\dirname(__DIR__, 3).'/.env.local', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lignes as $ligne) {
            foreach (['DATABASE_URL', 'SAGE_DATABASE_URL'] as $cle) {
                if (str_starts_with($ligne, $cle.'=')) {
                    $valeur = trim(substr($ligne, \strlen($cle) + 1), " \t\"'");
                    $_SERVER[$cle] = $_ENV[$cle] = $valeur;
                    putenv($cle.'='.$valeur);
                }
            }
        }

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        $this->em->getConnection()->beginTransaction();
        $this->creerLesTables();
    }

    protected function tearDown(): void
    {
        // isset : le test peut avoir ete ignore avant tout boot du noyau.
        if (isset($this->em)) {
            $connexion = $this->em->getConnection();
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
        }

        parent::tearDown();
    }

    public function testLesEcransDeLaBoiteAIdeesRepondent(): void
    {
        $id = $this->em->getConnection()->fetchOne(
            "SELECT id FROM shared.users WHERE roles::text LIKE '%ADMIN%' AND is_active = true ORDER BY id LIMIT 1",
        );
        if (false === $id) {
            self::markTestSkipped('Aucun administrateur actif en base pour ce smoke test.');
        }

        $admin = $this->em->find(User::class, (int) $id);
        self::assertNotNull($admin);

        $this->client->loginUser($admin);

        // 1. Le mur, vide.
        $this->client->request('GET', '/suggestions');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET /suggestions');
        self::assertStringContainsString('Aucune idée pour l\'instant', $this->client->getResponse()->getContent() ?: '');

        // 2. Le panneau de l'ampoule (charge au clic), avec le contexte de la page.
        $crawler = $this->client->request('GET', '/suggestions/panneau?route=app_recouvrement_index&url=/recouvrement');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET /suggestions/panneau');
        $html = $this->client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('Depuis Recouvrement', $html, 'Le module doit etre deduit de l\'url');

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);

        // 3. Envoi d'une idee (XHR).
        $this->client->xmlHttpRequest('POST', '/suggestions', [
            '_token' => $token,
            'type' => 'idee',
            'message' => 'Smoke test : un raccourci vers le dossier client.',
            'route' => 'app_recouvrement_index',
            'url' => '/recouvrement',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'POST /suggestions');
        $charge = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($charge['ok'] ?? false, 'La reponse doit valider l\'envoi');

        // 4. L'idee apparait sur le mur, avec son auteur et son contexte.
        $crawler = $this->client->request('GET', '/suggestions');
        $html = $this->client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('Smoke test : un raccourci vers le dossier client.', $html);
        self::assertStringContainsString('Recouvrement', $html);

        // 5. Vote : le compteur passe a 1, puis revient a 0 (bascule idempotente).
        $idees = $this->em->getConnection()->fetchOne('SELECT id FROM shared.suggestion ORDER BY id DESC LIMIT 1');
        $jetonVote = $crawler->filter('form[data-suggestion-mur-target="formVote"] input[name="_token"]')->attr('value');

        $this->client->xmlHttpRequest('POST', '/suggestions/'.$idees.'/vote', ['_token' => $jetonVote]);
        $charge = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertSame(['ok' => true, 'vote' => true, 'votes' => 1], $charge, 'Premier vote');

        $this->client->xmlHttpRequest('POST', '/suggestions/'.$idees.'/vote', ['_token' => $jetonVote]);
        $charge = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertSame(['ok' => true, 'vote' => false, 'votes' => 0], $charge, 'Retrait du vote');

        // 6. L'ecran d'administration liste la remontee et sait la traiter.
        $crawler = $this->client->request('GET', '/admin/suggestions');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET /admin/suggestions');
        self::assertStringContainsString('Smoke test : un raccourci vers le dossier client.', $this->client->getResponse()->getContent() ?: '');

        $jetonStatut = $crawler->filter('form[action="/admin/suggestions/'.$idees.'/statut"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/suggestions/'.$idees.'/statut', [
            '_token' => $jetonStatut,
            'statut' => 'faite',
            'reponse' => 'Livre, merci.',
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'POST statut');

        // 7. L'auteur a bien recu la notification (boucle de retour).
        $notif = $this->em->getConnection()->fetchAssociative(
            'SELECT titre, message FROM shared.notification WHERE destinataire_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $admin->getId()],
        );
        self::assertIsArray($notif);
        self::assertSame('Votre idée : Faite', $notif['titre']);
        self::assertSame('Livre, merci.', $notif['message']);

        // 8. Une anomalie ne va PAS sur le mur.
        $crawler = $this->client->request('GET', '/suggestions/panneau');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->xmlHttpRequest('POST', '/suggestions', [
            '_token' => $token,
            'type' => 'anomalie',
            'message' => 'Smoke test : ce bouton ne repond pas.',
            'url' => '/recouvrement',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/suggestions');
        self::assertStringNotContainsString(
            'ce bouton ne repond pas',
            $this->client->getResponse()->getContent() ?: '',
            'Une anomalie ne doit jamais apparaitre sur le mur public',
        );
    }

    /**
     * DDL de Version20260901190000, joue dans la transaction du test.
     */
    private function creerLesTables(): void
    {
        $connexion = $this->em->getConnection();

        $connexion->executeStatement(<<<'SQL'
            CREATE TABLE shared.suggestion (
                id BIGSERIAL PRIMARY KEY,
                auteur_id BIGINT DEFAULT NULL REFERENCES shared.users(id) ON DELETE SET NULL,
                auteur_nom VARCHAR(180) NOT NULL,
                type VARCHAR(20) NOT NULL,
                statut VARCHAR(20) NOT NULL DEFAULT 'nouvelle',
                message TEXT NOT NULL,
                module VARCHAR(20) DEFAULT NULL,
                route VARCHAR(255) DEFAULT NULL,
                url VARCHAR(1024) DEFAULT NULL,
                nb_votes INT NOT NULL DEFAULT 0,
                reponse TEXT DEFAULT NULL,
                traite_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                traite_par VARCHAR(180) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $connexion->executeStatement('CREATE INDEX idx_suggestion_statut ON shared.suggestion (statut, created_at)');
        $connexion->executeStatement('CREATE INDEX idx_suggestion_auteur ON shared.suggestion (auteur_id, created_at)');
        $connexion->executeStatement('CREATE INDEX idx_suggestion_mur ON shared.suggestion (type, nb_votes)');

        $connexion->executeStatement(<<<'SQL'
            CREATE TABLE shared.suggestion_vote (
                id BIGSERIAL PRIMARY KEY,
                suggestion_id BIGINT NOT NULL REFERENCES shared.suggestion(id) ON DELETE CASCADE,
                votant_id BIGINT NOT NULL REFERENCES shared.users(id) ON DELETE CASCADE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_suggestion_vote UNIQUE (suggestion_id, votant_id)
            )
        SQL);
        $connexion->executeStatement('CREATE INDEX idx_suggestion_vote_votant ON shared.suggestion_vote (votant_id, suggestion_id)');
    }
}
