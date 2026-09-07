<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Enum\Module;
use App\Shared\Secretaire\RegistreEspaces;
use App\Shared\Security\GoogleAuthenticator;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    /**
     * Page d'accueil = page de connexion. Si deja connecte, on bascule vers
     * l'espace applicatif.
     */
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        // DÉMONSTRATION : la racine ouvre le portail des dix outils. L'examinateur
        // n'arrive jamais sur un écran de connexion, puisqu'il n'y a rien à
        // authentifier : les postes sont des profils préparés.
        return $this->redirectToRoute('demo_portail');
    }

    /**
     * Demarre le flux OAuth Google (redirige vers l'ecran de consentement).
     */
    #[Route('/oauth/google/connect', name: 'oauth_google_connect')]
    public function connect(Request $request, ClientRegistry $clientRegistry, RegistreEspaces $registre): RedirectResponse
    {
        // Retour apres connexion vers une page interne demandee (ex. depot public
        // remboursement) : on memorise la cible comme target_path lu par
        // GoogleAuthenticator::onAuthenticationSuccess. Chemins internes seulement
        // (commence par "/" mais pas "//") pour eviter toute redirection ouverte.
        $redirect = (string) $request->query->get('redirect', '');
        if (str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            $request->getSession()->set('_security.main.target_path', $redirect);
        }

        // Inscription secretaire depuis le depot public remboursement : provisionnement
        // automatique en ROLE_SECRETAIRE a la connexion (cf. GoogleAuthenticator).
        $secretaire = $request->query->getBoolean('secretaire');
        if ($secretaire) {
            $request->getSession()->set('remb_secretaire_signup', true);

            // De QUEL espace vient-elle ? Le module a lui accorder s'en deduit : la
            // cle d'un espace est la valeur du module qui l'offre. Aucun couple
            // ecrit en dur — une secretaire qui arrive par Livraison recevra
            // Livraison, et un espace ajoute demain sera couvert d'office.
            //
            // Seuls les modules OFFERTS PAR UN ESPACE sont attribuables : un lien
            // forge vers /garanties ne donne donc rien.
            $module = null;
            foreach ($registre->espaces() as $espace) {
                $candidat = Module::depuisValeur($espace->cle);
                if (null !== $candidat && str_starts_with($redirect, $candidat->prefixe())) {
                    $module = $candidat;
                    break;
                }
            }
            $request->getSession()->set('secretaire_signup_module', $module?->value);
        }

        // Parametre `hd` = filtre du selecteur de compte Google (confort, pas securite ;
        // la liste blanche serveur est dans GoogleAuthenticator). Connexion normale :
        // on garde le domaine d'entreprise. Depot remboursement : `*` (tout compte
        // Google Workspace) car les secretaires sont aussi sur partenaire-a.invalid /
        // groupe-demonstration.invalid / partenaire-b.invalid — cf. GoogleAuthenticator::DOMAINES_SECRETAIRE.
        $options = ['hd' => $secretaire ? '*' : GoogleAuthenticator::DOMAINE_PRINCIPAL];

        return $clientRegistry->getClient('google')->redirect(['openid', 'email', 'profile'], $options);
    }

    /**
     * Cible de la connexion en POPUP (depot remboursement) : notifie la fenetre
     * parente que la connexion a reussi puis se ferme, sans recharger la page appelante.
     */
    #[Route('/oauth/popup-ok', name: 'app_oauth_popup_ok', methods: ['GET'])]
    public function popupOk(): Response
    {
        return new Response(<<<'HTML'
            <!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Connexion</title></head>
            <body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center;color:#2D3250">
            Connexion etablie. Vous pouvez fermer cette fenetre.
            <script>
                // Signal principal : BroadcastChannel (same-origin, survit a la coupure
                // popup<->opener imposee par les navigateurs quand le popup a transite par
                // Google, cf. COOP). Reculs : postMessage direct + fermeture du popup.
                try { var c = new BroadcastChannel('remb-auth'); c.postMessage('ok'); c.close(); } catch (e) {}
                try { if (window.opener) { window.opener.postMessage({ type: 'remb-auth-ok' }, window.location.origin); } } catch (e) {}
                window.close();
            </script>
            </body></html>
            HTML);
    }

    /**
     * Callback Google. La requete est interceptee par GoogleAuthenticator ;
     * ce corps ne sert que de point d'ancrage de route.
     */
    #[Route('/oauth/google/check', name: 'oauth_google_check')]
    public function check(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Interceptee par la cle logout du firewall de securite.
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new LogicException('Cette methode est interceptee par le firewall (cle logout).');
    }
}
