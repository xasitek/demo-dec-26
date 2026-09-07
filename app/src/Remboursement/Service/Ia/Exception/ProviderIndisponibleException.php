<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia\Exception;

use RuntimeException;

/** Erreur technique/transitoire du provider (429, 5xx, timeout, cle absente) : retryable. */
final class ProviderIndisponibleException extends RuntimeException
{
}
