<?php

declare(strict_types=1);

namespace App\Creances\Command;

use App\Shared\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Throwable;

/**
 * Commande de smoke-test : parcourt toutes les routes du module
 * Recouvrement (GET sans parametres) avec un user authentifie en memoire,
 * et reporte les codes HTTP + exceptions. Utile en dev pour valider que
 * tous les templates compilent et que les controleurs ne crashent pas.
 */
#[AsCommand(
    name: 'app:creances:smoke-test',
    description: 'Parcourt toutes les routes GET du module Recouvrement avec un user authentifie en memoire',
)]
final class SmokeTestCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly HttpKernelInterface $kernel,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Charge ou cree un user en memoire pour authentifier le firewall.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'expert-comptable@demonstration.invalid']);
        if (null === $user) {
            $io->error('Utilisateur expert-comptable@demonstration.invalid introuvable. Lance d\'abord app:user:promote.');

            return Command::FAILURE;
        }

        // Authentifie le user dans le token storage pour cette session.
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        $routes = [];
        foreach ($this->router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'app_creances_')) {
                continue;
            }
            $methods = $route->getMethods();
            if ([] !== $methods && !in_array('GET', $methods, true)) {
                continue;
            }
            // On saute les routes a parametres dynamiques sans valeur evidente.
            $url = (string) $route->getPath();
            $url = (string) preg_replace('#\{code\}#', '394074', $url);
            $url = (string) preg_replace('#\{numero\}#', '71900001', $url);
            $url = (string) preg_replace('#\{id\}#', '1', $url);
            $url = (string) preg_replace('#\{niveauId\}#', '1', $url);
            $url = (string) preg_replace('#\{lienId\}#', '1', $url);
            if (str_contains($url, '{')) {
                continue;
            }
            $routes[$name] = $url;
        }

        // Cas de filtres a tester explicitement (non couverts par les routes
        // sans parametres). Reproduit la regression 22P02 sur les filtres
        // ANY(array) — chaque URL doit repondre 200 et non 500.
        $routes['app_creances_index[categories]'] = '/creances?categories_client%5B0%5D=PART';
        $routes['app_creances_index[services]'] = '/creances?services%5B0%5D=VN';
        $routes['app_creances_index[multi]'] = '/creances?categories_client%5B0%5D=PRO&services%5B0%5D=VO&types_compte%5B0%5D=COMPTANT';
        $routes['app_creances_pilotage[etab]'] = '/creances/pilotage?etablissement%5B0%5D=ILK';
        $routes['app_creances_index[vue=client]'] = '/creances?vue=client';
        $routes['app_creances_index[vue=client+tri]'] = '/creances?vue=client&tri=retard&sens=desc';
        $routes['app_creances_responsable_detail[etab]'] = '/creances/analyses/responsable/ZXRhYmxpc3NlbWVudHxJTEs/detail';

        $io->title(sprintf('Smoke test de %d routes', count($routes)));

        $ok = 0;
        $erreurs = 0;
        $rows = [];
        foreach ($routes as $name => $url) {
            $request = Request::create($url, 'GET');
            // Session memoire pour eviter "no session" sur csrf_token().
            $session = new Session(new MockArraySessionStorage());
            $request->setSession($session);
            try {
                $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST, false);
                $code = $response->getStatusCode();
                $statut = match (true) {
                    $code >= 500 => '<error>'.$code.'</error>',
                    $code >= 400 => '<comment>'.$code.'</comment>',
                    $code >= 300 => '<info>'.$code.'</info>',
                    default => '<info>'.$code.'</info>',
                };
                $rows[] = [$statut, $name, $url];
                if ($code >= 500) {
                    ++$erreurs;
                } else {
                    ++$ok;
                }
            } catch (Throwable $e) {
                ++$erreurs;
                $rows[] = ['<error>EXC</error>', $name, $url.' — '.substr($e->getMessage(), 0, 90)];
            }
        }

        $io->table(['Status', 'Route', 'Path'], $rows);
        $io->writeln(sprintf('<info>%d OK</info> · <error>%d erreurs</error>', $ok, $erreurs));

        return $erreurs > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
