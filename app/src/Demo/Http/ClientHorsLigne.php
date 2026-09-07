<?php

declare(strict_types=1);

namespace App\Demo\Http;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Le client HTTP de la demonstration : il ne sort pas.
 *
 * La copie embarque les clients d'origine -- Sheets, OCR, API partenaires --
 * parce qu'ils font partie du code etudie. Mais un environnement de
 * demonstration qui garderait la faculte d'appeler un systeme du groupe
 * reposerait sur une configuration vide, donc sur un oubli possible.
 *
 * Ce decorateur ferme la porte au niveau du transport, une fois pour toutes :
 * quel que soit le service appelant, quelle que soit la cle presente ou absente
 * dans l'environnement, aucune requete ne part de cette machine. Un appel leve,
 * et le message dit pourquoi.
 *
 * C'est la contrepartie technique de la regle posee par l'auteur : si les
 * depots, l'entrepot et les applications du groupe devenaient inaccessibles, la
 * suite jury doit continuer a fonctionner integralement.
 *
 * La decoration est portee par l'attribut, pas par un fichier de configuration :
 * une definition posee dans config/packages/ serait ecrasee silencieusement par
 * le chargement automatique de src/ dans config/services.yaml, et la porte
 * resterait ouverte sans que rien ne le signale. La priorite haute place ce
 * decorateur en dehors de tous les autres.
 */
#[AsDecorator(decorates: 'http_client', priority: 255)]
final class ClientHorsLigne implements HttpClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        throw new SortieReseauInterdite(sprintf('Sortie réseau refusée par la démonstration : %s %s. Cet environnement est autonome, il ne joint aucun système extérieur.', $method, $url));
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new SortieReseauInterdite('Sortie réseau refusée par la démonstration.');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
