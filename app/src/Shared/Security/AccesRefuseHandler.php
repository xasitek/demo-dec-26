<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * Page conviviale quand un utilisateur (deja connecte) tente d'acceder a un
 * module auquel il n'est pas rattache : plutot qu'un 403 brut, on affiche une
 * page l'invitant a se rapprocher de son chef de pole.
 */
final class AccesRefuseHandler implements AccessDeniedHandlerInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new Response(
            $this->twig->render('security/acces_refuse.html.twig'),
            Response::HTTP_FORBIDDEN,
        );
    }
}
