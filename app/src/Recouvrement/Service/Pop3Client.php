<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use RuntimeException;

/**
 * Client POP3S minimal, pur PHP (aucune dependance a ext-imap).
 *
 * Concu pour la collecte des retours clients sur les serveurs ou le port IMAP
 * (993) est bloque mais le POP3S (995) ouvert. Ouvre une connexion TLS, s'auth
 * par USER/PASS (mot de passe d'application), et expose STAT / RETR / QUIT.
 *
 * Usage sequentiel (une connexion a la fois) : connecter() -> sIdentifier() ->
 * nombreMessages()/recuperer() -> fermer() (idempotent, appelable en finally).
 */
final class Pop3Client
{
    private const TIMEOUT_DEFAUT = 15;

    /** @var resource|null */
    private $flux;

    /**
     * Ouvre la connexion TLS et verifie le message d'accueil du serveur.
     */
    public function connecter(string $hote, int $port, int $timeout = self::TIMEOUT_DEFAUT): void
    {
        $errno = 0;
        $errstr = '';
        $cible = sprintf('tls://%s:%d', $hote, $port);

        $flux = @stream_socket_client($cible, $errno, $errstr, (float) $timeout, \STREAM_CLIENT_CONNECT);
        if (false === $flux) {
            throw new RuntimeException(sprintf('Connexion POP3S impossible vers %s : [%d] %s', $cible, $errno, '' !== $errstr ? $errstr : 'erreur inconnue'));
        }

        stream_set_timeout($flux, $timeout);
        $this->flux = $flux;

        // Message d'accueil : doit commencer par "+OK".
        $this->lireStatut();
    }

    /**
     * Authentifie la session (USER puis PASS). Le mot de passe est un mot de passe
     * d'application (Gmail impose OAuth ou app-password, pas le mdp du compte).
     */
    public function sIdentifier(string $utilisateur, string $motDePasse): void
    {
        $this->commande('USER '.$utilisateur);
        $this->commande('PASS '.$motDePasse);
    }

    /**
     * Nombre de messages disponibles (reponse STAT : "+OK <nb> <octets>").
     */
    public function nombreMessages(): int
    {
        $reponse = $this->commande('STAT');
        if (1 === preg_match('/^\+OK\s+(\d+)/', $reponse, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Taille en octets d'un message (reponse LIST : "+OK <num> <octets>").
     * Permet d'ignorer un message trop volumineux avant de le telecharger (anti-OOM).
     */
    public function tailleMessage(int $numero): int
    {
        $reponse = $this->commande('LIST '.$numero);
        if (1 === preg_match('/^\+OK\s+\d+\s+(\d+)/', $reponse, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Recupere le message brut complet (RFC 822 : en-tetes + corps) via RETR.
     * Gere le byte-stuffing POP3 (ligne debutant par "." doublee) et le
     * terminateur (ligne contenant seulement ".").
     */
    public function recuperer(int $numero): string
    {
        $this->ecrire('RETR '.$numero);
        $statut = $this->lireLigne();
        if (!str_starts_with($statut, '+OK')) {
            throw new RuntimeException(sprintf('RETR %d refuse : %s', $numero, trim($statut)));
        }

        $lignes = [];
        while (true) {
            $ligne = $this->lireLigne();

            // Terminateur : une ligne "." seule.
            if ('.' === rtrim($ligne, "\r\n")) {
                break;
            }

            // Un-stuffing : une ligne debutant par ".." a un "." en trop.
            if (str_starts_with($ligne, '..')) {
                $ligne = substr($ligne, 1);
            }

            $lignes[] = $ligne;
        }

        return implode('', $lignes);
    }

    /**
     * Ferme proprement la session (QUIT) puis le flux. Idempotent.
     */
    public function fermer(): void
    {
        $flux = $this->flux;
        if (null === $flux) {
            return;
        }

        try {
            $this->ecrire('QUIT');
            $this->lireLigne();
        } catch (RuntimeException) {
            // Fermeture best-effort : une erreur sur QUIT est sans consequence.
        }

        fclose($flux);
        $this->flux = null;
    }

    /**
     * Envoie une commande et retourne sa ligne de statut, en exigeant un "+OK".
     */
    private function commande(string $commande): string
    {
        $this->ecrire($commande);

        return $this->lireStatut();
    }

    /**
     * Lit une ligne de statut mono-ligne et exige qu'elle commence par "+OK".
     */
    private function lireStatut(): string
    {
        $ligne = trim($this->lireLigne());
        if (!str_starts_with($ligne, '+OK')) {
            throw new RuntimeException('Reponse POP3 negative : '.('' !== $ligne ? $ligne : '(vide)'));
        }

        return $ligne;
    }

    private function ecrire(string $commande): void
    {
        $flux = $this->fluxOuvert();
        if (false === @fwrite($flux, $commande."\r\n")) {
            throw new RuntimeException('Ecriture POP3 impossible.');
        }
    }

    /**
     * Lit une ligne brute complete (terminee par \n, avec son \r\n final).
     *
     * Detecte le timeout MEME lorsque fgets renvoie un fragment partiel : sur un
     * socket bloquant, un timeout en pleine ligne fait renvoyer a fgets la portion
     * deja bufferisee (une chaine NON-false). Sans ce controle, une ligne tronquee
     * serait prise pour complete (ex. un faux terminateur ".") et desynchroniserait
     * le flux. Une ligne n'est donc consideree complete que si elle finit par \n.
     */
    private function lireLigne(): string
    {
        $flux = $this->fluxOuvert();
        $ligne = '';

        while (true) {
            $morceau = fgets($flux);

            if (false === $morceau) {
                $meta = stream_get_meta_data($flux);
                if (true === $meta['timed_out']) {
                    throw new RuntimeException('Timeout de lecture POP3.');
                }
                if ('' !== $ligne) {
                    throw new RuntimeException('Lecture POP3 incomplete (connexion fermee ?).');
                }

                throw new RuntimeException('Lecture POP3 impossible (connexion fermee ?).');
            }

            $ligne .= $morceau;

            // Ligne complete seulement si terminee par \n.
            if (str_ends_with($ligne, "\n")) {
                return $ligne;
            }

            // Fragment sans \n : verifier un timeout / EOF mi-ligne (fgets a rendu
            // une portion non-false). Sinon simple fragmentation reseau -> on reboucle.
            $meta = stream_get_meta_data($flux);
            if (true === $meta['timed_out']) {
                throw new RuntimeException('Timeout de lecture POP3 (ligne partielle).');
            }
            if (true === $meta['eof']) {
                throw new RuntimeException('Lecture POP3 incomplete (EOF sans fin de ligne).');
            }
        }
    }

    /**
     * @return resource
     */
    private function fluxOuvert()
    {
        if (null === $this->flux) {
            throw new RuntimeException('Connexion POP3 non ouverte.');
        }

        return $this->flux;
    }
}
