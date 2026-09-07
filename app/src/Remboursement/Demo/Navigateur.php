<?php

declare(strict_types=1);

namespace App\Remboursement\Demo;

use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Un navigateur de demonstration : il joue les gestes d'un utilisateur.
 *
 * Toutes les requetes traversent le noyau HTTP complet -- routage, pare-feu,
 * roles, CSRF, controleurs, services, garde-fous du workflow. Rien n'est
 * court-circuite : ce qui est joue ici est ce qu'un utilisateur ferait.
 *
 * La seule chose franchie sans mot de passe reel est la PORTE D'ACCES de la
 * demonstration, un portail de courtoisie devant la plateforme, qui n'existe
 * pas en production et ne protege aucune donnee. Le mot de passe lui est
 * fourni au lancement, comme le ferait un lecteur.
 */
final class Navigateur
{
    private HttpKernelBrowser $browser;

    public function __construct(
        private readonly HttpKernelInterface $noyau,
        private readonly string $identifiant,
        private readonly string $motDePasse,
    ) {
        $this->browser = new HttpKernelBrowser($this->noyau, ['HTTP_HOST' => 'localhost']);
        $this->browser->followRedirects(false);
    }

    /** Franchit la porte d'acces. Rend false si elle refuse. */
    public function ouvrir(): bool
    {
        $this->browser->request('GET', '/acces');
        $this->browser->request('POST', '/acces', [
            'identifiant' => $this->identifiant,
            'mot_de_passe' => $this->motDePasse,
        ]);

        return 302 === $this->code();
    }

    /**
     * Prend un poste, par la vraie route de la demonstration.
     *
     * Les redirections sont suivies jusqu'a trois sauts : la prise de poste
     * redirige vers l'ecran demande, et cet ecran peut lui-meme rediriger --
     * le confinement de la secretaire, par exemple, ramene a son espace. Ne
     * suivre qu'un saut donnerait une page de redirection sans contenu, et
     * ferait croire a un formulaire absent.
     */
    public function poste(string $persona, string $vers): Crawler
    {
        $this->browser->request('GET', '/demo/voir-comme/'.$persona.'?vers='.rawurlencode($vers));
        for ($i = 0; $i < 3 && 302 === $this->code(); ++$i) {
            $this->browser->followRedirect();
        }

        return $this->browser->getCrawler();
    }

    /**
     * Va sur une page, en suivant les redirections.
     *
     * A utiliser pour toute navigation APRES la prise de poste : reprendre un
     * poste deja pris ferait passer la requete `voir-comme` par le confinement
     * de la secretaire, qui la renverrait a son espace. Un poste, un navigateur.
     */
    public function aller(string $uri): Crawler
    {
        $this->browser->request('GET', $uri);
        for ($i = 0; $i < 3 && 302 === $this->code(); ++$i) {
            $this->browser->followRedirect();
        }

        return $this->browser->getCrawler();
    }

    /** L'URI de la page ou l'on se trouve reellement. */
    public function ou(): string
    {
        return $this->browser->getInternalRequest()->getUri();
    }

    /**
     * @param array<string, mixed>  $parametres
     * @param array<string, mixed>  $fichiers
     * @param array<string, string> $entetes
     */
    public function requete(string $methode, string $uri, array $parametres = [], array $fichiers = [], array $entetes = []): Crawler
    {
        return $this->browser->request($methode, $uri, $parametres, $fichiers, $entetes);
    }

    /**
     * Une requete XHR : le module repond alors en JSON.
     *
     * @param array<string, mixed> $parametres
     * @param array<string, mixed> $fichiers
     */
    public function xhr(string $methode, string $uri, array $parametres = [], array $fichiers = []): mixed
    {
        $this->browser->request($methode, $uri, $parametres, $fichiers,
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode($this->contenu(), true);
    }

    public function crawler(): Crawler
    {
        return $this->browser->getCrawler();
    }

    public function suivre(): Crawler
    {
        return $this->browser->followRedirect();
    }

    public function code(): int
    {
        return $this->browser->getInternalResponse()->getStatusCode();
    }

    public function contenu(): string
    {
        return $this->browser->getInternalResponse()->getContent();
    }

    /** Le statut lisible d'une reponse, redirection comprise. */
    public function statut(): string
    {
        $r = $this->browser->getInternalResponse();
        $vers = $r->getHeader('location');
        $vers = \is_array($vers) ? implode(', ', $vers) : (string) $vers;

        return '' !== $vers
            ? sprintf('HTTP %d → %s', $r->getStatusCode(), $vers)
            : sprintf('HTTP %d', $r->getStatusCode());
    }

    /**
     * Soumet un formulaire de la page, tel qu'il est, en suivant la redirection.
     *
     * On part du formulaire RENDU : ses champs, ses valeurs, son jeton. Une
     * epreuve qui reconstruirait la requete a la main pourrait oublier un champ
     * que le module attend, et prouverait alors autre chose que ce qu'on croit.
     */
    public function soumettre(Form $formulaire): Crawler
    {
        $this->browser->submit($formulaire);
        for ($i = 0; $i < 3 && 302 === $this->code(); ++$i) {
            $this->browser->followRedirect();
        }

        return $this->browser->getCrawler();
    }

    /** Le jeton CSRF porte par CE formulaire, et pas par un autre de la page. */
    public function jeton(Crawler $formulaire): string
    {
        $champ = $formulaire->filter('input[name="_token"]');
        if (0 === $champ->count()) {
            throw new RuntimeException('Ce formulaire ne porte pas de jeton CSRF.');
        }

        return (string) $champ->first()->attr('value');
    }

    /** Le texte visible d'une page, sans balises, pour y chercher un libelle. */
    public function texteVisible(): string
    {
        $texte = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $this->contenu());
        $texte = strip_tags((string) $texte);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $texte)));
    }
}
