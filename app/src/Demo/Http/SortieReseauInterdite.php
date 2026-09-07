<?php

declare(strict_types=1);

namespace App\Demo\Http;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/** Levee des qu'un service tente de joindre l'exterieur depuis la demonstration. */
final class SortieReseauInterdite extends RuntimeException implements TransportExceptionInterface
{
}
