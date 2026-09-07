<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Entity\User;
use App\Shared\Repository\LegalAcceptanceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose a Twig les documents legaux que l'utilisateur courant doit encore
 * accepter (pour declencher la modale de consentement). Resultat memoize par
 * requete. Voir docs/SECURITY.md.
 */
final class LegalExtension extends AbstractExtension
{
    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Security $security,
        private readonly LegalAcceptanceRepository $acceptances,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('legal_manquants', $this->manquants(...)),
        ];
    }

    /**
     * @return list<string>
     */
    public function manquants(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $user = $this->security->getUser();
        $this->cache = $user instanceof User ? $this->acceptances->documentsManquants($user) : [];

        return $this->cache;
    }
}
